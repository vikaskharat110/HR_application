"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, resolveApprover } from "@/lib/workflow";

export async function createReviewCycle(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const cycle = await prisma.reviewCycle.create({
    data: {
      companyId: session.companyId,
      name: String(formData.get("name")),
      startDate: new Date(String(formData.get("startDate"))),
      endDate: new Date(String(formData.get("endDate"))),
      status: "ACTIVE",
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "ReviewCycle", entityId: cycle.id });
  revalidatePath("/dashboard/performance");
}

export async function addGoal(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const cycleId = String(formData.get("cycleId"));
  const cycle = await prisma.reviewCycle.findFirst({ where: { id: cycleId, companyId: session.companyId ?? undefined } });
  if (!cycle) throw new Error("Review cycle not found.");

  const goal = await prisma.goal.create({
    data: {
      employeeId: session.employeeId,
      cycleId,
      title: String(formData.get("title")),
      description: String(formData.get("description") || ""),
      weight: Number(formData.get("weight") || 0),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Goal", entityId: goal.id });
  revalidatePath("/dashboard/performance");
}

/**
 * Self-appraisal (BRD FR-081): the employee's own rating/narrative, stored
 * alongside — never overwriting — the manager's `rating`/`comments` on the
 * same row. If no row exists yet (the manager hasn't reviewed this cycle),
 * one is created with the employee's resolved manager as `reviewerId` (the
 * field is required by schema) but no manager rating yet.
 */
export async function submitSelfAppraisal(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const cycleId = String(formData.get("cycleId"));
  const selfRating = Number(formData.get("selfRating"));
  const selfComments = String(formData.get("selfComments") || "");
  if (!selfRating || selfRating < 1 || selfRating > 5) throw new Error("Rate yourself from 1 to 5.");

  const cycle = await prisma.reviewCycle.findFirst({ where: { id: cycleId, companyId: session.companyId ?? undefined } });
  if (!cycle) throw new Error("Review cycle not found.");

  const existing = await prisma.performanceReview.findUnique({
    where: { employeeId_cycleId: { employeeId: session.employeeId, cycleId } },
  });

  if (existing) {
    await prisma.performanceReview.update({
      where: { id: existing.id },
      data: { selfRating, selfComments, selfSubmittedAt: new Date() },
    });
  } else {
    const reviewer = await resolveApprover(session.employeeId);
    if (!reviewer) throw new Error("No manager on record to route this to — contact HR.");
    await prisma.performanceReview.create({
      data: { employeeId: session.employeeId, cycleId, reviewerId: reviewer.id, selfRating, selfComments, selfSubmittedAt: new Date() },
    });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "SELF_APPRAISAL", entityType: "PerformanceReview", entityId: session.employeeId, meta: { cycleId, selfRating } });
  revalidatePath("/dashboard/performance");
}

export async function submitReview(formData: FormData) {
  const session = await requireSession();
  const employeeId = String(formData.get("employeeId"));
  const cycleId = String(formData.get("cycleId"));
  const rating = Number(formData.get("rating"));
  const comments = String(formData.get("comments") || "");

  const employee = await prisma.employee.findFirst({
    where: { id: employeeId, companyId: session.companyId ?? undefined },
    select: { managerId: true },
  });
  if (!employee) throw new Error("Employee not found.");
  // A performance review's "assigned approver" is the employee's direct
  // manager — same strict single-reviewer rule as approvals elsewhere.
  await assertCanActOn(session, employeeId, employee.managerId);

  const cycle = await prisma.reviewCycle.findFirst({ where: { id: cycleId, companyId: session.companyId ?? undefined } });
  if (!cycle) throw new Error("Review cycle not found.");

  const review = await prisma.performanceReview.upsert({
    where: { employeeId_cycleId: { employeeId, cycleId } },
    create: { employeeId, cycleId, reviewerId: session.employeeId!, rating, comments, status: "APPROVED" },
    update: { rating, comments, status: "APPROVED", reviewerId: session.employeeId! },
    include: { employee: { include: { user: true } } },
  });

  if (review.employee.user) {
    await notify({
      userId: review.employee.user.id,
      title: "Performance review submitted",
      message: `Your manager submitted a review with a rating of ${rating}/5.`,
      link: "/dashboard/performance",
    });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "SUBMIT_REVIEW", entityType: "PerformanceReview", entityId: review.id });
  revalidatePath("/dashboard/performance");
}
