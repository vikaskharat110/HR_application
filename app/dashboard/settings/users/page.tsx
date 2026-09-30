import { requireRole, ROLE_LABEL, ASSIGNABLE_ROLES } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, Pagination } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { inviteUser, setUserActive } from "@/lib/actions/settings";
import { formatDate } from "@/lib/format";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 20;

export default async function UsersSettingsPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string }>;
}) {
  const session = await requireRole(["COMPANY_ADMIN"]);
  await requireModuleAccess("/dashboard/settings");
  const { page: pageParam } = await searchParams;
  const page = Math.max(1, Number(pageParam) || 1);

  const where = { companyId: session.companyId ?? undefined };

  const [total, users, customRoles] = await Promise.all([
    prisma.user.count({ where }),
    prisma.user.findMany({
      where,
      include: { employee: true, customRole: true },
      orderBy: { createdAt: "asc" },
      take: PAGE_SIZE,
      skip: (page - 1) * PAGE_SIZE,
    }),
    prisma.customRole.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
  ]);
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  async function toggle(formData: FormData) {
    "use server";
    await setUserActive(String(formData.get("userId")), formData.get("active") === "1");
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/settings">Back to settings</BackLink>
      <PageHeader title="Users & access" subtitle={`${total} portal login(s) and their roles.`} />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Invite a user</p>
          <form action={inviteUser} className="space-y-3">
            <input name="email" type="email" required placeholder="email@company.com" className="input" />
            <select name="role" className="input">
              <optgroup label="Standard roles">
                {ASSIGNABLE_ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
              </optgroup>
              {customRoles.length > 0 && (
                <optgroup label="Custom roles">
                  {customRoles.map((cr) => <option key={cr.id} value={`custom:${cr.id}`}>{cr.name}</option>)}
                </optgroup>
              )}
            </select>
            <button type="submit" className="btn btn-primary w-full justify-center">Create login</button>
          </form>
          <p className="text-xs text-muted mt-2">Temporary password: <span className="font-mono">Welcome@123</span></p>
        </div>

        <div className="lg:col-span-2">
          <div className="card overflow-x-auto">
            <table className="table-clean">
              <thead><tr><th>Email</th><th>Role</th><th>Linked employee</th><th>Joined</th><th>Status</th><th></th></tr></thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id}>
                    <td>{u.email}</td>
                    <td>{u.customRole?.name ?? ROLE_LABEL[u.role]}</td>
                    <td>{u.employee ? `${u.employee.firstName} ${u.employee.lastName}` : "—"}</td>
                    <td>{formatDate(u.createdAt)}</td>
                    <td>{u.isActive ? "Active" : "Disabled"}</td>
                    <td>
                      <form action={toggle}>
                        <input type="hidden" name="userId" value={u.id} />
                        <input type="hidden" name="active" value={u.isActive ? "0" : "1"} />
                        <button className="text-sm font-medium text-brand hover:underline">{u.isActive ? "Disable" : "Enable"}</button>
                      </form>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={page} totalPages={totalPages} basePath="/dashboard/settings/users" />
        </div>
      </div>
    </div>
  );
}
