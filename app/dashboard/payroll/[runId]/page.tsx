import { notFound } from "next/navigation";
import { requireRole } from "@/lib/session";
import { requireModuleAccess, hasPayrollAction } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, Avatar, StatusBadge } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { formatCurrency, formatDate, monthName } from "@/lib/format";
import { approvePayrollRun, lockPayrollRun, reopenPayrollRun, setPayslipHold } from "@/lib/actions/payroll";
import { CheckCircle2, Lock, RotateCcw, Download, PauseCircle, PlayCircle } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function PayrollRunDetail({ params }: { params: Promise<{ runId: string }> }) {
  const { runId } = await params;
  const session = await requireRole(["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN", "FINANCE"]);
  await requireModuleAccess("/dashboard/payroll");

  const run = await prisma.payrollRun.findFirst({
    where: { id: runId, companyId: session.companyId ?? undefined },
    include: { payslips: { include: { employee: true }, orderBy: { employee: { firstName: "asc" } } } },
  });
  if (!run) notFound();

  const [canApprove, canLock] = await Promise.all([
    hasPayrollAction(session, "APPROVE"),
    hasPayrollAction(session, "LOCK"),
  ]);
  const isCompanyAdmin = session.role === "COMPANY_ADMIN";
  const canHold = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN"].includes(session.role);

  const totals = run.payslips.reduce(
    (acc, p) => ({
      gross: acc.gross + p.grossPay,
      deductions: acc.deductions + p.totalDeductions,
      net: acc.net + (p.isHeld ? 0 : p.netPay),
      held: acc.held + (p.isHeld ? p.netPay : 0),
    }),
    { gross: 0, deductions: 0, net: 0, held: 0 }
  );

  async function approve() {
    "use server";
    await approvePayrollRun(runId);
  }
  async function lock() {
    "use server";
    await lockPayrollRun(runId);
  }
  async function toggleHold(formData: FormData) {
    "use server";
    await setPayslipHold(formData);
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/payroll">Back to payroll</BackLink>
      <PageHeader
        title={`${monthName(run.month)} ${run.year} payroll`}
        subtitle={`${run.payslips.length} employees · Preview → Approval → Lock${run.payDate ? ` · Salary date ${formatDate(run.payDate)}` : ""}`}
        action={
          <div className="flex items-center gap-2">
            <StatusBadge status={run.status} />
            {run.status === "PROCESSED" && canApprove && (
              <form action={approve}><button className="btn btn-primary"><CheckCircle2 size={15} /> Approve</button></form>
            )}
            {run.status === "APPROVED" && canLock && (
              <form action={lock}><button className="btn btn-primary"><Lock size={15} /> Lock &amp; publish</button></form>
            )}
            {run.status === "LOCKED" && (
              <a href={`/api/reports/payroll?runId=${run.id}&format=bank-file`} className="btn btn-secondary">
                <Download size={15} /> Bank file
              </a>
            )}
          </div>
        }
      />

      {run.reopenReason && (
        <div className="card p-4 mb-5 text-sm text-warning bg-[var(--color-warning-soft)] border-none">
          Reopened — reason on record: “{run.reopenReason}”
        </div>
      )}

      <div className="grid sm:grid-cols-4 gap-4 mb-6">
        <div className="stat-card"><p className="text-xs font-semibold uppercase text-muted">Gross</p><p className="font-display text-xl font-semibold text-ink mt-1">{formatCurrency(totals.gross)}</p></div>
        <div className="stat-card"><p className="text-xs font-semibold uppercase text-muted">Deductions</p><p className="font-display text-xl font-semibold text-ink mt-1">{formatCurrency(totals.deductions)}</p></div>
        <div className="stat-card"><p className="text-xs font-semibold uppercase text-muted">Net paid</p><p className="font-display text-xl font-semibold text-ink mt-1">{formatCurrency(totals.net)}</p></div>
        <div className="stat-card"><p className="text-xs font-semibold uppercase text-muted">On hold</p><p className="font-display text-xl font-semibold text-danger mt-1">{formatCurrency(totals.held)}</p></div>
      </div>

      <div className="card overflow-x-auto mb-6">
        <table className="table-clean">
          <thead><tr><th>Employee</th><th>Gross</th><th>Deductions</th><th>LOP days</th><th>Net pay</th><th></th>{canHold && run.status !== "LOCKED" && <th></th>}</tr></thead>
          <tbody>
            {run.payslips.map((p) => (
              <tr key={p.id}>
                <td className="flex items-center gap-2.5 py-2.5">
                  <Avatar firstName={p.employee.firstName} lastName={p.employee.lastName} color={p.employee.avatarColor} />
                  {p.employee.firstName} {p.employee.lastName}
                  {p.isHeld && <span className="badge badge-danger" title={p.holdReason ?? undefined}>On hold</span>}
                </td>
                <td>{formatCurrency(p.grossPay)}</td>
                <td>{formatCurrency(p.totalDeductions)}</td>
                <td>{p.lopDays}</td>
                <td className="font-semibold text-ink">{formatCurrency(p.netPay)}</td>
                <td>
                  <a href={`/payslips/${p.id}`} target="_blank" rel="noreferrer" className="text-brand text-sm font-medium hover:underline">
                    Download
                  </a>
                </td>
                {canHold && run.status !== "LOCKED" && (
                  <td>
                    {p.isHeld ? (
                      <form action={toggleHold}>
                        <input type="hidden" name="payslipId" value={p.id} />
                        <input type="hidden" name="held" value="0" />
                        <button className="btn btn-secondary !py-1.5 !px-2.5 text-sm" title="Release hold">
                          <PlayCircle size={14} /> Release
                        </button>
                      </form>
                    ) : (
                      <details>
                        <summary className="btn btn-secondary !py-1.5 !px-2.5 text-sm cursor-pointer list-none inline-flex whitespace-nowrap">
                          <PauseCircle size={14} /> Hold
                        </summary>
                        <form action={toggleHold} className="mt-2 w-56 space-y-2">
                          <input type="hidden" name="payslipId" value={p.id} />
                          <input type="hidden" name="held" value="1" />
                          <input name="reason" required placeholder="Reason for hold" className="input text-sm" />
                          <button type="submit" className="btn btn-primary w-full justify-center text-sm">Confirm hold</button>
                        </form>
                      </details>
                    )}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {isCompanyAdmin && run.status === "LOCKED" && (
        <div className="card p-5">
          <p className="text-sm font-semibold text-ink mb-1">Reopen this run</p>
          <p className="text-xs text-muted mb-3">
            A locked run can only be changed after it's reopened — the reason is recorded in the audit trail.
          </p>
          <form action={reopenPayrollRun} className="flex flex-wrap items-end gap-3">
            <input type="hidden" name="runId" value={run.id} />
            <div className="flex-1 min-w-[240px]">
              <label className="field-label">Reason</label>
              <input name="reason" required className="input" placeholder="e.g. LOP correction for Rohan Verma" />
            </div>
            <button type="submit" className="btn btn-secondary"><RotateCcw size={15} /> Reopen</button>
          </form>
        </div>
      )}

      <p className="text-xs text-muted mt-4">
        Processed {formatDate(run.processedAt)} {run.approvedAt && `· Approved ${formatDate(run.approvedAt)}`} {run.lockedAt && `· Locked ${formatDate(run.lockedAt)}`}
      </p>
    </div>
  );
}
