import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { completeOnboardingTask } from "@/lib/actions/onboarding";
import { formatDateTime } from "@/lib/format";
import { CheckCircle2, Circle } from "lucide-react";
import type { OnboardingTaskCategory } from "@prisma/client";

export const dynamic = "force-dynamic";

const CATEGORY_LABEL: Record<OnboardingTaskCategory, string> = { HR: "HR", IT: "IT", MANAGER: "Manager" };

export default async function OnboardingDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireModuleAccess("/dashboard/onboarding");

  const onboarding = await prisma.onboarding.findFirst({
    where: { id, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: true, tasks: { orderBy: { category: "asc" } } },
  });
  if (!onboarding) notFound();

  const companyWide = ["COMPANY_ADMIN", "HR", "IT_ADMIN"].includes(session.role);
  if (!companyWide) {
    const visible = await visibleEmployeeIds(session);
    if (!visible.includes(onboarding.employeeId)) redirect("/dashboard/onboarding");
  }

  const grouped = (["HR", "IT", "MANAGER"] as OnboardingTaskCategory[]).map((category) => ({
    category,
    tasks: onboarding.tasks.filter((t) => t.category === category),
  }));

  const employeeManagerId = onboarding.employee.managerId;
  function canComplete(category: OnboardingTaskCategory) {
    if (session.role === "COMPANY_ADMIN") return true;
    if (category === "HR") return session.role === "HR";
    if (category === "IT") return session.role === "IT_ADMIN";
    if (category === "MANAGER") return session.role === "HR" || session.employeeId === employeeManagerId;
    return false;
  }

  async function complete(formData: FormData) {
    "use server";
    await completeOnboardingTask(String(formData.get("taskId")));
  }

  return (
    <div className="max-w-3xl">
      <BackLink fallbackHref="/dashboard/onboarding">Back to onboarding</BackLink>

      <PageHeader
        title={`${onboarding.employee.firstName} ${onboarding.employee.lastName}`}
        subtitle={`${onboarding.employee.designation} — onboarding checklist`}
        action={<StatusBadge status={onboarding.status} />}
      />

      <div className="card p-5 mb-5 flex items-center gap-3">
        <Avatar firstName={onboarding.employee.firstName} lastName={onboarding.employee.lastName} color={onboarding.employee.avatarColor} />
        <Link href={`/dashboard/employees/${onboarding.employeeId}`} className="text-sm text-brand hover:underline">View employee profile</Link>
      </div>

      {grouped.map(({ category, tasks }) => (
        tasks.length > 0 && (
          <div key={category} className="card p-5 mb-5">
            <p className="text-sm font-semibold text-ink mb-3">{CATEGORY_LABEL[category]} tasks</p>
            <ul className="divide-y divide-border-soft">
              {tasks.map((t) => (
                <li key={t.id} className="py-2.5 flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5">
                    {t.status === "DONE" ? <CheckCircle2 size={16} className="text-brand shrink-0" /> : <Circle size={16} className="text-muted shrink-0" />}
                    <div>
                      <p className={`text-sm ${t.status === "DONE" ? "text-muted line-through" : "text-ink"}`}>{t.title}</p>
                      {t.completedAt && <p className="text-xs text-muted">Completed {formatDateTime(t.completedAt)}</p>}
                    </div>
                  </div>
                  {t.status !== "DONE" && canComplete(category) && (
                    <form action={complete}>
                      <input type="hidden" name="taskId" value={t.id} />
                      <button className="btn btn-secondary !py-1 !px-2.5 text-xs">Mark done</button>
                    </form>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )
      ))}
    </div>
  );
}
