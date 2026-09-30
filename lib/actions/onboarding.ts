"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { recordAudit, notify } from "@/lib/workflow";
import type { OnboardingTaskCategory } from "@prisma/client";

/**
 * Manual fallback for HR/Admin: starts a checklist for an employee who
 * doesn't have one yet — covers hires made before any task templates existed
 * for the company, or anyone onboarded outside the recruitment pipeline.
 */
export async function startOnboarding(employeeId: string) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const employee = await prisma.employee.findFirst({
    where: { id: employeeId, companyId: session.companyId },
    include: { onboarding: true },
  });
  if (!employee) throw new Error("Employee not found.");
  if (employee.onboarding) throw new Error("This employee already has an onboarding checklist.");

  const templates = await prisma.onboardingTaskTemplate.findMany({
    where: { companyId: session.companyId },
    orderBy: { sortOrder: "asc" },
  });

  await prisma.onboarding.create({
    data: {
      employeeId,
      tasks: { create: templates.map((t) => ({ category: t.category, title: t.title })) },
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Onboarding", entityId: employeeId, meta: { manualStart: true, taskCount: templates.length } });
  revalidatePath(`/dashboard/employees/${employeeId}`);
  revalidatePath("/dashboard/onboarding");
}

/** Who may tick off a task depends on its category — HR/IT/the employee's own manager (BRD FR-032). */
async function assertCanCompleteTask(
  session: Awaited<ReturnType<typeof requireSession>>,
  category: OnboardingTaskCategory,
  employeeManagerId: string | null
) {
  if (session.role === "COMPANY_ADMIN") return;
  if (category === "HR" && session.role === "HR") return;
  if (category === "IT" && session.role === "IT_ADMIN") return;
  if (category === "MANAGER" && session.employeeId && session.employeeId === employeeManagerId) return;
  if (category === "MANAGER" && session.role === "HR") return;
  throw new Error("You are not authorized to complete this onboarding task.");
}

export async function completeOnboardingTask(taskId: string) {
  const session = await requireSession();

  const task = await prisma.onboardingTask.findFirst({
    where: { id: taskId, onboarding: { employee: { companyId: session.companyId ?? undefined } } },
    include: { onboarding: { include: { employee: true, tasks: true } } },
  });
  if (!task) throw new Error("Onboarding task not found.");
  if (task.status === "DONE") return;

  // Nobody signs off on their own onboarding — this matters most for HR: their
  // role gives them a blanket HR-category (and manager-backup) completion
  // right that would otherwise apply to their own checklist too, letting a
  // new HR hire self-serve tasks meant to be verified by someone else.
  if (session.employeeId === task.onboarding.employee.id) {
    throw new Error("You cannot complete your own onboarding tasks — ask your manager or HR/Admin.");
  }

  await assertCanCompleteTask(session, task.category, task.onboarding.employee.managerId);

  await prisma.onboardingTask.update({
    where: { id: taskId },
    data: { status: "DONE", completedById: session.employeeId, completedAt: new Date() },
  });

  const remaining = task.onboarding.tasks.filter((t) => t.id !== taskId && t.status !== "DONE");
  if (remaining.length === 0) {
    await prisma.onboarding.update({
      where: { id: task.onboarding.id },
      data: { status: "COMPLETED", completedAt: new Date() },
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "ONBOARDING_TASK_COMPLETED",
    entityType: "OnboardingTask",
    entityId: taskId,
    meta: { title: task.title, category: task.category },
  });

  if (remaining.length === 0 && task.onboarding.employee.userId) {
    await notify({
      userId: task.onboarding.employee.userId,
      title: "Onboarding complete",
      message: "All your onboarding tasks have been completed — welcome aboard!",
      link: `/dashboard/employees/${task.onboarding.employee.id}`,
    });
  }

  revalidatePath("/dashboard/onboarding");
  revalidatePath(`/dashboard/onboarding/${task.onboarding.id}`);
  revalidatePath(`/dashboard/employees/${task.onboarding.employee.id}`);
}

