"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, ASSIGNABLE_ROLES } from "@/lib/session";
import { hashPassword } from "@/lib/auth";
import { recordAudit, notify } from "@/lib/workflow";
import { getStatutoryConfig } from "@/lib/statutory";
import { saveUploadedFile, MAX_UPLOAD_BYTES } from "@/lib/upload";
import type { Role, LetterType, OnboardingTaskCategory } from "@prisma/client";

export type UpdateLetterheadState = { error?: string; success?: boolean };

/**
 * Company identity shown on every generated letter (offer/appointment/etc.)
 * — logo, address, phone, PAN — kept on the Company row itself since it's
 * the company's own identity, not a leave/attendance policy setting like the
 * rest of CompanyPolicy.
 */
export async function updateCompanyLetterhead(_prev: UpdateLetterheadState, formData: FormData): Promise<UpdateLetterheadState> {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) return { error: "No company context." };

  const logo = formData.get("logo") as File | null;
  if (logo && logo.size > MAX_UPLOAD_BYTES) {
    return { error: "That logo is larger than 10MB — pick a smaller one." };
  }

  const address = String(formData.get("address") || "").trim();
  const phone = String(formData.get("phone") || "").trim();
  const panNumber = String(formData.get("panNumber") || "").trim();

  const logoUrl = logo && logo.size > 0 ? await saveUploadedFile(session.companyId, logo) : undefined;

  await prisma.company.update({
    where: { id: session.companyId },
    data: {
      address: address || null,
      phone: phone || null,
      panNumber: panNumber || null,
      ...(logoUrl ? { logoUrl } : {}),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CONFIGURE", entityType: "Company", entityId: session.companyId, meta: { letterhead: true } });
  revalidatePath("/dashboard/settings");
  return { success: true };
}

export async function updatePolicy(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");

  const data = {
    workWeek: String(formData.get("workWeek")),
    checkInGraceMins: Number(formData.get("checkInGraceMins")),
    casualLeaveDays: Number(formData.get("casualLeaveDays")),
    sickLeaveDays: Number(formData.get("sickLeaveDays")),
    earnedLeaveDays: Number(formData.get("earnedLeaveDays")),
    probationMonths: Number(formData.get("probationMonths")),
    noticePeriodDays: Number(formData.get("noticePeriodDays")),
    geofenceAttendanceEnabled: formData.get("geofenceAttendanceEnabled") === "on",
  };

  await prisma.companyPolicy.upsert({
    where: { companyId: session.companyId },
    create: { companyId: session.companyId, ...data },
    update: data,
  });

  revalidatePath("/dashboard/settings");
}

export async function addBranch(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");
  await prisma.branch.create({
    data: { companyId: session.companyId, name: String(formData.get("name")), city: String(formData.get("city")) },
  });
  revalidatePath("/dashboard/settings");
}

export type SetBranchLocationState = { error?: string; success?: boolean };

/**
 * Sets or clears the geofence for a branch — the coordinates employees at
 * that branch must be within (default 50m) to check in/out. The admin's
 * browser supplies these via its own Geolocation API (the "Use my current
 * location" button on the Branches tab) so nobody has to hand-type lat/lng —
 * the hidden fields are just the transport, not something meant to be typed.
 */
export async function setBranchLocation(_prev: SetBranchLocationState, formData: FormData): Promise<SetBranchLocationState> {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) return { error: "No company context" };

  const branchId = String(formData.get("branchId"));
  const branch = await prisma.branch.findFirst({ where: { id: branchId, companyId: session.companyId } });
  if (!branch) return { error: "Branch not found." };

  const latRaw = String(formData.get("latitude") || "").trim();
  const lngRaw = String(formData.get("longitude") || "").trim();
  const radiusRaw = String(formData.get("radius") || "").trim();

  if (!latRaw || !lngRaw) {
    // Both blank clears the geofence — check-in goes back to unrestricted for this branch.
    await prisma.branch.update({ where: { id: branchId }, data: { latitude: null, longitude: null } });
    revalidatePath("/dashboard/settings");
    return { success: true };
  }

  const latitude = Number(latRaw);
  const longitude = Number(lngRaw);
  if (Number.isNaN(latitude) || latitude < -90 || latitude > 90) return { error: "That doesn't look like a valid location — use the \"Use my current location\" button instead of typing coordinates." };
  if (Number.isNaN(longitude) || longitude < -180 || longitude > 180) return { error: "That doesn't look like a valid location — use the \"Use my current location\" button instead of typing coordinates." };

  const radius = radiusRaw ? Math.max(1, Math.round(Number(radiusRaw))) : 50;
  if (Number.isNaN(radius)) return { error: "Radius must be a number." };

  await prisma.branch.update({ where: { id: branchId }, data: { latitude, longitude, geofenceRadiusMeters: radius } });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CONFIGURE", entityType: "Branch", entityId: branchId, meta: { latitude, longitude, radius } });
  revalidatePath("/dashboard/settings");
  return { success: true };
}

export async function addDepartment(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");
  await prisma.department.create({ data: { companyId: session.companyId, name: String(formData.get("name")) } });
  revalidatePath("/dashboard/settings");
}

export async function addLeaveType(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");
  await prisma.leaveType.create({
    data: {
      companyId: session.companyId,
      name: String(formData.get("name")),
      daysPerYear: Number(formData.get("daysPerYear")),
      carryForward: formData.get("carryForward") === "on",
    },
  });
  revalidatePath("/dashboard/settings");
}

export async function addHoliday(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");
  await prisma.holiday.create({
    data: { companyId: session.companyId, name: String(formData.get("name")), date: new Date(String(formData.get("date"))) },
  });
  revalidatePath("/dashboard/settings");
}

export async function inviteUser(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");

  const email = String(formData.get("email")).trim().toLowerCase();
  const roleRaw = String(formData.get("role"));

  // A custom role's option value is "custom:<id>" — resolve it to the base
  // role it inherits its business-logic permissions from, same as
  // createEmployee does.
  let role: Role;
  let customRoleId: string | null = null;
  if (roleRaw.startsWith("custom:")) {
    customRoleId = roleRaw.slice("custom:".length);
    const customRole = await prisma.customRole.findFirst({ where: { id: customRoleId, companyId: session.companyId } });
    if (!customRole) throw new Error("Custom role not found.");
    role = customRole.baseRole;
  } else {
    role = roleRaw as Role;
  }

  if (!ASSIGNABLE_ROLES.includes(role)) {
    throw new Error("That role can't be assigned from here.");
  }
  const passwordHash = await hashPassword("Welcome@123");

  const user = await prisma.user.create({ data: { email, role, customRoleId, passwordHash, companyId: session.companyId } });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "User", entityId: user.id });
  await notify({ userId: user.id, title: "Welcome to Verdant HR", message: "Your account was created. Temporary password: Welcome@123", link: "/dashboard" });

  revalidatePath("/dashboard/settings/users");
}

export async function updateStatutoryConfig(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "PAYROLL_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");

  const parseSlabs = (raw: string) => {
    try {
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) throw new Error();
      return parsed;
    } catch {
      throw new Error("Slab tables must be valid JSON arrays, e.g. [{\"upto\":15000,\"amount\":0}].");
    }
  };

  await getStatutoryConfig(session.companyId); // ensure a row exists to update
  await prisma.statutoryConfig.update({
    where: { companyId: session.companyId },
    data: {
      pfEmployeeRate: Number(formData.get("pfEmployeeRate")),
      pfEmployerRate: Number(formData.get("pfEmployerRate")),
      pfWageCeiling: Number(formData.get("pfWageCeiling")),
      esiEmployeeRate: Number(formData.get("esiEmployeeRate")),
      esiEmployerRate: Number(formData.get("esiEmployerRate")),
      esiWageCeiling: Number(formData.get("esiWageCeiling")),
      ptSlabs: parseSlabs(String(formData.get("ptSlabs"))),
      tdsSlabs: parseSlabs(String(formData.get("tdsSlabs"))),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CONFIGURE", entityType: "StatutoryConfig", entityId: session.companyId });
  revalidatePath("/dashboard/settings/statutory");
}

export type UpsertLetterTemplateState = { error?: string; success?: boolean };

export async function upsertLetterTemplate(_prev: UpsertLetterTemplateState, formData: FormData): Promise<UpsertLetterTemplateState> {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) return { error: "No company context" };

  const type = String(formData.get("type")) as LetterType;
  // Browsers normalize textarea newlines to CRLF when constructing form data —
  // strip the "\r" back out so the PDF renderer never sees it as a stray glyph.
  const subject = String(formData.get("subject")).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  const bodyHtml = String(formData.get("bodyHtml")).replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  const removeTemplateFile = formData.get("removeTemplateFile") === "1";
  const templateFile = formData.get("templateFile") as File | null;

  if (templateFile && templateFile.size > 0) {
    if (templateFile.size > MAX_UPLOAD_BYTES) {
      return { error: "That template file is larger than 10MB — pick a smaller one." };
    }
    if (!templateFile.name.toLowerCase().endsWith(".docx")) {
      return { error: "The uploaded template must be a Word .docx file." };
    }
  }

  const existing = await prisma.letterTemplate.findUnique({ where: { companyId_type: { companyId: session.companyId, type } } });

  let templateFileUrl = existing?.templateFileUrl ?? null;
  let templateFileName = existing?.templateFileName ?? null;
  if (templateFile && templateFile.size > 0) {
    templateFileUrl = await saveUploadedFile(session.companyId, templateFile);
    templateFileName = templateFile.name;
  } else if (removeTemplateFile) {
    templateFileUrl = null;
    templateFileName = null;
  }

  await prisma.letterTemplate.upsert({
    where: { companyId_type: { companyId: session.companyId, type } },
    create: { companyId: session.companyId, type, subject, bodyHtml, templateFileUrl, templateFileName },
    update: { subject, bodyHtml, templateFileUrl, templateFileName },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CONFIGURE", entityType: "LetterTemplate", entityId: type });
  revalidatePath("/dashboard/documents");
  return { success: true };
}

export async function addOnboardingTaskTemplate(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  await prisma.onboardingTaskTemplate.create({
    data: {
      companyId: session.companyId,
      category: String(formData.get("category")) as OnboardingTaskCategory,
      title: String(formData.get("title")),
    },
  });
  revalidatePath("/dashboard/onboarding");
}

export async function deleteOnboardingTaskTemplate(id: string) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  await prisma.onboardingTaskTemplate.deleteMany({ where: { id, companyId: session.companyId ?? undefined } });
  revalidatePath("/dashboard/onboarding");
}

export async function setUserActive(userId: string, isActive: boolean) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  const target = await prisma.user.findFirst({ where: { id: userId, companyId: session.companyId ?? undefined } });
  if (!target) throw new Error("User not found.");
  if (target.id === session.sub) throw new Error("You cannot change your own account status.");

  await prisma.user.update({ where: { id: userId }, data: { isActive } });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: isActive ? "ACTIVATE" : "DEACTIVATE", entityType: "User", entityId: userId });
  revalidatePath("/dashboard/settings/users");
}
