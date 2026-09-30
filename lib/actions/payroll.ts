"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { Prisma } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { recordAudit, notify } from "@/lib/workflow";
import { hasPayrollAction } from "@/lib/permissions";
import { computeStatutoryDeductions } from "@/lib/statutory";
import { unauthorizedAbsenceDays } from "@/lib/absence";
import { monthName } from "@/lib/format";

function weekdaysInMonth(year: number, month: number) {
  const days = new Date(year, month, 0).getDate();
  let count = 0;
  for (let d = 1; d <= days; d++) {
    const dow = new Date(year, month - 1, d).getDay();
    if (dow !== 0 && dow !== 6) count++;
  }
  return count;
}

export type RunPayrollState = { error?: string };

export async function runPayroll(_prev: RunPayrollState, formData: FormData): Promise<RunPayrollState> {
  const session = await requireSession();
  if (!session.companyId) throw new Error("No company context");
  if (!(await hasPayrollAction(session, "PROCESS"))) {
    throw new Error("You are not authorized to process payroll.");
  }

  const month = Number(formData.get("month"));
  const year = Number(formData.get("year"));
  const payDateRaw = String(formData.get("payDate") || "");
  if (!payDateRaw) return { error: "Set the salary/payment date for this run." };
  const payDate = new Date(payDateRaw);

  // Create first and let the unique constraint be the single source of truth
  // for "already run" — checking with a separate findUnique first leaves a
  // race window where two rapid clicks (or a client double-submit) can both
  // pass the check and then collide on the create, surfacing an ugly
  // constraint-violation crash instead of the friendly message below.
  let run;
  try {
    run = await prisma.payrollRun.create({
      data: { companyId: session.companyId, month, year, payDate, status: "PROCESSED", processedAt: new Date() },
    });
  } catch (err) {
    if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === "P2002") {
      return { error: `Payroll for ${monthName(month)} ${year} has already been run.` };
    }
    throw err;
  }

  const employees = await prisma.employee.findMany({
    where: { companyId: session.companyId, status: { in: ["ACTIVE", "ON_LEAVE", "NOTICE_PERIOD"] } },
    include: { salaryStructure: true },
  });

  const periodStart = new Date(year, month - 1, 1);
  const periodEnd = new Date(year, month, 0, 23, 59, 59);
  const workingDays = weekdaysInMonth(year, month);

  const lopByEmployee = await unauthorizedAbsenceDays(
    session.companyId,
    employees.map((e) => e.id),
    periodStart,
    periodEnd
  );

  for (const emp of employees) {
    if (!emp.salaryStructure) continue;
    const s = emp.salaryStructure;
    const gross = s.basic + s.hra + s.conveyance + s.medicalAllowance + s.specialAllowance;

    const lopDays = lopByEmployee.get(emp.id) ?? 0;

    const perDay = gross / workingDays;
    const lopDeduction = Math.round(perDay * lopDays);
    const totalDeductions = s.pfEmployee + s.esi + s.professionalTax + s.tds + lopDeduction;
    const netPay = Math.round(gross - totalDeductions);

    await prisma.payslip.create({
      data: {
        payrollRunId: run.id,
        employeeId: emp.id,
        grossPay: gross,
        totalDeductions,
        netPay,
        workingDays,
        paidDays: workingDays - lopDays,
        lopDays,
        isHeld: emp.salaryOnHold,
        holdReason: emp.salaryOnHold ? emp.salaryHoldReason : null,
        breakdown: {
          basic: s.basic, hra: s.hra, conveyance: s.conveyance, medicalAllowance: s.medicalAllowance,
          specialAllowance: s.specialAllowance, pfEmployee: s.pfEmployee, esi: s.esi,
          professionalTax: s.professionalTax, tds: s.tds, lopDays, lopDeduction,
        },
      },
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "PAYROLL_RUN",
    entityType: "PayrollRun",
    entityId: run.id,
    meta: { month, year, employees: employees.length },
  });

  revalidatePath("/dashboard/payroll");
  redirect(`/dashboard/payroll/${run.id}`);
}

async function assertRunStatus(companyId: string, runId: string, expected: string) {
  const run = await prisma.payrollRun.findFirst({ where: { id: runId, companyId } });
  if (!run) throw new Error("Payroll run not found.");
  if (run.status !== expected) throw new Error(`Payroll run must be ${expected} to perform this action.`);
}

export async function approvePayrollRun(runId: string) {
  const session = await requireSession();
  if (!session.companyId) throw new Error("No company context");
  if (!(await hasPayrollAction(session, "APPROVE"))) {
    throw new Error("You are not authorized to approve payroll.");
  }
  await assertRunStatus(session.companyId, runId, "PROCESSED");

  await prisma.payrollRun.update({ where: { id: runId }, data: { status: "APPROVED", approvedAt: new Date() } });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "PAYROLL_APPROVED", entityType: "PayrollRun", entityId: runId });
  revalidatePath(`/dashboard/payroll/${runId}`);
}

export async function lockPayrollRun(runId: string) {
  const session = await requireSession();
  if (!session.companyId) throw new Error("No company context");
  if (!(await hasPayrollAction(session, "LOCK"))) {
    throw new Error("You are not authorized to lock payroll.");
  }
  await assertRunStatus(session.companyId, runId, "APPROVED");

  const run = await prisma.payrollRun.update({
    where: { id: runId },
    data: { status: "LOCKED", lockedAt: new Date() },
    include: { payslips: { include: { employee: { include: { user: true } } } } },
  });

  for (const p of run.payslips) {
    if (p.employee.user) {
      await notify({
        userId: p.employee.user.id,
        title: p.isHeld ? "Payslip published — payment on hold" : "Payslip published",
        message: p.isHeld
          ? `Your payslip for ${run.month}/${run.year} is ready to view, but payment is currently on hold. Contact HR for details.`
          : `Your payslip for ${run.month}/${run.year} is ready to view.`,
        link: "/dashboard/payroll",
      });
    }
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "PAYROLL_LOCKED", entityType: "PayrollRun", entityId: runId });
  revalidatePath(`/dashboard/payroll/${runId}`);
}

export async function reopenPayrollRun(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  const runId = String(formData.get("runId"));
  const reason = String(formData.get("reason") || "").trim();
  if (!reason) throw new Error("A reason is required to reopen a locked payroll run.");

  const run = await prisma.payrollRun.findFirst({ where: { id: runId, companyId: session.companyId ?? undefined } });
  if (!run) throw new Error("Payroll run not found.");
  if (run.status !== "LOCKED") throw new Error("Only a locked payroll run can be reopened.");

  await prisma.payrollRun.update({
    where: { id: runId },
    data: { status: "PROCESSED", approvedAt: null, lockedAt: null, reopenReason: reason },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "PAYROLL_REOPENED",
    entityType: "PayrollRun",
    entityId: runId,
    meta: { reason },
  });

  revalidatePath(`/dashboard/payroll/${runId}`);
}

export async function upsertSalaryStructure(formData: FormData) {
  const session = await requireSession();
  if (!(await hasPayrollAction(session, "EDIT_SALARY"))) {
    throw new Error("You are not authorized to edit salary structures.");
  }
  const employeeId = String(formData.get("employeeId"));
  // Salary structures are always stored monthly (that's what payroll processing
  // consumes directly) — but the admin can enter figures either way, so a
  // "YEARLY" entry just gets divided down to its monthly equivalent here.
  const payPeriod = String(formData.get("payPeriod") || "MONTHLY");
  const divisor = payPeriod === "YEARLY" ? 12 : 1;
  const toMonthly = (raw: FormDataEntryValue | null) => Math.round(Number(raw || 0) / divisor);

  const basic = toMonthly(formData.get("basic"));
  const hra = toMonthly(formData.get("hra"));
  const conveyance = toMonthly(formData.get("conveyance"));
  const medicalAllowance = toMonthly(formData.get("medicalAllowance"));
  const specialAllowance = toMonthly(formData.get("specialAllowance"));

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId ?? undefined } });
  if (!employee) throw new Error("Employee not found.");

  const { pfEmployee, esi, professionalTax, tds } = await computeStatutoryDeductions(employee.companyId, basic);

  await prisma.salaryStructure.upsert({
    where: { employeeId },
    create: { employeeId, basic, hra, conveyance, medicalAllowance, specialAllowance, pfEmployee, esi, professionalTax, tds },
    update: { basic, hra, conveyance, medicalAllowance, specialAllowance, pfEmployee, esi, professionalTax, tds },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "UPSERT",
    entityType: "SalaryStructure",
    entityId: employeeId,
  });

  revalidatePath("/dashboard/payroll/salary");
}

const SALARY_HOLD_ROLES = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN"] as const;

/**
 * A standing per-employee hold — checked only at the moment `runPayroll`
 * generates that employee's payslip for a period, so placing or lifting a
 * hold never retroactively changes a payslip that already exists (use
 * `setPayslipHold` for that). Notifies the employee's own login, if any, so
 * a hold is never silent.
 */
export async function setSalaryHold(formData: FormData) {
  const session = await requireRole(SALARY_HOLD_ROLES);
  if (!session.companyId) throw new Error("No company context");

  const employeeId = String(formData.get("employeeId"));
  const hold = formData.get("hold") === "1";
  const reason = String(formData.get("reason") || "").trim();
  if (hold && !reason) throw new Error("Give a reason for the hold.");

  const employee = await prisma.employee.findFirst({
    where: { id: employeeId, companyId: session.companyId },
    include: { user: true },
  });
  if (!employee) throw new Error("Employee not found.");

  await prisma.employee.update({
    where: { id: employeeId },
    data: { salaryOnHold: hold, salaryHoldReason: hold ? reason : null },
  });

  if (employee.user) {
    await notify({
      userId: employee.user.id,
      title: hold ? "Your salary has been put on hold" : "Your salary hold has been lifted",
      message: hold
        ? `Future payroll runs will withhold your payment until this is lifted. Reason: ${reason}`
        : "Your salary will be included normally in future payroll runs.",
      link: "/dashboard/payroll",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: hold ? "SALARY_HOLD" : "SALARY_HOLD_LIFTED",
    entityType: "Employee",
    entityId: employeeId,
    meta: hold ? { reason } : undefined,
  });

  revalidatePath(`/dashboard/employees/${employeeId}`);
}

/**
 * Toggles the hold on one already-generated payslip, for a run that isn't
 * locked yet — e.g. a hold placed after this month's run was already
 * processed. Locked runs can only be changed via `reopenPayrollRun` first,
 * same restriction every other payroll edit already respects.
 */
export async function setPayslipHold(formData: FormData) {
  const session = await requireRole(SALARY_HOLD_ROLES);
  if (!session.companyId) throw new Error("No company context");

  const payslipId = String(formData.get("payslipId"));
  const held = formData.get("held") === "1";
  const reason = String(formData.get("reason") || "").trim();
  if (held && !reason) throw new Error("Give a reason for the hold.");

  const payslip = await prisma.payslip.findFirst({
    where: { id: payslipId, employee: { companyId: session.companyId } },
    include: { payrollRun: true },
  });
  if (!payslip) throw new Error("Payslip not found.");
  if (payslip.payrollRun.status === "LOCKED") {
    throw new Error("This run is locked — reopen it first before changing a hold.");
  }

  await prisma.payslip.update({
    where: { id: payslipId },
    data: { isHeld: held, holdReason: held ? reason : null },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: held ? "PAYSLIP_HOLD" : "PAYSLIP_HOLD_LIFTED",
    entityType: "Payslip",
    entityId: payslipId,
    meta: held ? { reason } : undefined,
  });

  revalidatePath(`/dashboard/payroll/${payslip.payrollRunId}`);
}
