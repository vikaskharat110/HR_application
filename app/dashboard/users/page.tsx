import { Fragment } from "react";
import Link from "next/link";
import { requireRole, ROLE_LABEL } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { toggleCompanyUser } from "@/lib/actions/companies";
import { PageHeader, EmptyState, Pagination } from "@/components/ui";
import { formatDateTime } from "@/lib/format";
import { Users as UsersIcon } from "lucide-react";
import type { Role } from "@prisma/client";

export const dynamic = "force-dynamic";

const ROLES: Role[] = [
  "SUPER_ADMIN",
  "COMPANY_ADMIN",
  "HR",
  "MANAGER",
  "EMPLOYEE",
  "RECRUITER",
  "PAYROLL_ADMIN",
  "FINANCE",
  "IT_ADMIN",
  "AUDITOR",
  "MANAGEMENT",
];
const PAGE_SIZE = 25;

export default async function PlatformUsersPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string; role?: string; companyId?: string; status?: string; page?: string }>;
}) {
  const session = await requireRole(["SUPER_ADMIN"]);
  const { q, role, companyId, status, page: pageParam } = await searchParams;
  const page = Math.max(1, Number(pageParam) || 1);

  const companies = await prisma.company.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } });

  const where = {
    ...(q ? { OR: [{ email: { contains: q } }, { company: { name: { contains: q } } }] } : {}),
    ...(role ? { role: role as Role } : {}),
    ...(companyId ? (companyId === "__platform__" ? { companyId: null } : { companyId }) : {}),
    ...(status ? { isActive: status === "active" } : {}),
  };

  const [total, users] = await Promise.all([
    prisma.user.count({ where }),
    prisma.user.findMany({
      where,
      include: { company: true, customRole: true },
      orderBy: [{ company: { name: "asc" } }, { email: "asc" }],
      take: PAGE_SIZE,
      skip: (page - 1) * PAGE_SIZE,
    }),
  ]);
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  async function toggle(formData: FormData) {
    "use server";
    await toggleCompanyUser(String(formData.get("userId")), formData.get("active") === "1");
  }

  return (
    <div>
      <PageHeader title="Users" subtitle={`${total} login(s) across every tenant on the platform.`} />

      <form className="card p-4 mb-5 flex flex-wrap items-end gap-3" method="get">
        <div>
          <label className="field-label">Search email or company</label>
          <input name="q" defaultValue={q ?? ""} placeholder="name@company.com or company name" className="input" />
        </div>
        <div>
          <label className="field-label">Role</label>
          <select name="role" defaultValue={role ?? ""} className="input">
            <option value="">All roles</option>
            {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label">Company</label>
          <select name="companyId" defaultValue={companyId ?? ""} className="input">
            <option value="">All companies</option>
            <option value="__platform__">Platform (no company)</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label">Status</label>
          <select name="status" defaultValue={status ?? ""} className="input">
            <option value="">Any status</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
          </select>
        </div>
        <button type="submit" className="btn btn-secondary">Filter</button>
        {(q || role || companyId || status) && (
          <Link href="/dashboard/users" className="text-sm text-muted hover:text-ink">Clear</Link>
        )}
      </form>

      <div className="card overflow-x-auto">
        {users.length === 0 ? (
          <EmptyState icon={UsersIcon} title="No users match these filters" />
        ) : (
          <table className="table-clean">
            <thead>
              <tr><th>Email</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              {users.map((u, i) => {
                const companyKey = u.company?.id ?? "__platform__";
                const prevKey = i > 0 ? (users[i - 1].company?.id ?? "__platform__") : null;
                return (
                  <Fragment key={u.id}>
                    {companyKey !== prevKey && (
                      <tr className="bg-brand-softer">
                        <td colSpan={5} className="text-xs font-semibold uppercase tracking-wide text-ink-soft py-2">
                          {u.company ? (
                            <Link href={`/dashboard/companies/${u.company.id}`} className="hover:underline">{u.company.name}</Link>
                          ) : (
                            "Platform (no company)"
                          )}
                        </td>
                      </tr>
                    )}
                    <tr>
                      <td className="text-ink">{u.email}</td>
                      <td>{u.customRole?.name ?? ROLE_LABEL[u.role]}</td>
                      <td>{u.lastLoginAt ? formatDateTime(u.lastLoginAt) : "Never"}</td>
                      <td>{u.isActive ? "Active" : "Disabled"}</td>
                      <td>
                        {u.id === session.sub ? (
                          <span className="text-xs text-muted">You</span>
                        ) : (
                          <form action={toggle}>
                            <input type="hidden" name="userId" value={u.id} />
                            <input type="hidden" name="active" value={u.isActive ? "0" : "1"} />
                            <button className="text-sm font-medium text-brand hover:underline">
                              {u.isActive ? "Disable" : "Enable"}
                            </button>
                          </form>
                        )}
                      </td>
                    </tr>
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      <Pagination
        page={page}
        totalPages={totalPages}
        basePath="/dashboard/users"
        query={{ q, role, companyId, status }}
      />
    </div>
  );
}
