"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, notifyMany } from "@/lib/workflow";
import { generateLetterForEmployee } from "@/lib/letters";
import { unauthorizedAbsenceDays } from "@/lib/absence";
import type { ExitDepartment } from "@prisma/client";

const DEPARTMENTS: ExitDepartment[] = ["MANAGER", "HR", "IT", "FINANCE", "ASSET"];

export async function initiateResignation(formData: FormData) {
  const session = await requireSession();
  const employeeId = String(formData.get("employeeId") || session.employeeId || "");
  if (!employeeId) throw new Error("No employee record linked to this account.");
  if (!session.companyId) throw new Error("No company context");

  // Employees resign for themselves; HR can raise it on someone's behalf.
  const isSelf = employeeId === session.employeeId;
  const isHR = session.role === "COMPANY_ADMIN" || session.role === "HR";
  if (!isSelf && !isHR) throw new Error("You can only initiate a resignation for yourself.");

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
  if (!employee) throw new Error("Employee not found.");

  const openExit = await prisma.employeeExit.findFirst({
    where: { employeeId, status: { notIn: ["COMPLETED", "REJECTED"] } },
  });
  if (openExit) throw new Error("This employee already has an exit in progress.");

  const resignationDate = new Date(String(formData.get("resignationDate") || new Date().toISOString()));
  const reason = String(formData.get("reason") || "").trim();
  if (!reason) throw new Error("A reason is required.");

  const policy = await prisma.companyPolicy.findUnique({ where: { companyId: session.companyId } });

  const exit = await prisma.employeeExit.create({
    data: {
      employeeId,
      requestedById: session.employeeId ?? employeeId,
      resignationDate,
      reason,
      noticePeriodDays: policy?.noticePeriodDays ?? 30,
    },
  });

  if (employee.managerId) {
    const manager = await prisma.employee.findUnique({ where: { id: employee.managerId }, include: { user: true } });
    if (manager?.user) {
      await notify({ userId: manager.user.id, title: "Resignation submitted", message: `${employee.firstName} ${employee.lastName} has submitted their resignation.`, link: `/dashboard/exit/${exit.id}` });
    }
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "EmployeeExit", entityId: exit.id });
  revalidatePath("/dashboard/exit");
  return exit.id;
}

export async function reviewExitAsManager(exitId: string, approve: boolean, comments?: string) {
  const session = await requireSession();
  const exit = await prisma.employeeExit.findFirst({
    where: { id: exitId, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: true },
  });
  if (!exit) throw new Error("Exit request not found.");
  if (exit.status !== "PENDING") throw new Error("This exit request has already moved past manager review.");
  await assertCanActOn(session, exit.employeeId, exit.employee.managerId);

  await prisma.employeeExit.update({
    where: { id: exitId },
    data: { status: approve ? "MANAGER_APPROVED" : "REJECTED", managerApproverId: session.employeeId, actedAt: new Date() },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: approve ? "MANAGER_APPROVE" : "REJECT", entityType: "EmployeeExit", entityId: exitId, meta: { comments } });
  revalidatePath(`/dashboard/exit/${exitId}`);
  revalidatePath("/dashboard/exit");
}

export async function approveExitAsHR(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  const exitId = String(formData.get("exitId"));
  const lastWorkingDate = new Date(String(formData.get("lastWorkingDate")));

  const exit = await prisma.employeeExit.findFirst({
    where: { id: exitId, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: { include: { user: true } } },
  });
  if (!exit) throw new Error("Exit request not found.");
  if (exit.status !== "MANAGER_APPROVED") throw new Error("Manager approval is required before HR sign-off.");

  await prisma.$transaction([
    prisma.employeeExit.update({
      where: { id: exitId },
      data: { status: "CLEARANCE", hrApproverId: session.employeeId, lastWorkingDate, actedAt: new Date() },
    }),
    prisma.employee.update({ where: { id: exit.employeeId }, data: { status: "NOTICE_PERIOD" } }),
    ...DEPARTMENTS.map((department) =>
      prisma.exitClearance.create({ data: { exitId, department } })
    ),
  ]);

  const queueUsers = await prisma.user.findMany({
    where: { companyId: session.companyId ?? undefined, role: { in: ["IT_ADMIN", "FINANCE"] } },
  });
  await notifyMany(queueUsers.map((u) => u.id), {
    title: "Exit clearance required",
    message: `${exit.employee.firstName} ${exit.employee.lastName} is exiting — please complete your department's clearance.`,
    link: `/dashboard/exit/${exitId}`,
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "HR_APPROVE", entityType: "EmployeeExit", entityId: exitId, meta: { lastWorkingDate } });
  revalidatePath(`/dashboard/exit/${exitId}`);
  revalidatePath("/dashboard/exit");
}

export async function clearDepartment(formData: FormData) {
  const session = await requireSession();
  const clearanceId = String(formData.get("clearanceId"));
  const remarks = String(formData.get("remarks") || "");

  const clearance = await prisma.exitClearance.findFirst({
    where: { id: clearanceId, exit: { employee: { companyId: session.companyId ?? undefined } } },
    include: { exit: { include: { employee: true } } },
  });
  if (!clearance) throw new Error("Clearance record not found.");
  if (clearance.status === "CLEARED") return;

  const ROLE_FOR_DEPARTMENT: Record<ExitDepartment, string[]> = {
    MANAGER: ["COMPANY_ADMIN", "HR"],
    HR: ["COMPANY_ADMIN", "HR"],
    IT: ["COMPANY_ADMIN", "IT_ADMIN"],
    FINANCE: ["COMPANY_ADMIN", "FINANCE"],
    ASSET: ["COMPANY_ADMIN", "IT_ADMIN"],
  };
  const isDepartmentManager = clearance.department === "MANAGER" && session.employeeId === clearance.exit.employee.managerId;
  if (!isDepartmentManager && !ROLE_FOR_DEPARTMENT[clearance.department].includes(session.role)) {
    throw new Error("You are not authorized to clear this department.");
  }

  // FR-101: asset return is mandatory before the ASSET clearance can go through.
  if (clearance.department === "ASSET") {
    const outstanding = await prisma.assetAssignment.count({ where: { employeeId: clearance.exit.employeeId, returnedDate: null } });
    if (outstanding > 0) throw new Error("This employee still has unreturned assets — return them before clearing.");
  }

  await prisma.exitClearance.update({
    where: { id: clearanceId },
    data: { status: "CLEARED", clearedById: session.employeeId, clearedAt: new Date(), remarks },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CLEAR", entityType: "ExitClearance", entityId: clearanceId, meta: { department: clearance.department } });
  revalidatePath(`/dashboard/exit/${clearance.exitId}`);
}

export async function generateFullFinal(exitId: string) {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "FINANCE"]);
  if (!session.companyId) throw new Error("No company context");

  const exit = await prisma.employeeExit.findFirst({
    where: { id: exitId, employee: { companyId: session.companyId ?? undefined } },
    include: { clearances: true, employee: { include: { salaryStructure: true, leaveBalances: true } }, settlement: true },
  });
  if (!exit) throw new Error("Exit request not found.");
  if (exit.clearances.some((c) => c.status !== "CLEARED")) throw new Error("All department clearances must be complete first.");
  if (exit.settlement) throw new Error("A settlement has already been generated for this exit.");

  const salary = exit.employee.salaryStructure;
  const grossMonthly = salary ? salary.basic + salary.hra + salary.conveyance + salary.medicalAllowance + salary.specialAllowance : 0;
  const perDay = grossMonthly / 30;

  const year = new Date().getFullYear();
  const unusedLeave = exit.employee.leaveBalances
    .filter((b) => b.year === year)
    .reduce((sum, b) => sum + Math.max(0, b.allocated - b.used), 0);
  const leaveEncashment = Math.round(unusedLeave * perDay);

  const lastPayrollRun = await prisma.payrollRun.findFirst({
    where: { companyId: session.companyId ?? undefined },
    orderBy: [{ year: "desc" }, { month: "desc" }],
  });
  const since = lastPayrollRun ? new Date(lastPayrollRun.year, lastPayrollRun.month, 0) : new Date(0);
  const sinceExclusive = new Date(since);
  sinceExclusive.setDate(sinceExclusive.getDate() + 1);
  const until = exit.lastWorkingDate ?? new Date();
  const lopByEmployee = await unauthorizedAbsenceDays(session.companyId, [exit.employeeId], sinceExclusive, until);
  const unprocessedAbsences = lopByEmployee.get(exit.employeeId) ?? 0;
  const lopDeduction = Math.round(unprocessedAbsences * perDay);

  const pendingExpensesAgg = await prisma.expenseClaim.aggregate({
    where: { employeeId: exit.employeeId, status: "APPROVED" },
    _sum: { amount: true },
  });
  const pendingExpenses = pendingExpensesAgg._sum.amount ?? 0;

  const netPayable = Math.round(leaveEncashment + pendingExpenses - lopDeduction);

  const settlement = await prisma.fullFinalSettlement.create({
    data: { exitId, leaveEncashment, lopDeduction, pendingExpenses, netPayable },
  });

  await prisma.employeeExit.update({ where: { id: exitId }, data: { status: "SETTLED" } });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "GENERATE", entityType: "FullFinalSettlement", entityId: settlement.id });
  revalidatePath(`/dashboard/exit/${exitId}`);
}

export async function approveFullFinal(exitId: string) {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "FINANCE"]);
  if (!session.companyId) throw new Error("No company context");

  const exit = await prisma.employeeExit.findFirst({
    where: { id: exitId, employee: { companyId: session.companyId } },
    include: { settlement: true, employee: true },
  });
  if (!exit || !exit.settlement) throw new Error("No settlement to approve.");
  if (exit.settlement.status !== "DRAFT") throw new Error("This settlement has already been approved.");

  await prisma.$transaction([
    prisma.fullFinalSettlement.update({ where: { id: exit.settlement.id }, data: { status: "APPROVED", approvedAt: new Date() } }),
    prisma.employeeExit.update({ where: { id: exitId }, data: { status: "COMPLETED" } }),
    prisma.employee.update({ where: { id: exit.employeeId }, data: { status: "EXITED", dateOfExit: exit.lastWorkingDate ?? new Date() } }),
  ]);

  if (exit.employee.userId) {
    await prisma.user.update({ where: { id: exit.employee.userId }, data: { isActive: false } });
  }

  await generateLetterForEmployee(session.companyId, exit.employeeId, "RELIEVING");
  await generateLetterForEmployee(session.companyId, exit.employeeId, "EXPERIENCE");

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "APPROVE", entityType: "FullFinalSettlement", entityId: exit.settlement.id });
  revalidatePath(`/dashboard/exit/${exitId}`);
  revalidatePath(`/dashboard/employees/${exit.employeeId}`);
  revalidatePath("/dashboard/exit");
}

export async function recordExitInterview(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  const exitId = String(formData.get("exitId"));
  const notes = String(formData.get("notes") || "");

  await prisma.employeeExit.updateMany({
    where: { id: exitId, employee: { companyId: session.companyId ?? undefined } },
    data: { exitInterviewNotes: notes },
  });

  revalidatePath(`/dashboard/exit/${exitId}`);
}
