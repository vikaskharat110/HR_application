import { requireRole, ROLE_LABEL } from "@/lib/session";
import { CONFIGURABLE_MODULES, platformDefaults, PAYROLL_ACTIONS, payrollActionDefaults } from "@/lib/permissions";
import { setModuleAccess, setPayrollAction } from "@/lib/actions/permissions";
import { PageHeader } from "@/components/ui";
import { RolesPermissionsTabs } from "@/components/RolesPermissionsTabs";
import type { Role } from "@prisma/client";

export const dynamic = "force-dynamic";

const DISPLAY_ROLES: Role[] = [
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

const PAYROLL_ACTION_LABEL: Record<string, string> = {
  EDIT_SALARY: "Edit salary",
  PROCESS: "Process payroll",
  APPROVE: "Approve payroll",
  LOCK: "Lock payroll",
};

export default async function RolesPermissionsPage() {
  await requireRole(["SUPER_ADMIN"]);
  const defaults = await platformDefaults();
  const payrollDefaults = await payrollActionDefaults();

  async function toggle(formData: FormData) {
    "use server";
    const role = String(formData.get("role")) as Role;
    const moduleHref = String(formData.get("module"));
    const allowed = formData.get("allowed") === "1";
    await setModuleAccess(role, moduleHref, allowed);
  }

  async function togglePayrollAction(formData: FormData) {
    "use server";
    const role = String(formData.get("role")) as Role;
    const action = String(formData.get("action"));
    const allowed = formData.get("allowed") === "1";
    await setPayrollAction(role, action, allowed);
  }

  const modulesPanel = (
    <div>
      <p className="text-sm text-muted mb-4">
        Platform-wide defaults for which roles can open which modules. A blank cell means the role has no access to
        that module in the underlying app — no toggle can grant it.
      </p>
      <div className="card overflow-x-auto">
        <table className="table-clean table-compact table-sticky-col">
          <thead>
            <tr>
              <th>Module</th>
              {DISPLAY_ROLES.map((r) => <th key={r}>{ROLE_LABEL[r]}</th>)}
            </tr>
          </thead>
          <tbody>
            {CONFIGURABLE_MODULES.map((mod) => (
              <tr key={mod.href}>
                <td className="font-medium text-ink">{mod.label}</td>
                {DISPLAY_ROLES.map((role) => {
                  if (!mod.roles.includes(role)) {
                    return <td key={role} className="text-muted text-xs">—</td>;
                  }
                  const allowed = defaults.get(`${role}:${mod.href}`) ?? true;
                  return (
                    <td key={role}>
                      <form action={toggle}>
                        <input type="hidden" name="role" value={role} />
                        <input type="hidden" name="module" value={mod.href} />
                        <input type="hidden" name="allowed" value={allowed ? "0" : "1"} />
                        <button
                          className={`text-sm font-medium hover:underline ${allowed ? "text-brand" : "text-danger"}`}
                        >
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
    </div>
  );

  const payrollPanel = (
    <div>
      <p className="text-sm text-muted mb-4">
        Payroll and salary data are the most sensitive information in the system, so these four actions are gated
        individually — beyond just opening the Payroll module.
      </p>
      <div className="card overflow-x-auto">
        <table className="table-clean table-compact table-sticky-col">
          <thead>
            <tr>
              <th>Action</th>
              {(["COMPANY_ADMIN", "HR", "MANAGER", "PAYROLL_ADMIN", "FINANCE"] as Role[]).map((r) => (
                <th key={r}>{ROLE_LABEL[r]}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {PAYROLL_ACTIONS.map((action) => (
              <tr key={action}>
                <td className="font-medium text-ink">{PAYROLL_ACTION_LABEL[action]}</td>
                {(["COMPANY_ADMIN", "HR", "MANAGER", "PAYROLL_ADMIN", "FINANCE"] as Role[]).map((role) => {
                  const allowed = payrollDefaults.get(`${role}:${action}`);
                  const resolved = allowed ?? undefined;
                  return (
                    <td key={role}>
                      <form action={togglePayrollAction}>
                        <input type="hidden" name="role" value={role} />
                        <input type="hidden" name="action" value={action} />
                        <input type="hidden" name="allowed" value={resolved ? "0" : "1"} />
                        <button
                          className={`text-sm font-medium hover:underline ${resolved ? "text-brand" : "text-muted"}`}
                        >
                          {resolved === undefined ? "Default" : resolved ? "Allowed" : "Blocked"}
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
      <p className="text-xs text-muted mt-3">
        Changes apply platform-wide immediately — every company using the default (none has an override yet) picks
        this up on their next page load, hiding the module from their sidebar and blocking direct URL access.
      </p>
    </div>
  );

  return (
    <div>
      <PageHeader title="Roles & Permissions" subtitle="Platform-wide defaults for which roles can access what." />
      <RolesPermissionsTabs modulesPanel={modulesPanel} payrollPanel={payrollPanel} />
    </div>
  );
}

