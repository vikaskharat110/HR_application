"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/lib/session";
import { recordAudit, notify } from "@/lib/workflow";
import type { Role } from "@prisma/client";

const ASSET_MANAGER_ROLES: Role[] = ["COMPANY_ADMIN", "HR", "IT_ADMIN"];

export async function createAsset(formData: FormData) {
  const session = await requireRole(ASSET_MANAGER_ROLES);
  if (!session.companyId) throw new Error("No company context");

  const asset = await prisma.asset.create({
    data: {
      companyId: session.companyId,
      name: String(formData.get("name")),
      category: String(formData.get("category")),
      serialNumber: String(formData.get("serialNumber")),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Asset", entityId: asset.id });
  revalidatePath("/dashboard/assets");
}

export async function assignAsset(formData: FormData) {
  const session = await requireRole(ASSET_MANAGER_ROLES);
  const assetId = String(formData.get("assetId"));
  const employeeId = String(formData.get("employeeId"));

  const [asset, employee] = await Promise.all([
    prisma.asset.findFirst({ where: { id: assetId, companyId: session.companyId ?? undefined } }),
    prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId ?? undefined }, include: { user: true } }),
  ]);
  if (!asset || !employee) throw new Error("Asset or employee not found.");

  await prisma.$transaction([
    prisma.assetAssignment.create({ data: { assetId, employeeId } }),
    prisma.asset.update({ where: { id: assetId }, data: { status: "ASSIGNED" } }),
  ]);

  if (employee.user) {
    await notify({ userId: employee.user.id, title: "Asset assigned", message: "A new asset has been assigned to you.", link: "/dashboard/assets" });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "ASSIGN", entityType: "Asset", entityId: assetId });
  revalidatePath("/dashboard/assets");
}

export async function returnAsset(assignmentId: string, assetId: string) {
  const session = await requireRole(ASSET_MANAGER_ROLES);

  const asset = await prisma.asset.findFirst({ where: { id: assetId, companyId: session.companyId ?? undefined } });
  const assignment = await prisma.assetAssignment.findFirst({ where: { id: assignmentId, assetId } });
  if (!asset || !assignment) throw new Error("Asset assignment not found.");

  await prisma.$transaction([
    prisma.assetAssignment.update({ where: { id: assignmentId }, data: { returnedDate: new Date() } }),
    prisma.asset.update({ where: { id: assetId }, data: { status: "AVAILABLE" } }),
  ]);

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "RETURN", entityType: "Asset", entityId: assetId });
  revalidatePath("/dashboard/assets");
}
