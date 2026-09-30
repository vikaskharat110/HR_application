import "server-only";
import type { Prisma } from "@prisma/client";
import { prisma } from "./prisma";

/**
 * Every lifecycle event in the platform funnels through here so it always
 * leaves an audit trail and, optionally, notifies the people who need to
 * know — mirroring the "event -> audit log -> notification" rule the
 * platform is built around.
 */
export async function recordAudit(params: {
  companyId?: string | null;
  actorId?: string | null;
  action: string;
  entityType: string;
  entityId: string;
  meta?: Record<string, unknown>;
}) {
  await prisma.auditLog.create({
    data: {
      companyId: params.companyId ?? null,
      actorId: params.actorId ?? null,
      action: params.action,
      entityType: params.entityType,
      entityId: params.entityId,
      meta: (params.meta ?? {}) as Prisma.InputJsonValue,
    },
  });
}

export async function notify(params: {
  userId: string;
  title: string;
  message: string;
  link?: string;
}) {
  await prisma.notification.create({
    data: {
      userId: params.userId,
      title: params.title,
      message: params.message,
      link: params.link,
    },
  });
}

export async function notifyMany(
  userIds: string[],
  params: { title: string; message: string; link?: string }
) {
  if (userIds.length === 0) return;
  await prisma.notification.createMany({
    data: userIds.map((userId) => ({ userId, ...params })),
  });
}

/**
 * Resolves who should approve a request raised by `employeeId`: their direct
 * manager from the org chart, or — only for someone with no manager at all
 * (the top of the hierarchy, e.g. the Company Admin) — another HR/Admin in
 * the company. The requester is always excluded so nobody ends up approving
 * their own request.
 */
export async function resolveApprover(employeeId: string) {
  const employee = await prisma.employee.findUnique({
    where: { id: employeeId },
    include: { manager: { include: { user: true } } },
  });
  if (!employee) return null;

  if (employee.manager) return employee.manager;

  const hrUser = await prisma.user.findFirst({
    where: {
      companyId: employee.companyId,
      role: { in: ["COMPANY_ADMIN", "HR"] },
      employee: { is: { id: { not: employeeId } } },
    },
    orderBy: { role: "asc" },
    include: { employee: true },
  });
  return hrUser?.employee ?? null;
}

