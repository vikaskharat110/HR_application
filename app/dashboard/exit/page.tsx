import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge, EmptyState } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { LogOut, Plus } from "lucide-react";
import type { ExitDepartment } from "@prisma/client";

export const dynamic = "force-dynamic";

export default async function ExitListPage() {
  const session = await requireModuleAccess("/dashboard/exit");

  const companyWide = ["COMPANY_ADMIN", "HR"].includes(session.role);
  const queueDepartment: ExitDepartment | null = session.role === "IT_ADMIN" ? "IT" : session.role === "FINANCE" ? "FINANCE" : null;

  const where = companyWide
    ? { employee: { companyId: session.companyId ?? undefined } }
    : queueDepartment
    ? { employee: { companyId: session.companyId ?? undefined }, clearances: { some: { department: queueDepartment } } }
    : { employeeId: { in: await visibleEmployeeIds(session) } };

  const exits = await prisma.employeeExit.findMany({
    where,
    include: { employee: true, clearances: true },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div>
      <PageHeader
        title="Exit / Offboarding"
        subtitle="Resignation, notice period, department clearance and Full &amp; Final settlement."
        action={<Link href="/dashboard/exit/new" className="btn btn-primary"><Plus size={15} /> Initiate resignation</Link>}
      />

      {exits.length === 0 ? (
        <div className="card"><EmptyState icon={LogOut} title="No exits in progress" /></div>
      ) : (
        <div className="card divide-y divide-border-soft">
          {exits.map((e) => {
            const cleared = e.clearances.filter((c) => c.status === "CLEARED").length;
            return (
              <Link key={e.id} href={`/dashboard/exit/${e.id}`} className="flex items-center justify-between gap-4 px-5 py-4 hover:bg-[var(--color-brand-softer)] transition-colors">
                <div className="flex items-center gap-3 min-w-0">
                  <Avatar firstName={e.employee.firstName} lastName={e.employee.lastName} color={e.employee.avatarColor} />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-ink truncate">{e.employee.firstName} {e.employee.lastName}</p>
                    <p className="text-xs text-muted">Resigned {formatDate(e.resignationDate)}{e.lastWorkingDate && ` · last day ${formatDate(e.lastWorkingDate)}`}</p>
                  </div>
                </div>
                <div className="flex items-center gap-3 shrink-0">
                  {e.clearances.length > 0 && <span className="text-xs text-muted">{cleared}/{e.clearances.length} cleared</span>}
                  <StatusBadge status={e.status} />
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}

