import "server-only";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import type { Role } from "@prisma/client";
import { prisma } from "./prisma";
import { SESSION_COOKIE, verifySession, type SessionPayload } from "./auth";

export async function getSession(): Promise<SessionPayload | null> {
  const jar = await cookies();
  const token = jar.get(SESSION_COOKIE)?.value;
  if (!token) return null;
  return verifySession(token);
}

/**
 * A session token is a fixed snapshot signed at login — `sub`/`employeeId` are
 * only ever as valid as the row they pointed to. A `db:seed` reset (or any
 * hard delete) leaves already-issued cookies pointing at ids that no longer
 * exist, which otherwise surfaces later as a raw FK-constraint crash deep in
 * some unrelated action (e.g. attendance check-in). One cheap primary-key
 * lookup here catches it up front and sends the user back to a clean login
 * instead.
 */
async function isSessionStillValid(session: SessionPayload): Promise<boolean> {
  const user = await prisma.user.findUnique({
    where: { id: session.sub },
    select: { isActive: true, employee: { select: { id: true } } },
  });
  if (!user || !user.isActive) return false;
  if (session.employeeId && session.employeeId !== user.employee?.id) return false;
  return true;
}

export async function requireSession(): Promise<SessionPayload> {
  const session = await getSession();
  if (!session) redirect("/login");
  if (!(await isSessionStillValid(session))) {
    // Cookies can only be mutated from a Server Action or Route Handler, never
    // from a Server Component's render path (which is where every caller of
    // requireSession lives) — so the stale cookie is left in place here. It's
    // harmless: every protected page keeps bouncing back to /login until the
    // next successful login overwrites it with a freshly-signed one.
    redirect("/login");
  }
  return session;
}

export async function requireRole(roles: readonly Role[]): Promise<SessionPayload> {
  const session = await requireSession();
  if (!roles.includes(session.role)) redirect("/dashboard");
  return session;
}

export const ROLE_LABEL: Record<Role, string> = {
  SUPER_ADMIN: "Super Admin",
  COMPANY_ADMIN: "Company Admin",
  HR: "HR",
  MANAGER: "Manager",
  EMPLOYEE: "Employee",
  RECRUITER: "Recruiter",
  PAYROLL_ADMIN: "Payroll Admin",
  FINANCE: "Finance",
  IT_ADMIN: "IT / Admin",
  AUDITOR: "Auditor",
  MANAGEMENT: "Management",
};

/**
 * Every role a Company Admin/HR can hand out to someone inside their own
 * tenant — everything except SUPER_ADMIN, which only exists as a platform
 * account created outside any company (see createCompany). Used both to
 * render the role dropdowns and to validate what a submitted role is
 * actually allowed to be, since a raw request can send any string regardless
 * of what the dropdown offers.
 */
export const ASSIGNABLE_ROLES: Role[] = [
  "EMPLOYEE",
  "MANAGER",
  "HR",
  "RECRUITER",
  "PAYROLL_ADMIN",
  "FINANCE",
  "IT_ADMIN",
  "AUDITOR",
  "MANAGEMENT",
  "COMPANY_ADMIN",
];

export const ROLE_HOME: Record<Role, string> = {
  SUPER_ADMIN: "/dashboard/companies",
  COMPANY_ADMIN: "/dashboard",
  HR: "/dashboard",
  MANAGER: "/dashboard",
  EMPLOYEE: "/dashboard",
  RECRUITER: "/dashboard",
  PAYROLL_ADMIN: "/dashboard",
  FINANCE: "/dashboard",
  IT_ADMIN: "/dashboard",
  AUDITOR: "/dashboard",
  MANAGEMENT: "/dashboard",
};

