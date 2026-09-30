"use server";

import { unlink } from "node:fs/promises";
import path from "node:path";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { recordAudit } from "@/lib/workflow";
import { generateLetterForEmployee } from "@/lib/letters";
import { saveUploadedFile, MAX_UPLOAD_BYTES } from "@/lib/upload";
import type { DocumentType, LetterType } from "@prisma/client";

/**
 * Same rule everywhere a document is managed: Company Admin/HR can touch any
 * document; a company-wide one (no employeeId) stops there — only Admin/HR
 * can edit or delete it. An employee-specific document additionally opens up
 * to the employee it belongs to (not their manager — matches who can upload
 * it in the first place via `uploadOwnDocument`).
 */
async function assertCanManageDocument(documentId: string) {
  const session = await requireSession();
  const doc = await prisma.document.findFirst({ where: { id: documentId, companyId: session.companyId ?? undefined } });
  if (!doc) throw new Error("Document not found.");

  const isAdminOrHR = session.role === "COMPANY_ADMIN" || session.role === "HR";
  const isOwner = doc.employeeId !== null && doc.employeeId === session.employeeId;
  if (!isAdminOrHR && !isOwner) {
    throw new Error("You are not authorized to manage this document.");
  }
  return { session, doc, isAdminOrHR };
}

export type UploadDocumentState = { error?: string; success?: boolean };

export async function uploadDocument(_prev: UploadDocumentState, formData: FormData): Promise<UploadDocumentState> {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const file = formData.get("file") as File | null;
  if (file && file.size > MAX_UPLOAD_BYTES) {
    return { error: "That file is larger than 10MB — pick a smaller one." };
  }

  const name = String(formData.get("name") || file?.name || "Untitled document");
  const type = String(formData.get("type") || "OTHER") as DocumentType;
  const employeeId = String(formData.get("employeeId") || "") || null;
  const expiryDate = String(formData.get("expiryDate") || "");

  if (employeeId) {
    const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
    if (!employee) return { error: "Employee not found." };
  }

  const fileUrl = await saveUploadedFile(session.companyId, file);

  const doc = await prisma.document.create({
    data: {
      companyId: session.companyId,
      employeeId,
      name,
      type,
      fileUrl,
      expiryDate: expiryDate ? new Date(expiryDate) : null,
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Document", entityId: doc.id });
  revalidatePath("/dashboard/documents");
  return { success: true };
}

/**
 * Self-service upload for any employee (BRD FR-070 — ESS document access includes
 * the employee's own documents). Deliberately narrower than `uploadDocument`: the
 * employee can only attach to their own record, never company-wide or another
 * employee, and only to the document types that make sense for a self-upload
 * (ID proof / certificate) — offer letters, contracts, and policies stay HR-issued.
 */
const SELF_UPLOAD_TYPES: DocumentType[] = ["ID_PROOF", "CERTIFICATE", "OTHER"];

export async function uploadOwnDocument(_prev: UploadDocumentState, formData: FormData): Promise<UploadDocumentState> {
  const session = await requireSession();
  if (!session.employeeId || !session.companyId) throw new Error("No employee record linked to this account");

  const file = formData.get("file") as File | null;
  if (file && file.size > MAX_UPLOAD_BYTES) {
    return { error: "That file is larger than 10MB — pick a smaller one." };
  }

  const name = String(formData.get("name") || file?.name || "Untitled document");
  const requestedType = String(formData.get("type") || "OTHER") as DocumentType;
  const type = SELF_UPLOAD_TYPES.includes(requestedType) ? requestedType : "OTHER";

  const fileUrl = await saveUploadedFile(session.companyId, file);

  const doc = await prisma.document.create({
    data: { companyId: session.companyId, employeeId: session.employeeId, name, type, fileUrl },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "Document",
    entityId: doc.id,
    meta: { selfUploaded: true },
  });
  revalidatePath("/dashboard/documents");
  return { success: true };
}

export type GenerateLetterState = { error?: string; success?: boolean };

export async function generateLetter(_prev: GenerateLetterState, formData: FormData): Promise<GenerateLetterState> {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const employeeId = String(formData.get("employeeId"));
  const type = String(formData.get("type")) as LetterType;

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
  if (!employee) return { error: "Employee not found." };

  const doc = await generateLetterForEmployee(session.companyId, employeeId, type);
  if (!doc) return { error: 'No template configured for this letter type yet — add one under "Edit letter templates" below.' };

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "GENERATE_LETTER", entityType: "Document", entityId: doc.id, meta: { type, employeeId } });
  revalidatePath("/dashboard/documents");
  revalidatePath(`/dashboard/employees/${employeeId}`);
  return { success: true };
}

export type UpdateDocumentState = { error?: string; success?: boolean };

export async function updateDocument(_prev: UpdateDocumentState, formData: FormData): Promise<UpdateDocumentState> {
  const documentId = String(formData.get("documentId"));
  const { session, doc, isAdminOrHR } = await assertCanManageDocument(documentId);

  const name = String(formData.get("name") || "").trim() || doc.name;
  const requestedType = String(formData.get("type") || doc.type) as DocumentType;
  const type = isAdminOrHR || SELF_UPLOAD_TYPES.includes(requestedType) ? requestedType : doc.type;
  const expiryDateRaw = String(formData.get("expiryDate") || "");
  const file = formData.get("file") as File | null;

  if (file && file.size > MAX_UPLOAD_BYTES) {
    return { error: "That file is larger than 10MB — pick a smaller one." };
  }

  const newFileUrl = file && file.size > 0 ? await saveUploadedFile(doc.companyId, file) : null;
  if (newFileUrl && doc.fileUrl.startsWith("/uploads/")) {
    await unlink(path.join(process.cwd(), "public", doc.fileUrl)).catch(() => {});
  }

  await prisma.document.update({
    where: { id: documentId },
    data: {
      name,
      type,
      expiryDate: expiryDateRaw ? new Date(expiryDateRaw) : null,
      ...(newFileUrl ? { fileUrl: newFileUrl } : {}),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "UPDATE", entityType: "Document", entityId: documentId });
  revalidatePath("/dashboard/documents");
  if (doc.employeeId) revalidatePath(`/dashboard/documents/${doc.employeeId}`);
  return { success: true };
}

export async function deleteDocument(formData: FormData) {
  const documentId = String(formData.get("documentId"));
  const { session, doc } = await assertCanManageDocument(documentId);

  await prisma.document.delete({ where: { id: documentId } });
  if (doc.fileUrl.startsWith("/uploads/")) {
    await unlink(path.join(process.cwd(), "public", doc.fileUrl)).catch(() => {});
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "DELETE", entityType: "Document", entityId: documentId, meta: { name: doc.name } });
  revalidatePath("/dashboard/documents");
  if (doc.employeeId) revalidatePath(`/dashboard/documents/${doc.employeeId}`);
}

