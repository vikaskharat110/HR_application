import { requireRole, ROLE_LABEL, ASSIGNABLE_ROLES } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { CONFIGURABLE_MODULES } from "@/lib/permissions";
import { deleteCustomRole, setCustomRoleModuleAccess } from "@/lib/actions/customRoles";
import { PageHeader, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { CreateCustomRoleForm } from "@/components/CreateCustomRoleForm";
import { ConfirmSubmitButton } from "@/components/ConfirmSubmitButton";
import { ShieldCheck, Trash2 } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function CustomRolesPage() {
  const session = await requireRole(["COMPANY_ADMIN"]);
  if (!session.companyId) throw new Error("No company context");

  const customRoles = await prisma.customRole.findMany({
    where: { companyId: session.companyId },
    include: { _count: { select: { users: true } } },
    orderBy: { createdAt: "asc" },
  });

  const permissionRows = customRoles.length
    ? await prisma.rolePermission.findMany({
        where: { companyId: session.companyId, action: "VIEW", role: { in: customRoles.map((r) => r.id) } },
      })
    : [];
  const allowedMap = new Map(permissionRows.map((r) => [`${r.role}:${r.module}`, r.allowed]));

  async function remove(formData: FormData) {
    "use server";
    await deleteCustomRole(String(formData.get("id")));
  }

  async function toggle(formData: FormData) {
    "use server";
    const customRoleId = String(formData.get("customRoleId"));
    const moduleHref = String(formData.get("module"));
    const allowed = formData.get("allowed") === "1";
    await setCustomRoleModuleAccess(customRoleId, moduleHref, allowed);
  }

  const baseRoleOptions = ASSIGNABLE_ROLES.map((r) => ({ value: r, label: ROLE_LABEL[r] }));

  return (
    <div>
      <BackLink fallbackHref="/dashboard/settings">Back to settings</BackLink>
      <PageHeader
        title="Custom roles"
        subtitle="Give a role its own name — it inherits the base role's approvals and business rules, but you control which dashboard sections it can see."
      />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Create a role</p>
          <CreateCustomRoleForm baseRoles={baseRoleOptions} />
        </div>

        <div className="lg:col-span-2 space-y-5">
          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><ShieldCheck size={15} /> Your roles</p>
            {customRoles.length === 0 ? (
              <EmptyState icon={ShieldCheck} title="No custom roles yet" message="Create one on the left to get started." />
            ) : (
              <ul className="divide-y divide-border-soft">
                {customRoles.map((r) => (
                  <li key={r.id} className="py-3 flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium text-ink">{r.name}</p>
                      <p className="text-xs text-muted mt-0.5">
                        Based on {ROLE_LABEL[r.baseRole]} · {r._count.users} user(s) assigned
                      </p>
                    </div>
                    <form action={remove}>
                      <input type="hidden" name="id" value={r.id} />
                      <ConfirmSubmitButton
                        confirmMessage={`Delete the "${r.name}" role? This can't be undone.`}
                        className="btn btn-secondary !py-1.5 !px-2.5 text-danger disabled:opacity-40"
                        disabled={r._count.users > 0}
                        title={r._count.users > 0 ? "Reassign every user with this role before deleting it" : "Delete role"}
                      >
                        <Trash2 size={14} />
                      </ConfirmSubmitButton>
                    </form>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {customRoles.length > 0 && (
            <div className="card overflow-x-auto">
              <table className="table-clean table-compact table-sticky-col">
                <thead>
                  <tr>
                    <th>Module</th>
                    {customRoles.map((r) => <th key={r.id}>{r.name}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {CONFIGURABLE_MODULES.map((mod) => (
                    <tr key={mod.href}>
                      <td className="font-medium text-ink">{mod.label}</td>
                      {customRoles.map((r) => {
                        if (!mod.roles.includes(r.baseRole)) {
                          return <td key={r.id} className="text-muted text-xs">—</td>;
                        }
                        const allowed = allowedMap.get(`${r.id}:${mod.href}`) ?? true;
                        return (
                          <td key={r.id}>
                            <form action={toggle}>
                              <input type="hidden" name="customRoleId" value={r.id} />
                              <input type="hidden" name="module" value={mod.href} />
                              <input type="hidden" name="allowed" value={allowed ? "0" : "1"} />
                              <button className={`text-sm font-medium hover:underline ${allowed ? "text-brand" : "text-danger"}`}>
                                {allowed ? "Allowed" : "Blocked"}
                              </button>
                            </form>
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
