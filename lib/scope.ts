import "server-only";
import { prisma } from "./prisma";
import type { SessionPayload } from "./auth";

/** True for roles that can see data beyond their own employee record. */
export function isManagerial(session: SessionPayload) {
  return session.role === "COMPANY_ADMIN" || session.role === "HR" || session.role === "MANAGER";
}

export function isCompanyAdmin(session: SessionPayload) {
  return session.role === "COMPANY_ADMIN" || session.role === "HR";
}

/**
 * Employee ids a session is allowed to see for team-scoped modules
 * (attendance, leave, expenses, approvals): admins/HR see the whole
 * company, managers see their reporting line plus themselves, employees
 * see only themselves.
 */
export async function visibleEmployeeIds(session: SessionPayload): Promise<string[]> {
  if (!session.companyId) return [];

  if (session.role === "COMPANY_ADMIN" || session.role === "HR") {
    const all = await prisma.employee.findMany({
      where: { companyId: session.companyId },
      select: { id: true },
    });
    return all.map((e) => e.id);
  }

  if (session.role === "MANAGER" && session.employeeId) {
    const ids = new Set<string>([session.employeeId]);
    let frontier = [session.employeeId];
    for (let depth = 0; depth < 5 && frontier.length > 0; depth++) {
      const reports = await prisma.employee.findMany({
        where: { managerId: { in: frontier } },
        select: { id: true },
      });
      frontier = reports.map((r) => r.id).filter((id) => !ids.has(id));
      frontier.forEach((id) => ids.add(id));
    }
    return Array.from(ids);
  }

  return session.employeeId ? [session.employeeId] : [];
}

/** Employee ids of every HR user in the company — used to keep HR's requests
 * out of a peer HR's approval queue (see `approvalQueueWhere`/`assertCanActOn`). */
async function hrEmployeeIds(companyId: string | null | undefined): Promise<string[]> {
  if (!companyId) return [];
  const rows = await prisma.employee.findMany({ where: { companyId, user: { role: "HR" } }, select: { id: true } });
  return rows.map((e) => e.id);
}

/**
 * Filter for an approvals queue list (leave/expense/regularization): Company
 * Admin sees every pending request company-wide; HR sees the same except
 * requests raised by a fellow HR employee (those go to the Company Admin or
 * the requester's own manager instead — HR shouldn't approve HR); a plain
 * Manager sees only the requests specifically assigned to them, matching the
 * strict single-approver action guard below — so nothing shows up in the
 * queue that they'd then be blocked from actually deciding.
 */
export async function approvalQueueWhere(session: SessionPayload) {
  const visible = await visibleEmployeeIds(session);

  if (session.role === "HR") {
    const excluded = await hrEmployeeIds(session.companyId);
    return { employeeId: { in: visible, notIn: excluded } };
  }
  if (isCompanyAdmin(session)) return { employeeId: { in: visible } };
  return { employeeId: { in: visible }, approverId: session.employeeId ?? "__none__" };
}

/**
 * Guards every "act on someone else's request" server action (approve/reject
 * leave, expenses, regularizations, reviews...). A request id alone is not a
 * safe authorization boundary — the caller must also be entitled to decide
 * this specific request.
 *
 * This follows the model most HRMS products ship with: HR/Company Admin get
 * a company-wide administrative override (they can always step in), but a
 * plain Manager may only act as the *specifically assigned* approver —
 * `assignedApproverId` — not any manager above the requester in the chain.
 * A skip-level manager covering for someone is a deliberate delegation or
 * escalation feature in real systems, not standing authority every manager
 * has by default, so it isn't granted implicitly here.
 */
export async function assertCanActOn(
  session: SessionPayload,
  targetEmployeeId: string,
  assignedApproverId: string | null | undefined
) {
  if (!isManagerial(session)) {
    throw new Error("You are not authorized to act on this request.");
  }
  if (targetEmployeeId === session.employeeId) {
    throw new Error("You cannot approve or reject your own request.");
  }

  // HR's own requests skip HR's usual company-wide override — a fellow HR
  // employee isn't allowed to approve them, only the Company Admin or the
  // requester's own assigned manager, so HR can't approve HR.
  if (session.role === "HR") {
    const target = await prisma.employee.findUnique({ where: { id: targetEmployeeId }, select: { user: { select: { role: true } } } });
    if (target?.user?.role === "HR") {
      if (session.employeeId !== assignedApproverId) {
        throw new Error("An HR request can only be approved by the Company Admin or the requester's own manager.");
      }
      return;
    }
  }

  if (isCompanyAdmin(session)) {
    const ids = await visibleEmployeeIds(session);
    if (!ids.includes(targetEmployeeId)) {
      throw new Error("You are not authorized to act on this request.");
    }
    return;
  }

  if (session.employeeId !== assignedApproverId) {
    throw new Error("Only the assigned approver, or HR/Admin, can act on this request.");
  }
}

