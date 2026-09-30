import Link from "next/link";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds, isManagerial } from "@/lib/scope";
import { PageHeader, StatCard, StatusBadge, Avatar } from "@/components/ui";
import { TrendLine } from "@/components/charts/TrendLine";
import { RankBar } from "@/components/charts/RankBar";
import { formatDate } from "@/lib/format";
import {
  Users, UserCheck, CalendarClock, Receipt, Building2, ArrowRight,
  Fingerprint, Wallet, LifeBuoy, CheckCircle2, LogOut, Cake, Award,
} from "lucide-react";

export const dynamic = "force-dynamic";

export default async function DashboardHome() {
  const session = await requireSession();

  if (session.role === "SUPER_ADMIN") return <SuperAdminDashboard />;

  const empIds = await visibleEmployeeIds(session);
  const managerial = isManagerial(session);
  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);
  const fourteenDaysAgo = new Date();
  fourteenDaysAgo.setDate(fourteenDaysAgo.getDate() - 14);

  const [
    headcount,
    presentToday,
    pendingLeave,
    pendingExpense,
    openTickets,
    myBalances,
    leaveTrendRaw,
    departmentRows,
    upcomingHolidays,
    approvalsQueue,
    eventEmployees,
  ] = await Promise.all([
    prisma.employee.count({ where: { id: { in: empIds }, status: "ACTIVE" } }),
    prisma.attendance.count({ where: { employeeId: { in: empIds }, date: { gte: startOfToday }, status: { in: ["PRESENT", "LATE"] } } }),
    prisma.leaveRequest.count({ where: { employeeId: { in: empIds }, status: "PENDING" } }),
    prisma.expenseClaim.count({ where: { employeeId: { in: empIds }, status: "PENDING" } }),
    prisma.ticket.count({ where: { raisedById: { in: empIds }, status: { in: ["OPEN", "IN_PROGRESS"] } } }),
    session.employeeId
      ? prisma.leaveBalance.findMany({
          where: { employeeId: session.employeeId, year: new Date().getFullYear() },
          include: { leaveType: true },
        })
      : Promise.resolve([]),
    prisma.attendance.groupBy({
      by: ["date", "status"],
      where: { employeeId: { in: empIds }, date: { gte: fourteenDaysAgo } },
      _count: true,
    }),
    managerial
      ? prisma.department.findMany({
          where: { companyId: session.companyId ?? undefined },
          include: { _count: { select: { employees: true } } },
        })
      : Promise.resolve([]),
    prisma.holiday.findMany({
      where: { companyId: session.companyId ?? undefined, date: { gte: startOfToday } },
      orderBy: { date: "asc" },
      take: 3,
    }),
    managerial
      ? prisma.leaveRequest.findMany({
          where: { employeeId: { in: empIds }, status: "PENDING" },
          include: { employee: true, leaveType: true },
          orderBy: { appliedAt: "desc" },
          take: 5,
        })
      : Promise.resolve([]),
    // Birthdays/anniversaries: HR & Company Admin see the whole company, Manager sees their
    // team — the exact same scope `empIds` already resolves for everything else on this page.
    managerial
      ? prisma.employee.findMany({
          where: { id: { in: empIds }, status: "ACTIVE" },
          select: { id: true, firstName: true, lastName: true, avatarColor: true, dateOfBirth: true, dateOfJoining: true },
        })
      : Promise.resolve([]),
  ]);

  // Build a 14-day present-count trend from the grouped rows
  const byDate = new Map<string, number>();
  for (const row of leaveTrendRaw) {
    if (row.status === "PRESENT" || row.status === "LATE") {
      const key = new Date(row.date).toLocaleDateString("en-IN", { day: "2-digit", month: "short" });
      byDate.set(key, (byDate.get(key) ?? 0) + row._count);
    }
  }
  const trendData = Array.from(byDate.entries()).map(([day, present]) => ({ day, present }));

  const deptData = departmentRows
    .map((d) => ({ department: d.name, headcount: d._count.employees }))
    .sort((a, b) => b.headcount - a.headcount);

  // Month match only — the year is irrelevant for "does this fall in the current month".
  const thisMonth = new Date().getMonth();
  const thisYear = new Date().getFullYear();
  const birthdaysThisMonth = eventEmployees
    .filter((e) => e.dateOfBirth && new Date(e.dateOfBirth).getMonth() === thisMonth)
    .sort((a, b) => new Date(a.dateOfBirth!).getDate() - new Date(b.dateOfBirth!).getDate());
  const anniversariesThisMonth = eventEmployees
    .filter((e) => new Date(e.dateOfJoining).getMonth() === thisMonth)
    .map((e) => ({ ...e, years: thisYear - new Date(e.dateOfJoining).getFullYear() }))
    .filter((e) => e.years >= 1)
    .sort((a, b) => new Date(a.dateOfJoining).getDate() - new Date(b.dateOfJoining).getDate());

  return (
    <div>
      <PageHeader
        title={`Welcome back, ${session.name.split(" ")[0]}`}
        subtitle="Here's what's moving across your organisation today."
      />

      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <StatCard label={managerial ? "Active employees" : "Team size"} value={headcount} icon={Users} tone="brand" />
        <StatCard label="Present today" value={presentToday} icon={UserCheck} tone="info" hint={`of ${headcount} expected`} />
        <StatCard label="Pending leave" value={pendingLeave} icon={CalendarClock} tone="warning" />
        <StatCard label="Pending expenses" value={pendingExpense} icon={Receipt} tone="accent" />
        <StatCard label="Open tickets" value={openTickets} icon={LifeBuoy} tone="danger" />
      </div>

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 space-y-5">
          <div className="card p-5">
            <p className="text-sm font-semibold text-[var(--color-ink)] mb-1">Attendance — last 14 days</p>
            <p className="text-xs text-[var(--color-muted)] mb-3">Employees marked present or late, company-wide.</p>
            {trendData.length > 0 ? (
              <TrendLine data={trendData} dataKey="present" xKey="day" />
            ) : (
              <p className="text-sm text-[var(--color-muted)] py-10 text-center">No attendance recorded yet.</p>
            )}
          </div>

          {managerial && deptData.length > 0 && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-[var(--color-ink)] mb-1">Headcount by department</p>
              <p className="text-xs text-[var(--color-muted)] mb-3">Active employees per department.</p>
              <RankBar data={deptData} dataKey="headcount" xKey="department" horizontal />
            </div>
          )}

          {!managerial && (
            <div className="card p-5">
              <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-semibold text-[var(--color-ink)]">My leave balance — {new Date().getFullYear()}</p>
                <Link href="/dashboard/leave" className="text-xs font-medium text-[var(--color-brand)] flex items-center gap-1 hover:underline">
                  Apply for leave <ArrowRight size={12} />
                </Link>
              </div>
              <div className="grid sm:grid-cols-3 gap-3">
                {myBalances.map((b) => (
                  <div key={b.id} className="rounded-lg border border-[var(--color-border)] px-4 py-3">
                    <p className="text-xs font-medium text-[var(--color-muted)]">{b.leaveType.name}</p>
                    <p className="font-display text-xl font-semibold text-[var(--color-ink)] mt-1">
                      {b.allocated - b.used}
                      <span className="text-sm font-normal text-[var(--color-muted)]"> / {b.allocated} days</span>
                    </p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="space-y-5">
          {managerial && (
            <div className="card p-5">
              <div className="flex items-center justify-between mb-3">
                <p className="text-sm font-semibold text-[var(--color-ink)]">Awaiting your approval</p>
                <Link href="/dashboard/leave/approvals" className="text-xs font-medium text-[var(--color-brand)] hover:underline">
                  View all
                </Link>
              </div>
              {approvalsQueue.length === 0 ? (
                <p className="text-sm text-[var(--color-muted)] py-4">Nothing pending. Nice and clear.</p>
              ) : (
                <ul className="space-y-3">
                  {approvalsQueue.map((r) => (
                    <li key={r.id} className="flex items-center gap-3">
                      <Avatar firstName={r.employee.firstName} lastName={r.employee.lastName} color={r.employee.avatarColor} />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-[var(--color-ink)] truncate">
                          {r.employee.firstName} {r.employee.lastName}
                        </p>
                        <p className="text-xs text-[var(--color-muted)]">
                          {r.leaveType.name} · {r.days}d from {formatDate(r.startDate)}
                        </p>
                      </div>
                      <StatusBadge status={r.status} />
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-[var(--color-ink)] mb-3">Quick actions</p>
            <div className="grid grid-cols-2 gap-2.5">
              <QuickLink href="/dashboard/attendance" icon={Fingerprint} label="Check in/out" />
              <QuickLink href="/dashboard/leave" icon={CalendarClock} label="Apply leave" />
              <QuickLink href="/dashboard/payroll" icon={Wallet} label="Payslips" />
              <QuickLink href="/dashboard/helpdesk" icon={LifeBuoy} label="Raise ticket" />
              <QuickLink href="/dashboard/exit/new" icon={LogOut} label="Resign" />
            </div>
          </div>

          {managerial && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-[var(--color-ink)] mb-3">This month</p>
              {birthdaysThisMonth.length === 0 && anniversariesThisMonth.length === 0 ? (
                <p className="text-sm text-[var(--color-muted)]">No birthdays or work anniversaries this month.</p>
              ) : (
                <div className="space-y-4">
                  {birthdaysThisMonth.length > 0 && (
                    <div>
                      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)] mb-2">
                        <Cake size={13} /> Birthdays
                      </p>
                      <ul className="space-y-2.5">
                        {birthdaysThisMonth.map((e) => (
                          <li key={e.id} className="flex items-center gap-2.5 text-sm">
                            <Avatar firstName={e.firstName} lastName={e.lastName} color={e.avatarColor} />
                            <span className="flex-1 min-w-0 truncate text-[var(--color-ink)]">{e.firstName} {e.lastName}</span>
                            <span className="text-xs text-[var(--color-muted)] shrink-0">
                              {new Date(e.dateOfBirth!).toLocaleDateString("en-IN", { day: "2-digit", month: "short" })}
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {anniversariesThisMonth.length > 0 && (
                    <div>
                      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)] mb-2">
                        <Award size={13} /> Work anniversaries
                      </p>
                      <ul className="space-y-2.5">
                        {anniversariesThisMonth.map((e) => (
                          <li key={e.id} className="flex items-center gap-2.5 text-sm">
                            <Avatar firstName={e.firstName} lastName={e.lastName} color={e.avatarColor} />
                            <span className="flex-1 min-w-0 truncate text-[var(--color-ink)]">{e.firstName} {e.lastName}</span>
                            <span className="text-xs text-[var(--color-muted)] shrink-0">
                              {e.years} yr{e.years === 1 ? "" : "s"} · {new Date(e.dateOfJoining).toLocaleDateString("en-IN", { day: "2-digit", month: "short" })}
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-[var(--color-ink)] mb-3">Upcoming holidays</p>
            {upcomingHolidays.length === 0 ? (
              <p className="text-sm text-[var(--color-muted)]">None scheduled.</p>
            ) : (
              <ul className="space-y-2.5">
                {upcomingHolidays.map((h) => (
                  <li key={h.id} className="flex items-center justify-between text-sm">
                    <span className="text-[var(--color-ink)]">{h.name}</span>
                    <span className="text-[var(--color-muted)]">{formatDate(h.date)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function QuickLink({ href, icon: Icon, label }: { href: string; icon: typeof Fingerprint; label: string }) {
  return (
    <Link
      href={href}
      className="flex flex-col items-start gap-2 rounded-lg border border-[var(--color-border)] px-3.5 py-3 hover:border-[var(--color-brand-soft)] hover:bg-[var(--color-brand-softer)] transition-colors"
    >
      <Icon size={17} className="text-[var(--color-brand)]" />
      <span className="text-xs font-medium text-[var(--color-ink)]">{label}</span>
    </Link>
  );
}

async function SuperAdminDashboard() {
  const [companies, totalUsers, totalEmployees, recentCompanies] = await Promise.all([
    prisma.company.count(),
    prisma.user.count(),
    prisma.employee.count(),
    prisma.company.findMany({
      orderBy: { createdAt: "desc" },
      take: 6,
      include: { _count: { select: { employees: true, users: true } } },
    }),
  ]);

  return (
    <div>
      <PageHeader
        title="Platform overview"
        subtitle="Every tenant on Verdant HR, at a glance."
        action={
          <Link href="/dashboard/companies" className="btn btn-primary">
            <Building2 size={15} /> Manage companies
          </Link>
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <StatCard label="Companies" value={companies} icon={Building2} tone="brand" />
        <StatCard label="Total users" value={totalUsers} icon={Users} tone="info" />
        <StatCard label="Total employees" value={totalEmployees} icon={CheckCircle2} tone="accent" />
      </div>

      <div className="card overflow-x-auto">
        <table className="table-clean">
          <thead>
            <tr>
              <th>Company</th>
              <th>Industry</th>
              <th>Users</th>
              <th>Employees</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            {recentCompanies.map((c) => (
              <tr key={c.id}>
                <td className="font-medium text-[var(--color-ink)]">{c.name}</td>
                <td>{c.industry ?? "—"}</td>
                <td>{c._count.users}</td>
                <td>{c._count.employees}</td>
                <td>{formatDate(c.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
