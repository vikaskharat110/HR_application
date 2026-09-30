import Link from "next/link";
import { notFound } from "next/navigation";
import { requireRole, ROLE_LABEL } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { toggleCompanyUser, updateCompanyStatus } from "@/lib/actions/companies";
import { PageHeader, StatusBadge, EmptyState, Pagination } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { AutoSubmitSelect } from "@/components/AutoSubmitSelect";
import { formatDate, formatDateTime } from "@/lib/format";
import { Building2, Users, MapPin, Layers, History } from "lucide-react";
import type { CompanyStatus } from "@prisma/client";

export const dynamic = "force-dynamic";

const COMPANY_STATUSES: CompanyStatus[] = ["ACTIVE", "SUSPENDED", "ARCHIVED"];
const PAGE_SIZE = 10;

const TABS = [
  { key: "users", label: "Users", icon: Users },
  { key: "activity", label: "Recent activity", icon: History },
  { key: "branches", label: "Branches", icon: MapPin },
  { key: "departments", label: "Departments", icon: Layers },
  { key: "policy", label: "Policy", icon: Building2 },
] as const;
type TabKey = (typeof TABS)[number]["key"];

export default async function CompanyDetailPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ tab?: string; page?: string }>;
}) {
  const { id } = await params;
  await requireRole(["SUPER_ADMIN"]);
  const { tab, page: pageParam } = await searchParams;
  const activeTab: TabKey = TABS.some((t) => t.key === tab) ? (tab as TabKey) : "users";
  const page = Math.max(1, Number(pageParam) || 1);

  const company = await prisma.company.findFirst({
    where: { id },
    include: {
      _count: { select: { employees: true, users: true, branches: true, departments: true } },
    },
  });
  if (!company) notFound();

  const [usersResult, activityResult, branches, departments, policy] = await Promise.all([
    activeTab === "users"
      ? Promise.all([
          prisma.user.count({ where: { companyId: id } }),
          prisma.user.findMany({
            where: { companyId: id },
            include: { customRole: true },
            orderBy: { createdAt: "asc" },
            take: PAGE_SIZE,
            skip: (page - 1) * PAGE_SIZE,
          }),
        ])
      : null,
    activeTab === "activity"
      ? Promise.all([
          prisma.auditLog.count({ where: { companyId: id } }),
          prisma.auditLog.findMany({
            where: { companyId: id },
            include: { actor: true },
            orderBy: { createdAt: "desc" },
            take: PAGE_SIZE,
            skip: (page - 1) * PAGE_SIZE,
          }),
        ])
      : null,
    activeTab === "branches" ? prisma.branch.findMany({ where: { companyId: id }, orderBy: { name: "asc" } }) : Promise.resolve([]),
    activeTab === "departments" ? prisma.department.findMany({ where: { companyId: id }, orderBy: { name: "asc" } }) : Promise.resolve([]),
    activeTab === "policy" ? prisma.companyPolicy.findUnique({ where: { companyId: id } }) : Promise.resolve(null),
  ]);
  const [usersTotal, users] = usersResult ?? [0, []];
  const [activityTotal, activity] = activityResult ?? [0, []];
  const usersTotalPages = Math.max(1, Math.ceil(usersTotal / PAGE_SIZE));
  const activityTotalPages = Math.max(1, Math.ceil(activityTotal / PAGE_SIZE));

  async function toggle(formData: FormData) {
    "use server";
    await toggleCompanyUser(String(formData.get("userId")), formData.get("active") === "1");
  }

  async function setStatus(formData: FormData) {
    "use server";
    await updateCompanyStatus(id, formData.get("status") as CompanyStatus);
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/companies">Back to companies</BackLink>

      <PageHeader
        title={company.name}
        subtitle={`${company.industry ?? "—"} · ${company.slug} · created ${formatDate(company.createdAt)}`}
        action={
          <div className="flex items-center gap-2">
            <StatusBadge status={company.status} />
            <form action={setStatus}>
              <AutoSubmitSelect name="status" defaultValue={company.status} options={COMPANY_STATUSES} className="input w-auto! text-sm" />
            </form>
          </div>
        }
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="stat-card">
          <p className="text-xs font-semibold uppercase text-muted">Employees</p>
          <p className="font-display text-2xl font-semibold text-ink mt-1.5">{company._count.employees}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs font-semibold uppercase text-muted">Logins</p>
          <p className="font-display text-2xl font-semibold text-ink mt-1.5">{company._count.users}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs font-semibold uppercase text-muted">Branches</p>
          <p className="font-display text-2xl font-semibold text-ink mt-1.5">{company._count.branches}</p>
        </div>
        <div className="stat-card">
          <p className="text-xs font-semibold uppercase text-muted">Departments</p>
          <p className="font-display text-2xl font-semibold text-ink mt-1.5">{company._count.departments}</p>
        </div>
      </div>

      <div className="inline-flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1 mb-5">
        {TABS.map((t) => {
          const Icon = t.icon;
          const active = activeTab === t.key;
          return (
            <Link
              key={t.key}
              href={`/dashboard/companies/${id}?tab=${t.key}`}
              className={`flex items-center gap-1.5 px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors ${
                active ? "bg-brand text-white" : "text-ink-soft hover:text-ink hover:bg-brand-softer"
              }`}
            >
              <Icon size={14} /> {t.label}
            </Link>
          );
        })}
      </div>

      <div className="card p-5">
        {activeTab === "users" && (
          <>
            {users.length === 0 ? (
              <EmptyState icon={Users} title="No logins created for this company yet." />
            ) : (
              <div className="overflow-x-auto">
                <table className="table-clean">
                  <thead><tr><th>Email</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    {users.map((u) => (
                      <tr key={u.id}>
                        <td className="text-ink whitespace-nowrap">{u.email}</td>
                        <td className="whitespace-nowrap">{u.customRole?.name ?? ROLE_LABEL[u.role]}</td>
                        <td className="whitespace-nowrap">{u.lastLoginAt ? formatDateTime(u.lastLoginAt) : "Never"}</td>
                        <td className="whitespace-nowrap">{u.isActive ? "Active" : "Disabled"}</td>
                        <td>
                          <form action={toggle}>
                            <input type="hidden" name="userId" value={u.id} />
                            <input type="hidden" name="active" value={u.isActive ? "0" : "1"} />
                            <button className="text-sm font-medium text-brand hover:underline whitespace-nowrap">
                              {u.isActive ? "Disable" : "Enable"}
                            </button>
                          </form>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <Pagination page={page} totalPages={usersTotalPages} basePath={`/dashboard/companies/${id}`} query={{ tab: "users" }} />
          </>
        )}

        {activeTab === "activity" && (
          <>
            {activity.length === 0 ? (
              <EmptyState icon={History} title="No audited actions recorded for this company yet." />
            ) : (
              <ul className="divide-y divide-border-soft">
                {activity.map((log) => (
                  <li key={log.id} className="py-2.5 text-sm flex items-center justify-between gap-3">
                    <span className="text-ink-soft">
                      <span className="font-medium text-ink">{log.actor?.email ?? "system"}</span>
                      {" "}{log.action.toLowerCase()} {log.entityType}
                    </span>
                    <span className="text-xs text-muted shrink-0">{formatDateTime(log.createdAt)}</span>
                  </li>
                ))}
              </ul>
            )}
            <Pagination page={page} totalPages={activityTotalPages} basePath={`/dashboard/companies/${id}`} query={{ tab: "activity" }} />
          </>
        )}

        {activeTab === "branches" && (
          branches.length === 0 ? (
            <EmptyState icon={MapPin} title="No branches yet." />
          ) : (
            <ul className="grid gap-3 grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
              {branches.map((b) => (
                <li key={b.id} className="flex items-center justify-between rounded-lg border border-border-soft px-4 py-3 text-sm">
                  <span className="text-ink-soft">{b.name} — {b.city}</span>
                  {b.isHQ && <span className="badge badge-info">HQ</span>}
                </li>
              ))}
            </ul>
          )
        )}

        {activeTab === "departments" && (
          departments.length === 0 ? (
            <EmptyState icon={Layers} title="No departments yet." />
          ) : (
            <ul className="grid gap-3 grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
              {departments.map((d) => (
                <li key={d.id} className="rounded-lg border border-border-soft px-4 py-3 text-sm text-ink-soft">{d.name}</li>
              ))}
            </ul>
          )
        )}

        {activeTab === "policy" && (
          policy ? (
            <div className="max-w-md">
              <ul className="space-y-1.5 text-sm">
                <li className="flex justify-between"><span className="text-muted">Work week</span><span className="text-ink">{policy.workWeek}</span></li>
                <li className="flex justify-between"><span className="text-muted">Check-in grace</span><span className="text-ink">{policy.checkInGraceMins} min</span></li>
                <li className="flex justify-between"><span className="text-muted">Casual leave</span><span className="text-ink">{policy.casualLeaveDays}/yr</span></li>
                <li className="flex justify-between"><span className="text-muted">Sick leave</span><span className="text-ink">{policy.sickLeaveDays}/yr</span></li>
                <li className="flex justify-between"><span className="text-muted">Earned leave</span><span className="text-ink">{policy.earnedLeaveDays}/yr</span></li>
                <li className="flex justify-between"><span className="text-muted">Probation</span><span className="text-ink">{policy.probationMonths} mo</span></li>
                <li className="flex justify-between"><span className="text-muted">Notice period</span><span className="text-ink">{policy.noticePeriodDays} days</span></li>
              </ul>
              <p className="text-xs text-muted mt-3">Edited by the Company Admin under their own Settings — read-only here.</p>
            </div>
          ) : (
            <EmptyState icon={Building2} title="No policy configured yet." />
          )
        )}
      </div>
    </div>
  );
}
