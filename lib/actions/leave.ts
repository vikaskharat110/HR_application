"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, resolveApprover } from "@/lib/workflow";

function dayCount(start: Date, end: Date) {
  return Math.round((end.getTime() - start.getTime()) / 86400000) + 1;
}

export async function applyLeave(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const leaveTypeId = String(formData.get("leaveTypeId"));
  const startDate = new Date(String(formData.get("startDate")));
  const endDate = new Date(String(formData.get("endDate")));
  const reason = String(formData.get("reason") || "").trim();

  if (!leaveTypeId || !reason || endDate < startDate) {
    throw new Error("Please fill in all fields correctly");
  }

  const days = dayCount(startDate, endDate);
  const approver = await resolveApprover(session.employeeId);

  const request = await prisma.leaveRequest.create({
    data: {
      employeeId: session.employeeId,
      leaveTypeId,
      startDate,
      endDate,
      days,
      reason,
      approverId: approver?.id,
    },
  });

  if (approver?.userId) {
    await notify({
      userId: approver.userId,
      title: "New leave request",
      message: `${session.name} requested ${days} day(s) of leave.`,
      link: "/dashboard/leave/approvals",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "LeaveRequest",
    entityId: request.id,
    meta: { days },
  });

  revalidatePath("/dashboard/leave");
}

export async function decideLeave(id: string, approve: boolean, comments?: string) {
  const session = await requireSession();

  const existing = await prisma.leaveRequest.findUnique({ where: { id }, select: { employeeId: true, status: true, approverId: true } });
  if (!existing) throw new Error("Leave request not found.");
  await assertCanActOn(session, existing.employeeId, existing.approverId);
  if (existing.status !== "PENDING") throw new Error("This request has already been actioned.");

  const request = await prisma.leaveRequest.update({
    where: { id },
    data: { status: approve ? "APPROVED" : "REJECTED", actedAt: new Date(), comments },
    include: { employee: { include: { user: true } }, leaveType: true },
  });

  if (approve) {
    const year = request.startDate.getFullYear();
    if (request.leaveType.paid) {
      const balance = await prisma.leaveBalance.findUnique({
        where: { employeeId_leaveTypeId_year: { employeeId: request.employeeId, leaveTypeId: request.leaveTypeId, year } },
      });
      const remaining = (balance?.allocated ?? 0) - (balance?.used ?? 0);
      if (request.days > remaining) {
        throw new Error(
          `Approving this would exceed the employee's remaining ${request.leaveType.name} balance (${remaining} day(s) left). Reject it instead, or have them re-apply for fewer days.`
        );
      }
    }
    await prisma.leaveBalance.updateMany({
      where: { employeeId: request.employeeId, leaveTypeId: request.leaveTypeId, year },
      data: { used: { increment: request.days } },
    });
  }

  if (request.employee.user) {
    await notify({
      userId: request.employee.user.id,
      title: `Leave request ${approve ? "approved" : "rejected"}`,
      message: `Your ${request.leaveType.name} request for ${request.days} day(s) was ${approve ? "approved" : "rejected"}.`,
      link: "/dashboard/leave",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: approve ? "APPROVE" : "REJECT",
    entityType: "LeaveRequest",
    entityId: id,
  });

  revalidatePath("/dashboard/leave/approvals");
  revalidatePath("/dashboard/leave");
}

export async function cancelLeave(id: string) {
  const session = await requireSession();
  await prisma.leaveRequest.updateMany({
    where: { id, employeeId: session.employeeId ?? undefined, status: "PENDING" },
    data: { status: "CANCELLED" },
  });
  revalidatePath("/dashboard/leave");
}
