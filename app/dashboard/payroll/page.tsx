import Link from "next/link";
import { requireModuleAccess, hasPayrollAction } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, EmptyState, Pagination } from "@/components/ui";
import { RunPayrollButton } from "@/components/RunPayrollButton";
import { formatCurrency, formatDate, monthName } from "@/lib/format";
import { Wallet, Settings2 } from "lucide-react";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 12;

export default async function PayrollPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string }>;
}) {
  const session = await requireModuleAccess("/dashboard/payroll");
  const canView = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN", "FINANCE"].includes(session.role);
  const canProcess = await hasPayrollAction(session, "PROCESS");
  const canEditSalary = await hasPayrollAction(session, "EDIT_SALARY");
  const managerial = isManagerial(session) || session.role === "PAYROLL_ADMIN" || session.role === "FINANCE";
  const { page: pageParam } = await searchParams;
  const page = Math.max(1, Number(pageParam) || 1);

  const now = new Date();
  const runsWhere = { companyId: session.companyId ?? undefined };

  const [runTotal, runs, myPayslips] = await Promise.all([
    canView ? prisma.payrollRun.count({ where: runsWhere }) : Promise.resolve(0),
    canView
      ? prisma.payrollRun.findMany({
          where: runsWhere,
          include: { _count: { select: { payslips: true } } },
          orderBy: [{ year: "desc" }, { month: "desc" }],
          take: PAGE_SIZE,
          skip: (page - 1) * PAGE_SIZE,
        })
      : Promise.resolve([]),
    session.employeeId
      ? prisma.payslip.findMany({
          where: { employeeId: session.employeeId, payrollRun: { status: "LOCKED" } },
          include: { payrollRun: true },
          orderBy: { generatedAt: "desc" },
        })
      : Promise.resolve([]),
  ]);
  const totalPages = Math.max(1, Math.ceil(runTotal / PAGE_SIZE));

  return (
    <div>
      <PageHeader
        title="Payroll"
        subtitle={canView ? "Run monthly payroll and manage salary structures." : "Your payslip history."}
        action={
          canView && (
            <div className="flex gap-2">
              {canEditSalary && (
                <Link href="/dashboard/payroll/salary" className="btn btn-secondary"><Settings2 size={15} /> Salary structures</Link>
              )}
              {canProcess && (
                <RunPayrollButton month={now.getMonth() + 1} year={now.getFullYear()} label={`Run payroll — ${monthName(now.getMonth() + 1)}`} />
              )}
            </div>
          )
        }
      />

      {canView && (
        <div className="mb-6">
          <div className="card overflow-x-auto">
            {runs.length === 0 ? (
              <EmptyState icon={Wallet} title="No payroll runs yet" message="Run payroll for the current month to get started." />
            ) : (
              <table className="table-clean">
                <thead><tr><th>Period</th><th>Employees paid</th><th>Status</th><th>Salary date</th><th>Processed</th><th></th></tr></thead>
                <tbody>
                  {runs.map((r) => (
                    <tr key={r.id}>
                      <td className="font-medium text-ink">{monthName(r.month)} {r.year}</td>
                      <td>{r._count.payslips}</td>
                      <td><StatusBadge status={r.status} /></td>
                      <td>{r.payDate ? formatDate(r.payDate) : "—"}</td>
                      <td>{formatDate(r.processedAt)}</td>
                      <td><Link href={`/dashboard/payroll/${r.id}`} className="text-brand text-sm font-medium hover:underline">View payslips</Link></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
          <Pagination page={page} totalPages={totalPages} basePath="/dashboard/payroll" />
        </div>
      )}

      {!managerial && (
        <div className="card overflow-x-auto">
          <p className="px-5 pt-5 text-sm font-semibold text-ink">My payslips</p>
          {myPayslips.length === 0 ? (
            <EmptyState icon={Wallet} title="No payslips yet" message="Your payslips will appear here once payroll is processed." />
          ) : (
            <table className="table-clean mt-2">
              <thead><tr><th>Period</th><th>Gross</th><th>Deductions</th><th>Net pay</th><th>Paid days</th><th></th></tr></thead>
              <tbody>
                {myPayslips.map((p) => (
                  <tr key={p.id}>
                    <td className="font-medium text-ink">{monthName(p.payrollRun.month)} {p.payrollRun.year}</td>
                    <td>{formatCurrency(p.grossPay)}</td>
                    <td>{formatCurrency(p.totalDeductions)}</td>
                    <td className="font-semibold text-ink">
                      {formatCurrency(p.netPay)}
                      {p.isHeld && <span className="badge badge-danger ml-2">On hold</span>}
                    </td>
                    <td>{p.paidDays} / {p.workingDays}</td>
                    <td>
                      <a href={`/payslips/${p.id}`} target="_blank" rel="noreferrer" className="text-brand text-sm font-medium hover:underline">
                        Download
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
