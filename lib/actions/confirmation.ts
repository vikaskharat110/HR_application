"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify } from "@/lib/workflow";
import { generateLetterForEmployee } from "@/lib/letters";

/**
 * Employees past their probation end date (dateOfJoining + CompanyPolicy.probationMonths)
 * with no open Confirmation record yet — computed on read rather than a stored
 * status, matching this codebase's "no scheduler" approach (see PromotionTransfer).
 */
export async function probationDueEmployees(companyId: string, employeeIds: string[]) {
  const policy = await prisma.companyPolicy.findUnique({ where: { companyId } });
  const probationMonths = policy?.probationMonths ?? 3;

  const employees = await prisma.employee.findMany({
    where: { id: { in: employeeIds }, companyId, status: "ACTIVE" },
    include: { confirmations: { orderBy: { createdAt: "desc" }, take: 1 } },
  });

  const today = new Date();
  return employees.filter((e) => {
    const dueDate = new Date(e.dateOfJoining);
    dueDate.setMonth(dueDate.getMonth() + probationMonths);
    if (dueDate > today) return false;

    const latest = e.confirmations[0];
    if (!latest) return true;
    if (latest.status === "EXTENDED" && latest.extendedToDate && latest.extendedToDate <= today) return true;
    return false;
  });
}

export async function raiseConfirmation(employeeId: string) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
  if (!employee) throw new Error("Employee not found.");

  const policy = await prisma.companyPolicy.findUnique({ where: { companyId: session.companyId } });
  const dueDate = new Date(employee.dateOfJoining);
  dueDate.setMonth(dueDate.getMonth() + (policy?.probationMonths ?? 3));

  const confirmation = await prisma.confirmation.create({ data: { employeeId, dueDate } });

  if (employee.managerId) {
    const manager = await prisma.employee.findUnique({ where: { id: employee.managerId }, include: { user: true } });
    if (manager?.user) {
      await notify({
        userId: manager.user.id,
        title: "Confirmation review needed",
        message: `${employee.firstName} ${employee.lastName} has reached the end of probation — please recommend confirmation.`,
        link: "/dashboard/employees/confirmations",
      });
    }
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Confirmation", entityId: confirmation.id });
  revalidatePath("/dashboard/employees/confirmations");
}

export async function recommendConfirmation(formData: FormData) {
  const session = await requireSession();
  const confirmationId = String(formData.get("confirmationId"));
  const recommendation = String(formData.get("recommendation") || "").trim();

  const confirmation = await prisma.confirmation.findFirst({
    where: { id: confirmationId, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: true },
  });
  if (!confirmation) throw new Error("Confirmation not found.");
  if (confirmation.status !== "PENDING") throw new Error("This confirmation has already been finalized.");

  await assertCanActOn(session, confirmation.employeeId, confirmation.employee.managerId);

  await prisma.confirmation.update({
    where: { id: confirmationId },
    data: { managerRecommendation: recommendation, managerActedAt: new Date() },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "RECOMMEND", entityType: "Confirmation", entityId: confirmationId, meta: { recommendation } });
  revalidatePath("/dashboard/employees/confirmations");
}

export async function finalizeConfirmation(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");
  const confirmationId = String(formData.get("confirmationId"));
  const decision = String(formData.get("decision")) as "CONFIRMED" | "EXTENDED";
  const extendedToDateRaw = String(formData.get("extendedToDate") || "");

  const confirmation = await prisma.confirmation.findFirst({
    where: { id: confirmationId, employee: { companyId: session.companyId } },
    include: { employee: { include: { user: true } } },
  });
  if (!confirmation) throw new Error("Confirmation not found.");
  if (confirmation.status !== "PENDING") throw new Error("This confirmation has already been finalized.");
  if (decision === "EXTENDED" && !extendedToDateRaw) throw new Error("An extended probation end date is required.");

  await prisma.confirmation.update({
    where: { id: confirmationId },
    data: {
      status: decision,
      hrApproverId: session.employeeId,
      hrActedAt: new Date(),
      extendedToDate: decision === "EXTENDED" ? new Date(extendedToDateRaw) : null,
    },
  });

  if (decision === "CONFIRMED") {
    await generateLetterForEmployee(session.companyId, confirmation.employeeId, "CONFIRMATION");
  }

  if (confirmation.employee.user) {
    await notify({
      userId: confirmation.employee.user.id,
      title: decision === "CONFIRMED" ? "Employment confirmed" : "Probation extended",
      message: decision === "CONFIRMED"
        ? "Congratulations — your employment has been confirmed."
        : `Your probation period has been extended to ${extendedToDateRaw}.`,
      link: `/dashboard/employees/${confirmation.employeeId}`,
    });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: decision, entityType: "Confirmation", entityId: confirmationId });
  revalidatePath("/dashboard/employees/confirmations");
  revalidatePath(`/dashboard/employees/${confirmation.employeeId}`);
}

