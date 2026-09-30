import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { approvalQueueWhere, isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar, EmptyState } from "@/components/ui";
import { decideExpense } from "@/lib/actions/expenses";
import { SubmitExpenseForm } from "@/components/SubmitExpenseForm";
import { formatCurrency, formatDate } from "@/lib/format";
import { Receipt, Check, X } from "lucide-react";

export const dynamic = "force-dynamic";

const CATEGORIES = ["Travel", "Meals", "Software", "Training", "Client Entertainment", "Office Supplies", "Other"];

export default async function ExpensesPage() {
  const session = await requireModuleAccess("/dashboard/expenses");
  const managerial = isManagerial(session);
  const isFinance = session.role === "FINANCE";
  const canApprove = managerial || isFinance;

  const [myClaims, pending] = await Promise.all([
    session.employeeId
      ? prisma.expenseClaim.findMany({ where: { employeeId: session.employeeId }, orderBy: { submittedAt: "desc" } })
      : Promise.resolve([]),
    managerial
      ? prisma.expenseClaim.findMany({
          where: { ...(await approvalQueueWhere(session)), status: "PENDING" },
          include: { employee: true },
          orderBy: { submittedAt: "asc" },
        })
      : isFinance
      ? prisma.expenseClaim.findMany({
          where: { status: "PENDING", employee: { companyId: session.companyId ?? undefined } },
          include: { employee: true },
          orderBy: { submittedAt: "asc" },
        })
      : Promise.resolve([]),
  ]);

  async function decide(formData: FormData) {
    "use server";
    await decideExpense(String(formData.get("id")), formData.get("approve") === "1");
  }

  return (
    <div>
      <PageHeader title="Expenses" subtitle="Submit claims and track reimbursement status." />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Submit a claim</p>
          {session.employeeId ? (
            <SubmitExpenseForm categories={CATEGORIES} />
          ) : (
            <p className="text-sm text-muted">This account isn&rsquo;t linked to an employee record, so it can&rsquo;t submit expense claims.</p>
          )}
        </div>

        <div className="lg:col-span-2 space-y-5">
          {canApprove && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Awaiting your approval</p>
              {pending.length === 0 ? (
                <EmptyState icon={Receipt} title="Nothing pending" />
              ) : (
                <ul className="divide-y divide-border-soft">
                  {pending.map((c) => (
                    <li key={c.id} className="py-3 flex items-start justify-between gap-3">
                      <div className="flex items-start gap-2.5">
                        <Avatar firstName={c.employee.firstName} lastName={c.employee.lastName} color={c.employee.avatarColor} />
                        <div>
                          <p className="text-sm font-medium text-ink">{c.employee.firstName} {c.employee.lastName} · {formatCurrency(c.amount)}</p>
                          <p className="text-xs text-muted">{c.category} · {formatDate(c.submittedAt)}</p>
                          <p className="text-sm text-ink-soft mt-1">{c.description}</p>
                        </div>
                      </div>
                      <div className="flex gap-1.5 shrink-0">
                        <form action={decide}>
                          <input type="hidden" name="id" value={c.id} />
                          <input type="hidden" name="approve" value="1" />
                          <button className="btn btn-secondary !py-1.5 !px-2.5"><Check size={14} /></button>
                        </form>
                        <form action={decide}>
                          <input type="hidden" name="id" value={c.id} />
                          <input type="hidden" name="approve" value="0" />
                          <button className="btn btn-secondary !py-1.5 !px-2.5 text-danger"><X size={14} /></button>
                        </form>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3">My claims</p>
            <table className="table-clean">
              <thead><tr><th>Category</th><th>Amount</th><th>Submitted</th><th>Status</th></tr></thead>
              <tbody>
                {myClaims.map((c) => (
                  <tr key={c.id}>
                    <td>{c.category}</td>
                    <td>{formatCurrency(c.amount)}</td>
                    <td>{formatDate(c.submittedAt)}</td>
                    <td><StatusBadge status={c.status} /></td>
                  </tr>
                ))}
                {myClaims.length === 0 && <tr><td colSpan={4} className="text-center text-muted py-6">No claims submitted yet.</td></tr>}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
