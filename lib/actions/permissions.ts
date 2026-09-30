"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/lib/session";
import { recordAudit } from "@/lib/workflow";
import type { Role } from "@prisma/client";

export async function setPayrollAction(role: Role, action: string, allowed: boolean) {
  const session = await requireRole(["SUPER_ADMIN"]);
  const moduleHref = "/dashboard/payroll";

  const existing = await prisma.rolePermission.findFirst({
    where: { role, module: moduleHref, action, companyId: null },
  });

  if (existing) {
    await prisma.rolePermission.update({ where: { id: existing.id }, data: { allowed } });
  } else {
    await prisma.rolePermission.create({
      data: { role, module: moduleHref, action, allowed, companyId: null },
    });
  }

  await recordAudit({
    actorId: session.sub,
    action: "CONFIGURE",
    entityType: "RolePermission",
    entityId: `${role}:${moduleHref}:${action}`,
    meta: { role, module: moduleHref, action, allowed },
  });

  revalidatePath("/dashboard/roles");
}

export async function setModuleAccess(role: Role, moduleHref: string, allowed: boolean) {
  const session = await requireRole(["SUPER_ADMIN"]);

  const existing = await prisma.rolePermission.findFirst({
    where: { role, module: moduleHref, action: "VIEW", companyId: null },
  });

  if (existing) {
    await prisma.rolePermission.update({ where: { id: existing.id }, data: { allowed } });
  } else {
    await prisma.rolePermission.create({
      data: { role, module: moduleHref, action: "VIEW", allowed, companyId: null },
    });
  }

  await recordAudit({
    actorId: session.sub,
    action: "CONFIGURE",
    entityType: "RolePermission",
    entityId: `${role}:${moduleHref}`,
    meta: { role, module: moduleHref, allowed },
  });

  revalidatePath("/dashboard/roles");
  revalidatePath("/dashboard");
}

