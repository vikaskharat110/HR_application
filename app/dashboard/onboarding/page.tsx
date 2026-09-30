import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge, EmptyState } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { ClipboardCheck } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function OnboardingListPage() {
  const session = await requireModuleAccess("/dashboard/onboarding");
  const companyWide = ["COMPANY_ADMIN", "HR", "IT_ADMIN"].includes(session.role);
  const employeeIds = companyWide ? null : await visibleEmployeeIds(session);

  const onboardings = await prisma.onboarding.findMany({
    where: {
      employee: {
        companyId: session.companyId ?? undefined,
        ...(employeeIds ? { id: { in: employeeIds } } : {}),
      },
    },
    include: { employee: true, tasks: true },
    orderBy: { startedAt: "desc" },
  });

  return (
    <div>
      <PageHeader title="Onboarding" subtitle="Pre-boarding, BGV and IT/HR/Manager checklists for new hires." />

      {onboardings.length === 0 ? (
        <div className="card"><EmptyState icon={ClipboardCheck} title="No onboarding checklists yet" message="These are created automatically when a hired candidate is onboarded as an employee." /></div>
      ) : (
        <div className="card divide-y divide-border-soft">
          {onboardings.map((o) => {
            const done = o.tasks.filter((t) => t.status === "DONE").length;
            const pct = o.tasks.length ? Math.round((done / o.tasks.length) * 100) : 0;
            return (
              <Link key={o.id} href={`/dashboard/onboarding/${o.id}`} className="flex items-center justify-between gap-4 px-5 py-4 hover:bg-[var(--color-brand-softer)] transition-colors">
                <div className="flex items-center gap-3 min-w-0">
                  <Avatar firstName={o.employee.firstName} lastName={o.employee.lastName} color={o.employee.avatarColor} />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-ink truncate">{o.employee.firstName} {o.employee.lastName}</p>
                    <p className="text-xs text-muted">{o.employee.designation} · started {formatDate(o.startedAt)}</p>
                  </div>
                </div>
                <div className="flex items-center gap-3 shrink-0">
                  <div className="w-32 h-1.5 rounded-full bg-[var(--color-border-soft)] overflow-hidden">
                    <div className="h-full bg-[var(--color-brand)]" style={{ width: `${pct}%` }} />
                  </div>
                  <span className="text-xs text-muted w-16 text-right">{done}/{o.tasks.length} tasks</span>
                  <StatusBadge status={o.status} />
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}

