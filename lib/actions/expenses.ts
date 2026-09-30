"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, resolveApprover } from "@/lib/workflow";

export type SubmitExpenseState = { error?: string; success?: boolean };

export async function submitExpense(_prev: SubmitExpenseState, formData: FormData): Promise<SubmitExpenseState> {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const category = String(formData.get("category"));
  const amount = Number(formData.get("amount"));
  const description = String(formData.get("description") || "");
  if (!category) return { error: "Category is required." };
  if (!Number.isFinite(amount) || amount <= 0) return { error: "Enter an amount greater than zero." };

  const approver = await resolveApprover(session.employeeId);

  const claim = await prisma.expenseClaim.create({
    data: { employeeId: session.employeeId, category, amount, description, approverId: approver?.id },
  });

  if (approver?.userId) {
    await notify({
      userId: approver.userId,
      title: "New expense claim",
      message: `${session.name} submitted a ${category} claim for ₹${amount.toLocaleString("en-IN")}.`,
      link: "/dashboard/expenses",
    });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "ExpenseClaim", entityId: claim.id });
  revalidatePath("/dashboard/expenses");
  return { success: true };
}

export async function decideExpense(id: string, approve: boolean, comments?: string) {
  const session = await requireSession();

  const existing = await prisma.expenseClaim.findFirst({
    where: { id, employee: { companyId: session.companyId ?? undefined } },
    select: { employeeId: true, status: true, approverId: true },
  });
  if (!existing) throw new Error("Expense claim not found.");
  if (existing.status !== "PENDING") throw new Error("This claim has already been actioned.");

  // Finance holds a company-wide override on expense approval (BRD Section 10 —
  // "Expenses: Finance = Full"), separate from the manager-chain approval every
  // other role goes through via assertCanActOn.
  if (session.role !== "FINANCE") {
    await assertCanActOn(session, existing.employeeId, existing.approverId);
  }

  const claim = await prisma.expenseClaim.update({
    where: { id },
    data: { status: approve ? "APPROVED" : "REJECTED", actedAt: new Date(), comments },
    include: { employee: { include: { user: true } } },
  });

  if (claim.employee.user) {
    await notify({
      userId: claim.employee.user.id,
      title: `Expense claim ${approve ? "approved" : "rejected"}`,
      message: `Your ${claim.category} claim for ₹${claim.amount.toLocaleString("en-IN")} was ${approve ? "approved" : "rejected"}.`,
      link: "/dashboard/expenses",
    });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: approve ? "APPROVE" : "REJECT", entityType: "ExpenseClaim", entityId: id });
  revalidatePath("/dashboard/expenses");
}

