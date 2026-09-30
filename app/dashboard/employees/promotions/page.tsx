import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { approvalQueueWhere } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { decidePromotionTransfer } from "@/lib/actions/promotions";
import { formatDate, formatCurrency } from "@/lib/format";
import { Check, X, TrendingUp } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function PromotionsPage() {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER"]);
  await requireModuleAccess("/dashboard/employees");

  const [pending, recent] = await Promise.all([
    prisma.promotionTransfer.findMany({
      where: { ...(await approvalQueueWhere(session)), status: "PENDING" },
      include: { employee: true },
      orderBy: { createdAt: "asc" },
    }),
    prisma.promotionTransfer.findMany({
      where: { ...(await approvalQueueWhere(session)), status: { not: "PENDING" } },
      include: { employee: true },
      orderBy: { actedAt: "desc" },
      take: 10,
    }),
  ]);

  async function decide(formData: FormData) {
    "use server";
    await decidePromotionTransfer(String(formData.get("id")), formData.get("approve") === "1");
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/employees">Back to employees</BackLink>
      <PageHeader title="Promotions & Transfers" subtitle="Requests awaiting your decision, and recent history." />

      <div className="card p-5 mb-5">
        <p className="text-sm font-semibold text-ink mb-3">Awaiting your decision</p>
        {pending.length === 0 ? (
          <EmptyState icon={TrendingUp} title="Nothing pending" />
        ) : (
          <ul className="divide-y divide-border-soft">
            {pending.map((r) => (
              <li key={r.id} className="py-4 flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                  <Avatar firstName={r.employee.firstName} lastName={r.employee.lastName} color={r.employee.avatarColor} />
                  <div>
                    <p className="text-sm font-medium text-ink">
                      {r.employee.firstName} {r.employee.lastName} <StatusBadge status={r.type} />
                    </p>
                    <p className="text-xs text-muted mt-0.5">Effective {formatDate(r.effectiveDate)}</p>
                    <ul className="text-sm text-ink-soft mt-1.5 space-y-0.5">
                      {r.newDesignation && <li>New designation: {r.newDesignation}</li>}
                      {r.newDepartmentId && <li>New department assigned</li>}
                      {r.newBranchId && <li>New branch assigned</li>}
                      {r.newManagerId && <li>New manager assigned</li>}
                      {r.newBasic != null && <li>New basic salary: {formatCurrency(r.newBasic)}</li>}
                    </ul>
                    <p className="text-sm text-ink-soft mt-1.5">{r.reason}</p>
                  </div>
                </div>
                <div className="flex gap-1.5 shrink-0">
                  <form action={decide}>
                    <input type="hidden" name="id" value={r.id} />
                    <input type="hidden" name="approve" value="1" />
                    <button className="btn btn-secondary !py-1.5 !px-2.5"><Check size={14} /> Approve</button>
                  </form>
                  <form action={decide}>
                    <input type="hidden" name="id" value={r.id} />
                    <input type="hidden" name="approve" value="0" />
                    <button className="btn btn-secondary !py-1.5 !px-2.5 text-danger"><X size={14} /> Reject</button>
                  </form>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="card p-5">
        <p className="text-sm font-semibold text-ink mb-3">Recent decisions</p>
        {recent.length === 0 ? (
          <p className="text-sm text-muted py-4">No decided requests yet.</p>
        ) : (
          <table className="table-clean">
            <thead><tr><th>Employee</th><th>Type</th><th>Effective</th><th>Status</th></tr></thead>
            <tbody>
              {recent.map((r) => (
                <tr key={r.id}>
                  <td>{r.employee.firstName} {r.employee.lastName}</td>
                  <td>{r.type}</td>
                  <td>{formatDate(r.effectiveDate)}</td>
                  <td><StatusBadge status={r.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
