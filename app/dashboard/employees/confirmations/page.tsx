import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { probationDueEmployees, raiseConfirmation, recommendConfirmation, finalizeConfirmation } from "@/lib/actions/confirmation";
import { formatDate } from "@/lib/format";
import { UserCheck2, Check, Clock } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function ConfirmationsPage() {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER"]);
  await requireModuleAccess("/dashboard/employees");
  const companyWide = session.role === "COMPANY_ADMIN" || session.role === "HR";

  const empIds = await visibleEmployeeIds(session);

  const [due, pending, recent] = await Promise.all([
    session.companyId ? probationDueEmployees(session.companyId, empIds) : Promise.resolve([]),
    prisma.confirmation.findMany({
      where: { employeeId: { in: empIds }, status: "PENDING" },
      include: { employee: true },
      orderBy: { createdAt: "asc" },
    }),
    prisma.confirmation.findMany({
      where: { employeeId: { in: empIds }, status: { not: "PENDING" } },
      include: { employee: true },
      orderBy: { hrActedAt: "desc" },
      take: 10,
    }),
  ]);

  async function raise(formData: FormData) {
    "use server";
    await raiseConfirmation(String(formData.get("employeeId")));
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/employees">Back to employees</BackLink>
      <PageHeader title="Confirmations" subtitle="Probation end-of-term review — manager recommendation, then HR sign-off." />

      {companyWide && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-3">Probation due</p>
          {due.length === 0 ? (
            <EmptyState icon={UserCheck2} title="Nobody due right now" />
          ) : (
            <ul className="divide-y divide-border-soft">
              {due.map((e) => (
                <li key={e.id} className="py-3 flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5">
                    <Avatar firstName={e.firstName} lastName={e.lastName} color={e.avatarColor} />
                    <span className="text-sm text-ink">{e.firstName} {e.lastName} <span className="text-muted">— joined {formatDate(e.dateOfJoining)}</span></span>
                  </div>
                  <form action={raise}>
                    <input type="hidden" name="employeeId" value={e.id} />
                    <button className="btn btn-secondary !py-1.5 !px-2.5 text-xs">Raise confirmation</button>
                  </form>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="card p-5 mb-5">
        <p className="text-sm font-semibold text-ink mb-3">Awaiting a decision</p>
        {pending.length === 0 ? (
          <EmptyState icon={Clock} title="Nothing pending" />
        ) : (
          <ul className="divide-y divide-border-soft">
            {pending.map((c) => {
              const isManager = session.employeeId === c.employee.managerId;
              return (
                <li key={c.id} className="py-3 space-y-2">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                      <Avatar firstName={c.employee.firstName} lastName={c.employee.lastName} color={c.employee.avatarColor} />
                      <span className="text-sm text-ink">{c.employee.firstName} {c.employee.lastName}</span>
                    </div>
                    <span className="text-xs text-muted">Due {formatDate(c.dueDate)}</span>
                  </div>

                  {c.managerRecommendation ? (
                    <p className="text-xs text-ink-soft pl-10">Manager recommendation: {c.managerRecommendation}</p>
                  ) : (
                    (isManager || companyWide) && (
                      <form action={recommendConfirmation} className="flex items-center gap-2 pl-10">
                        <input type="hidden" name="confirmationId" value={c.id} />
                        <select name="recommendation" className="input !py-1 text-xs !w-auto">
                          <option value="Confirm">Recommend: Confirm</option>
                          <option value="Extend probation">Recommend: Extend probation</option>
                        </select>
                        <button className="btn btn-secondary !py-1 !px-2.5 text-xs">Submit recommendation</button>
                      </form>
                    )
                  )}

                  {companyWide && c.managerRecommendation && (
                    <form action={finalizeConfirmation} className="flex flex-wrap items-center gap-2 pl-10">
                      <input type="hidden" name="confirmationId" value={c.id} />
                      <select name="decision" className="input !py-1 text-xs !w-auto">
                        <option value="CONFIRMED">Confirm employment</option>
                        <option value="EXTENDED">Extend probation</option>
                      </select>
                      <input type="date" name="extendedToDate" className="input !py-1 text-xs !w-auto" />
                      <button className="btn btn-primary !py-1 !px-2.5 text-xs"><Check size={12} /> Finalize</button>
                    </form>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <div className="card p-5">
        <p className="text-sm font-semibold text-ink mb-3">Recent decisions</p>
        {recent.length === 0 ? (
          <p className="text-sm text-muted py-4">No decided confirmations yet.</p>
        ) : (
          <table className="table-clean">
            <thead><tr><th>Employee</th><th>Due date</th><th>Status</th></tr></thead>
            <tbody>
              {recent.map((c) => (
                <tr key={c.id}>
                  <td>{c.employee.firstName} {c.employee.lastName}</td>
                  <td>{formatDate(c.dueDate)}</td>
                  <td><StatusBadge status={c.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
