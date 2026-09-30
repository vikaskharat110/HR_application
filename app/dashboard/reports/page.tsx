import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, StatCard } from "@/components/ui";
import { TrendLine } from "@/components/charts/TrendLine";
import { RankBar } from "@/components/charts/RankBar";
import { monthName } from "@/lib/format";
import { Download, Users, Wallet, CalendarClock, Briefcase } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function ReportsPage() {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER", "AUDITOR", "MANAGEMENT", "PAYROLL_ADMIN", "RECRUITER"]);
  await requireModuleAccess("/dashboard/reports");
  const canSeePayroll = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN", "FINANCE", "AUDITOR", "MANAGEMENT"].includes(session.role);
  const thirtyDaysAgo = new Date();
  thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

  const [departments, payrollRuns, leaveCounts, attendanceRows, jobStages, headcount] = await Promise.all([
    prisma.department.findMany({ where: { companyId: session.companyId ?? undefined }, include: { _count: { select: { employees: true } } } }),
    prisma.payrollRun.findMany({
      where: { companyId: session.companyId ?? undefined },
      include: { _count: { select: { payslips: true } }, payslips: { select: { netPay: true } } },
      orderBy: [{ year: "asc" }, { month: "asc" }],
      take: 6,
    }),
    prisma.leaveRequest.groupBy({ by: ["status"], where: { employee: { companyId: session.companyId ?? undefined } }, _count: true }),
    prisma.attendance.groupBy({
      by: ["date", "status"],
      where: { employee: { companyId: session.companyId ?? undefined }, date: { gte: thirtyDaysAgo } },
      _count: true,
    }),
    prisma.candidate.groupBy({ by: ["stage"], where: { jobPosting: { companyId: session.companyId ?? undefined } }, _count: true }),
    prisma.employee.count({ where: { companyId: session.companyId ?? undefined, status: "ACTIVE" } }),
  ]);

  const deptData = departments.map((d) => ({ department: d.name, headcount: d._count.employees })).sort((a, b) => b.headcount - a.headcount);

  const payrollData = payrollRuns.map((r) => ({
    period: `${monthName(r.month).slice(0, 3)} '${String(r.year).slice(2)}`,
    net: Math.round(r.payslips.reduce((s, p) => s + p.netPay, 0) / 1000),
  }));

  const byDate = new Map<string, number>();
  for (const row of attendanceRows) {
    if (row.status === "PRESENT" || row.status === "LATE") {
      const key = new Date(row.date).toLocaleDateString("en-IN", { day: "2-digit", month: "short" });
      byDate.set(key, (byDate.get(key) ?? 0) + row._count);
    }
  }
  const attendanceTrend = Array.from(byDate.entries()).map(([day, present]) => ({ day, present }));

  const leaveTotal = leaveCounts.reduce((s, r) => s + r._count, 0);
  const leaveByStatus = Object.fromEntries(leaveCounts.map((r) => [r.status, r._count]));
  const pipelineTotal = jobStages.reduce((s, r) => s + r._count, 0);

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle="Company-wide MIS across attendance, leave, payroll and hiring."
        action={
          <div className="flex gap-2">
            <a href="/api/reports/employees" className="btn btn-secondary"><Download size={15} /> Employees CSV</a>
            {canSeePayroll && (
              <a href="/api/reports/payroll" className="btn btn-secondary"><Download size={15} /> Latest payroll CSV</a>
            )}
          </div>
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <StatCard label="Active employees" value={headcount} icon={Users} tone="brand" />
        <StatCard label="Leave requests (all-time)" value={leaveTotal} icon={CalendarClock} tone="warning" hint={`${leaveByStatus.PENDING ?? 0} pending`} />
        <StatCard label="Payroll runs" value={payrollRuns.length} icon={Wallet} tone="info" />
        <StatCard label="Candidates in pipeline" value={pipelineTotal} icon={Briefcase} tone="accent" />
      </div>

      <div className="grid lg:grid-cols-2 gap-5 mb-5">
        <div className="card p-5">
          <p className="text-sm font-semibold text-ink mb-1">Attendance — last 30 days</p>
          <p className="text-xs text-muted mb-3">Present or late headcount per day, company-wide.</p>
          {attendanceTrend.length > 0 ? <TrendLine data={attendanceTrend} dataKey="present" xKey="day" /> : <p className="text-sm text-muted py-10 text-center">No data yet.</p>}
        </div>
        <div className="card p-5">
          <p className="text-sm font-semibold text-ink mb-1">Net payroll cost (₹ thousands)</p>
          <p className="text-xs text-muted mb-3">Across the last {payrollData.length} processed run(s).</p>
          {payrollData.length > 0 ? <TrendLine data={payrollData} dataKey="net" xKey="period" unit="k" /> : <p className="text-sm text-muted py-10 text-center">Run payroll to see this trend.</p>}
        </div>
      </div>

      <div className="card p-5">
        <p className="text-sm font-semibold text-ink mb-1">Headcount by department</p>
        <p className="text-xs text-muted mb-3">Active employees per department.</p>
        {deptData.length > 0 ? <RankBar data={deptData} dataKey="headcount" xKey="department" horizontal /> : <p className="text-sm text-muted py-10 text-center">No departments yet.</p>}
      </div>
    </div>
  );
}

