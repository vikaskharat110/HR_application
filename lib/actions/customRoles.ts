"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, ASSIGNABLE_ROLES } from "@/lib/session";
import { recordAudit } from "@/lib/workflow";
import type { Role } from "@prisma/client";

export type CreateCustomRoleState = { error?: string };

export async function createCustomRole(_prev: CreateCustomRoleState, formData: FormData): Promise<CreateCustomRoleState> {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) return { error: "No company context." };

  const name = String(formData.get("name") || "").trim();
  const baseRole = String(formData.get("baseRole") || "") as Role;

  if (!name) return { error: "Give the role a name." };
  if (!ASSIGNABLE_ROLES.includes(baseRole)) return { error: "Pick a valid base role." };

  const existing = await prisma.customRole.findUnique({
    where: { companyId_name: { companyId: session.companyId, name } },
  });
  if (existing) return { error: "A role with this name already exists." };

  const customRole = await prisma.customRole.create({
    data: { companyId: session.companyId, name, baseRole },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "CustomRole",
    entityId: customRole.id,
    meta: { name, baseRole },
  });

  revalidatePath("/dashboard/settings/roles");
  return {};
}

export async function deleteCustomRole(customRoleId: string) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context.");

  const customRole = await prisma.customRole.findFirst({
    where: { id: customRoleId, companyId: session.companyId },
  });
  if (!customRole) throw new Error("Role not found.");

  const assignedCount = await prisma.user.count({ where: { customRoleId } });
  if (assignedCount > 0) {
    throw new Error(`${assignedCount} user(s) still have this role — reassign them before deleting it.`);
  }

  await prisma.$transaction([
    prisma.rolePermission.deleteMany({ where: { role: customRoleId, companyId: session.companyId } }),
    prisma.customRole.delete({ where: { id: customRoleId } }),
  ]);

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "DELETE",
    entityType: "CustomRole",
    entityId: customRoleId,
    meta: { name: customRole.name },
  });

  revalidatePath("/dashboard/settings/roles");
}

export async function setCustomRoleModuleAccess(customRoleId: string, moduleHref: string, allowed: boolean) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context.");

  const customRole = await prisma.customRole.findFirst({
    where: { id: customRoleId, companyId: session.companyId },
  });
  if (!customRole) throw new Error("Role not found.");

  const existing = await prisma.rolePermission.findFirst({
    where: { role: customRoleId, module: moduleHref, action: "VIEW", companyId: session.companyId },
  });

  if (existing) {
    await prisma.rolePermission.update({ where: { id: existing.id }, data: { allowed } });
  } else {
    await prisma.rolePermission.create({
      data: { role: customRoleId, module: moduleHref, action: "VIEW", allowed, companyId: session.companyId },
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CONFIGURE",
    entityType: "CustomRole",
    entityId: customRoleId,
    meta: { module: moduleHref, allowed },
  });

  revalidatePath("/dashboard/settings/roles");
  revalidatePath("/dashboard");
}
