import "server-only";
import { redirect } from "next/navigation";
import { prisma } from "./prisma";
import { requireSession } from "./session";
import { NAV_ITEMS } from "./nav";
import type { SessionPayload } from "./auth";

/**
 * Tenant modules a Super Admin can restrict per role. Platform-level nav
 * items (Dashboard, Companies, Users, Audit Logs) are intentionally excluded
 * — those aren't tenant modules, so plain role checks are the whole story
 * for them, same as before this feature existed.
 */
const CONFIGURABLE_HREFS = new Set([
  "/dashboard/employees",
  "/dashboard/attendance",
  "/dashboard/leave",
  "/dashboard/payroll",
  "/dashboard/recruitment",
  "/dashboard/performance",
  "/dashboard/expenses",
  "/dashboard/documents",
  "/dashboard/assets",
  "/dashboard/helpdesk",
  "/dashboard/reports",
  "/dashboard/settings",
]);

export const CONFIGURABLE_MODULES = NAV_ITEMS.filter((item) => CONFIGURABLE_HREFS.has(item.href));

/**
 * Whether `session` can access the module at `href`.
 *
 * This is a *restriction* layer, never a grant: a role that isn't in the
 * nav item's own `roles` list is denied outright, before the database is
 * even consulted — a permission row can never give a role access the code
 * doesn't actually support. For roles the code does support, the database
 * can additionally turn a module off (tenant override first, then the
 * platform-wide default); no row at all means "allowed", so every existing
 * company keeps exactly today's behavior with nothing to seed or migrate.
 */
export async function hasModuleAccess(session: SessionPayload, href: string): Promise<boolean> {
  const item = NAV_ITEMS.find((i) => i.href === href);
  if (!item) return true;
  if (!item.roles.includes(session.role)) return false;
  if (!CONFIGURABLE_HREFS.has(href)) return true;

  // A custom role's own id, when present, takes over from its base role as
  // the override lookup key — so its module visibility can be toggled
  // independently of every other user who shares that base role. The
  // ceiling check above always stays on the base role: a custom role can
  // never see more than its base role's nav.ts allow-list permits.
  const rows = await prisma.rolePermission.findMany({
    where: {
      role: session.customRoleId ?? session.role,
      module: href,
      action: "VIEW",
      OR: [{ companyId: session.companyId ?? "__none__" }, { companyId: null }],
    },
  });

  const override = rows.find((r) => r.companyId === session.companyId) ?? rows.find((r) => r.companyId === null);
  return override ? override.allowed : true;
}

export async function requireModuleAccess(href: string): Promise<SessionPayload> {
  const session = await requireSession();
  const ok = await hasModuleAccess(session, href);
  if (!ok) redirect("/dashboard");
  return session;
}

/**
 * Every configurable-module href this session cannot access, in one query
 * (rather than one query per module) — for filtering the sidebar/mobile nav.
 */
export async function hiddenHrefsForSession(session: SessionPayload): Promise<string[]> {
  const candidates = CONFIGURABLE_MODULES.filter((m) => m.roles.includes(session.role));
  if (candidates.length === 0) return [];

  const rows = await prisma.rolePermission.findMany({
    where: {
      role: session.customRoleId ?? session.role,
      action: "VIEW",
      module: { in: candidates.map((m) => m.href) },
      OR: [{ companyId: session.companyId ?? "__none__" }, { companyId: null }],
    },
  });

  const hidden: string[] = [];
  for (const mod of candidates) {
    const forModule = rows.filter((r) => r.module === mod.href);
    const override = forModule.find((r) => r.companyId === session.companyId) ?? forModule.find((r) => r.companyId === null);
    if (override && !override.allowed) hidden.push(mod.href);
  }
  return hidden;
}

/** Platform-default (companyId: null) allow-map for every configurable module x role, for the Roles & Permissions screen. */
export async function platformDefaults(): Promise<Map<string, boolean>> {
  const rows = await prisma.rolePermission.findMany({ where: { companyId: null, action: "VIEW" } });
  const map = new Map<string, boolean>();
  for (const r of rows) map.set(`${r.role}:${r.module}`, r.allowed);
  return map;
}

/**
 * Payroll-specific Action×Scope control (BRD Section 10.2): unlike the plain
 * module VIEW toggle above, these four actions are sensitive enough to gate
 * individually. Backed by the same RolePermission table (module fixed to the
 * payroll href, action one of the four below) so Super Admin can reconfigure
 * without a code change; when no override row exists, the BRD 10.2 table
 * itself is the default — Payroll Admin and Company Admin hold all four,
 * Finance holds only Approve, nobody else holds any.
 */
export const PAYROLL_ACTIONS = ["EDIT_SALARY", "PROCESS", "APPROVE", "LOCK"] as const;
export type PayrollAction = (typeof PAYROLL_ACTIONS)[number];
const PAYROLL_MODULE = "/dashboard/payroll";

const PAYROLL_ACTION_DEFAULTS: Record<PayrollAction, SessionPayload["role"][]> = {
  EDIT_SALARY: ["COMPANY_ADMIN", "PAYROLL_ADMIN"],
  PROCESS: ["COMPANY_ADMIN", "PAYROLL_ADMIN"],
  APPROVE: ["COMPANY_ADMIN", "PAYROLL_ADMIN", "FINANCE"],
  LOCK: ["COMPANY_ADMIN", "PAYROLL_ADMIN"],
};

export async function hasPayrollAction(session: SessionPayload, action: PayrollAction): Promise<boolean> {
  const rows = await prisma.rolePermission.findMany({
    where: {
      role: session.role,
      module: PAYROLL_MODULE,
      action,
      OR: [{ companyId: session.companyId ?? "__none__" }, { companyId: null }],
    },
  });
  const override = rows.find((r) => r.companyId === session.companyId) ?? rows.find((r) => r.companyId === null);
  if (override) return override.allowed;
  return PAYROLL_ACTION_DEFAULTS[action].includes(session.role);
}

/** Platform-default allow-map for the four payroll actions, for the Roles & Permissions screen. */
export async function payrollActionDefaults(): Promise<Map<string, boolean>> {
  const rows = await prisma.rolePermission.findMany({
    where: { companyId: null, module: PAYROLL_MODULE, action: { in: PAYROLL_ACTIONS as unknown as string[] } },
  });
  const map = new Map<string, boolean>();
  for (const r of rows) map.set(`${r.role}:${r.action}`, r.allowed);
  return map;
}
