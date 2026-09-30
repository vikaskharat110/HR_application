"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession, ASSIGNABLE_ROLES } from "@/lib/session";
import { hashPassword } from "@/lib/auth";
import { recordAudit, notify } from "@/lib/workflow";
import { parseCSV } from "@/lib/csv";
import type { EmploymentType, Gender, Role } from "@prisma/client";

export async function createEmployee(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const firstName = String(formData.get("firstName") || "").trim();
  const lastName = String(formData.get("lastName") || "").trim();
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const designation = String(formData.get("designation") || "").trim();
  const departmentId = String(formData.get("departmentId") || "") || null;
  const branchId = String(formData.get("branchId") || "") || null;
  const managerId = String(formData.get("managerId") || "") || null;
  const employmentType = String(formData.get("employmentType") || "FULL_TIME") as EmploymentType;
  const doj = String(formData.get("dateOfJoining") || "");
  const createLogin = formData.get("createLogin") === "on";
  const roleRaw = String(formData.get("role") || "EMPLOYEE");
  const candidateId = String(formData.get("candidateId") || "") || null;

  if (!firstName || !lastName || !email || !designation || !doj) {
    throw new Error("Missing required fields");
  }

  // A custom role's option value is "custom:<id>" — resolve it to the base
  // role it inherits its business-logic permissions from (approvals, who can
  // create employees, etc.); everything downstream keeps working off that
  // base role exactly as it would for anyone with the plain enum role.
  let role: Role;
  let customRoleId: string | null = null;
  if (roleRaw.startsWith("custom:")) {
    customRoleId = roleRaw.slice("custom:".length);
    const customRole = await prisma.customRole.findFirst({ where: { id: customRoleId, companyId: session.companyId } });
    if (!customRole) throw new Error("Custom role not found.");
    role = customRole.baseRole;
  } else {
    role = roleRaw as Role;
  }

  if (createLogin && !ASSIGNABLE_ROLES.includes(role)) {
    throw new Error("That role can't be assigned from here.");
  }
  if (createLogin && role === "COMPANY_ADMIN" && session.role !== "COMPANY_ADMIN") {
    throw new Error("Only a Company Admin can grant Company Admin access.");
  }

  if (departmentId || branchId || managerId) {
    const [dept, branch, manager] = await Promise.all([
      departmentId ? prisma.department.findFirst({ where: { id: departmentId, companyId: session.companyId } }) : null,
      branchId ? prisma.branch.findFirst({ where: { id: branchId, companyId: session.companyId } }) : null,
      managerId ? prisma.employee.findFirst({ where: { id: managerId, companyId: session.companyId } }) : null,
    ]);
    if (departmentId && !dept) throw new Error("Department not found.");
    if (branchId && !branch) throw new Error("Branch not found.");
    if (managerId && !manager) throw new Error("Manager not found.");
  }

  let candidate: { id: string; jobPostingId: string } | null = null;
  if (candidateId) {
    candidate = await prisma.candidate.findFirst({
      where: { id: candidateId, jobPosting: { companyId: session.companyId } },
      select: { id: true, jobPostingId: true },
    });
    if (!candidate) throw new Error("Candidate not found.");
    const alreadyLinked = await prisma.employee.findUnique({ where: { candidateId } });
    if (alreadyLinked) throw new Error("This candidate has already been onboarded as an employee.");
  }

  const count = await prisma.employee.count({ where: { companyId: session.companyId } });
  const employeeCode = `MW-${1001 + count}`;

  let userId: string | undefined;
  let linkedExistingUser = false;
  if (createLogin) {
    // The email can already belong to a login with no employee behind it yet —
    // e.g. the Company Admin account a Super Admin creates when provisioning the
    // company. Link to that login instead of trying (and failing, since email is
    // globally unique on User) to create a second one.
    const existingUser = await prisma.user.findUnique({ where: { email } });
    if (existingUser) {
      if (existingUser.companyId !== session.companyId) {
        throw new Error("A login with this email already exists on a different company.");
      }
      const alreadyLinkedEmployee = await prisma.employee.findUnique({ where: { userId: existingUser.id } });
      if (alreadyLinkedEmployee) {
        throw new Error("This login is already linked to another employee.");
      }
      if (existingUser.role !== role || existingUser.customRoleId !== customRoleId) {
        await prisma.user.update({ where: { id: existingUser.id }, data: { role, customRoleId } });
      }
      userId = existingUser.id;
      linkedExistingUser = true;
    } else {
      const passwordHash = await hashPassword("Welcome@123");
      const user = await prisma.user.create({
        data: { email, passwordHash, role, customRoleId, companyId: session.companyId },
      });
      userId = user.id;
    }
  }

  const employee = await prisma.employee.create({
    data: {
      companyId: session.companyId,
      userId,
      candidateId: candidate?.id,
      employeeCode,
      firstName,
      lastName,
      email,
      designation,
      departmentId,
      branchId,
      managerId,
      employmentType,
      dateOfJoining: new Date(doj),
      avatarColor: ["#1f4d3a", "#c1712f", "#2c6a8c", "#7a5a2f", "#5a3a7a"][count % 5],
    },
  });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "Employee",
    entityId: employee.id,
    meta: candidate ? { name: `${firstName} ${lastName}`, onboardedFromCandidateId: candidate.id } : { name: `${firstName} ${lastName}` },
  });

  // A hire coming through recruitment gets a pre-boarding/onboarding checklist
  // cloned from the company's task templates (BRD FR-032); a directly-added
  // employee (no candidate behind them) doesn't need one. Always create the
  // Onboarding record itself even if there are zero templates right now — an
  // empty-but-visible checklist in the Onboarding list is a signal HR can act
  // on (add templates in Settings); silently creating nothing here is what
  // caused new companies with no templates yet to "lose" a hire's checklist
  // with no visible trace of it ever happening.
  if (candidate) {
    const templates = await prisma.onboardingTaskTemplate.findMany({
      where: { companyId: session.companyId },
      orderBy: { sortOrder: "asc" },
    });
    await prisma.onboarding.create({
      data: {
        employeeId: employee.id,
        tasks: { create: templates.map((t) => ({ category: t.category, title: t.title })) },
      },
    });
  }

  if (userId && !linkedExistingUser) {
    await notify({
      userId,
      title: "Welcome to Verdant HR",
      message: `Your account is ready. Temporary password: Welcome@123 — please change it after logging in.`,
      link: "/dashboard",
    });
  } else if (userId && linkedExistingUser) {
    await notify({
      userId,
      title: "Your employee profile is ready",
      message: `${firstName} ${lastName}'s employee record has been added to your existing login — no password change needed.`,
      link: `/dashboard/employees/${employee.id}`,
    });
  }

  revalidatePath("/dashboard/employees");
  redirect(`/dashboard/employees/${employee.id}`);
}

/** True if `candidateManagerId` is `employeeId` itself, or is anywhere in the chain of people who already report — directly or transitively — to `employeeId`. Setting such a person as manager would create a reporting cycle. */
export async function wouldCreateManagerCycle(employeeId: string, candidateManagerId: string): Promise<boolean> {
  if (candidateManagerId === employeeId) return true;

  let frontier = [employeeId];
  for (let depth = 0; depth < 10 && frontier.length > 0; depth++) {
    const reports = await prisma.employee.findMany({ where: { managerId: { in: frontier } }, select: { id: true } });
    const ids = reports.map((r) => r.id);
    if (ids.includes(candidateManagerId)) return true;
    frontier = ids;
  }
  return false;
}

type OrgFields = { designation: string; departmentId: string | null; branchId: string | null; managerId: string | null };

/**
 * Shared by updateEmployee and the promotion/transfer approval flow: applies
 * a change to an employee's designation/department/branch/manager, resolving
 * relation ids to the names they had at the time (so the audit trail stays
 * readable even if a department is later renamed or deleted), and writes one
 * audit entry with a field-level diff. Only keys present in `patch` are
 * changed — an absent key leaves that field exactly as it was.
 */
export async function applyOrgFieldChanges(params: {
  companyId: string;
  actorId: string;
  employeeId: string;
  existing: OrgFields;
  patch: Partial<OrgFields>;
  auditAction?: string;
  auditMeta?: Record<string, unknown>;
  /** Additional already-computed field changes (e.g. contact details) to fold into the same audit entry, rather than writing a second row for one edit. */
  extraChanges?: Record<string, { from: unknown; to: unknown }>;
}): Promise<Record<string, { from: unknown; to: unknown }>> {
  const { companyId, actorId, employeeId, existing, patch } = params;
  const next: OrgFields = { ...existing, ...patch };

  const [oldDept, newDept, oldBranch, newBranch, oldManager, newManager] = await Promise.all([
    existing.departmentId ? prisma.department.findUnique({ where: { id: existing.departmentId } }) : null,
    next.departmentId && next.departmentId !== existing.departmentId ? prisma.department.findUnique({ where: { id: next.departmentId } }) : null,
    existing.branchId ? prisma.branch.findUnique({ where: { id: existing.branchId } }) : null,
    next.branchId && next.branchId !== existing.branchId ? prisma.branch.findUnique({ where: { id: next.branchId } }) : null,
    existing.managerId ? prisma.employee.findUnique({ where: { id: existing.managerId } }) : null,
    next.managerId && next.managerId !== existing.managerId ? prisma.employee.findUnique({ where: { id: next.managerId } }) : null,
  ]);

  const displayPrev: Record<string, unknown> = {
    designation: existing.designation,
    departmentId: oldDept?.name ?? null,
    branchId: oldBranch?.name ?? null,
    managerId: oldManager ? `${oldManager.firstName} ${oldManager.lastName}` : null,
  };
  const displayNext: Record<string, unknown> = {
    designation: next.designation,
    departmentId: next.departmentId ? (next.departmentId === existing.departmentId ? oldDept?.name : newDept?.name) ?? null : null,
    branchId: next.branchId ? (next.branchId === existing.branchId ? oldBranch?.name : newBranch?.name) ?? null : null,
    managerId: next.managerId ? (next.managerId === existing.managerId ? `${oldManager?.firstName} ${oldManager?.lastName}` : `${newManager?.firstName} ${newManager?.lastName}`) : null,
  };

  const changes: Record<string, { from: unknown; to: unknown }> = { ...params.extraChanges };
  for (const key of Object.keys(next) as (keyof OrgFields)[]) {
    if (existing[key] !== next[key]) changes[key] = { from: displayPrev[key], to: displayNext[key] };
  }

  await prisma.employee.update({ where: { id: employeeId }, data: next });

  if (Object.keys(changes).length > 0) {
    await recordAudit({
      companyId,
      actorId,
      action: params.auditAction ?? "UPDATE",
      entityType: "Employee",
      entityId: employeeId,
      meta: { changes, ...params.auditMeta },
    });
  }

  return changes;
}

export async function updateEmployee(employeeId: string, formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const existing = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
  if (!existing) throw new Error("Employee not found.");

  const firstName = String(formData.get("firstName") || "").trim();
  const lastName = String(formData.get("lastName") || "").trim();
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const phone = String(formData.get("phone") || "").trim() || null;
  const designation = String(formData.get("designation") || "").trim();
  const departmentId = String(formData.get("departmentId") || "") || null;
  const branchId = String(formData.get("branchId") || "") || null;
  const managerId = String(formData.get("managerId") || "") || null;
  const employmentType = String(formData.get("employmentType") || "FULL_TIME") as EmploymentType;

  if (!firstName || !lastName || !email || !designation) {
    throw new Error("Missing required fields");
  }

  if (managerId) {
    const manager = await prisma.employee.findFirst({ where: { id: managerId, companyId: session.companyId } });
    if (!manager) throw new Error("Manager not found.");
    if (await wouldCreateManagerCycle(employeeId, managerId)) {
      throw new Error("That would create a reporting cycle — this person already reports, directly or indirectly, to the employee you're editing.");
    }
  }
  if (departmentId) {
    const dept = await prisma.department.findFirst({ where: { id: departmentId, companyId: session.companyId } });
    if (!dept) throw new Error("Department not found.");
  }
  if (branchId) {
    const branch = await prisma.branch.findFirst({ where: { id: branchId, companyId: session.companyId } });
    if (!branch) throw new Error("Branch not found.");
  }

  // Contact/personal fields update alongside the org-structure fields
  // (designation/department/branch/manager), which go through the shared
  // diff helper since promotion/transfer approvals touch those too — both
  // sets of changes land in one audit entry via extraChanges.
  const contactPrev: Record<string, unknown> = { firstName: existing.firstName, lastName: existing.lastName, email: existing.email, phone: existing.phone, employmentType: existing.employmentType };
  const contactNext: Record<string, unknown> = { firstName, lastName, email, phone, employmentType };
  const contactChanges: Record<string, { from: unknown; to: unknown }> = {};
  for (const key of Object.keys(contactNext)) {
    if (contactPrev[key] !== contactNext[key]) contactChanges[key] = { from: contactPrev[key], to: contactNext[key] };
  }

  await prisma.employee.update({ where: { id: employeeId }, data: { firstName, lastName, email, phone, employmentType } });

  await applyOrgFieldChanges({
    companyId: session.companyId,
    actorId: session.sub,
    employeeId,
    existing,
    patch: { designation, departmentId, branchId, managerId },
    extraChanges: contactChanges,
  });

  revalidatePath("/dashboard/employees");
  revalidatePath(`/dashboard/employees/${employeeId}`);
  redirect(`/dashboard/employees/${employeeId}`);
}

/**
 * Self-service contact-detail edit (BRD FR-012 — "employee can edit contact
 * details"). Deliberately narrower than `updateEmployee`: phone only, and only
 * for the caller's own record — designation/department/branch/manager/email
 * stay HR/Admin-owned.
 */
export async function updateOwnPhone(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const phone = String(formData.get("phone") || "").trim() || null;

  const existing = await prisma.employee.findUnique({ where: { id: session.employeeId } });
  if (!existing) throw new Error("Employee not found.");
  if (existing.phone === phone) return;

  await prisma.employee.update({ where: { id: session.employeeId }, data: { phone } });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "UPDATE",
    entityType: "Employee",
    entityId: session.employeeId,
    meta: { changes: { phone: { from: existing.phone, to: phone } }, selfService: true },
  });

  revalidatePath(`/dashboard/employees/${session.employeeId}`);
}

export async function updateEmployeeStatus(employeeId: string, status: "ACTIVE" | "ON_LEAVE" | "NOTICE_PERIOD") {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  const existing = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId ?? undefined } });
  if (!existing) throw new Error("Employee not found.");

  // EXITED is reached only through the Exit/Offboarding workflow (resignation
  // → clearance → Full & Final) in lib/actions/exit.ts, never as a plain
  // status flip — it needs the clearance trail and F&F settlement behind it.
  const employee = await prisma.employee.update({
    where: { id: employeeId },
    data: { status },
  });
  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "STATUS_CHANGE",
    entityType: "Employee",
    entityId: employee.id,
    meta: { status },
  });
  revalidatePath("/dashboard/employees");
  revalidatePath(`/dashboard/employees/${employeeId}`);
}

export type ImportRowResult = { row: number; status: "created" | "updated" | "error"; message?: string };
export type ImportEmployeesState = {
  error?: string;
  done?: boolean;
  created?: number;
  updated?: number;
  errors?: ImportRowResult[];
};

const GENDER_VALUES: Gender[] = ["MALE", "FEMALE", "OTHER"];
const EMPLOYMENT_TYPE_VALUES: EmploymentType[] = ["FULL_TIME", "PART_TIME", "CONTRACT", "INTERN"];

/**
 * Bulk import (BRD FR-014). Column order must match `EMPLOYEE_CSV_HEADERS` in
 * lib/employeeCsv.ts — the same file this exports and the template download
 * produce. Processed row-by-row (not one transaction) so one bad row doesn't
 * sink the whole batch; a row with an existing Employee Code updates that
 * record, a blank or unrecognized code creates a new one. Manager linkage is
 * resolved in a second pass once every row's employee exists, so a file can
 * introduce a manager and their reports in the same batch regardless of row
 * order. Status is deliberately never imported — see the comment on
 * EMPLOYEE_CSV_HEADERS.
 */
export async function importEmployees(_prev: ImportEmployeesState, formData: FormData): Promise<ImportEmployeesState> {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");
  const companyId = session.companyId;

  const file = formData.get("file") as File | null;
  if (!file || file.size === 0) return { error: "Choose a CSV file to import." };

  const text = await file.text();
  const allRows = parseCSV(text);
  if (allRows.length < 2) return { error: "The file has no data rows below the header." };
  const dataRows = allRows.slice(1);

  const [departments, branches, existing] = await Promise.all([
    prisma.department.findMany({ where: { companyId }, select: { id: true, name: true } }),
    prisma.branch.findMany({ where: { companyId }, select: { id: true, name: true } }),
    prisma.employee.findMany({ where: { companyId }, select: { id: true, employeeCode: true } }),
  ]);
  const deptByName = new Map(departments.map((d) => [d.name.toLowerCase(), d.id]));
  const branchByName = new Map(branches.map((b) => [b.name.toLowerCase(), b.id]));
  const codeToId = new Map(existing.map((e) => [e.employeeCode, e.id]));
  const codesSeenInFile = new Set<string>();
  let codeCounter = 1001 + existing.length;

  const results: ImportRowResult[] = [];
  const rowEmployeeId: (string | null)[] = new Array(dataRows.length).fill(null);
  const rowManagerCode: (string | null)[] = new Array(dataRows.length).fill(null);

  for (let i = 0; i < dataRows.length; i++) {
    const rowNum = i + 2; // header is row 1, data rows are 1-based after it
    const cols = dataRows[i];
    try {
      const [
        codeCell, firstName, lastName, email, phone, genderCell, dobCell,
        designation, deptCell, branchCell, managerCodeCell, empTypeCell, dojCell,
        bankAccountNumber, bankIfsc, bankName,
      ] = Array.from({ length: 16 }, (_, idx) => (cols[idx] ?? "").trim());

      if (!firstName || !lastName || !email || !designation || !dojCell) {
        throw new Error("First name, last name, email, designation, and date of joining are required.");
      }
      if (codeCell && codesSeenInFile.has(codeCell)) throw new Error(`Employee Code "${codeCell}" appears more than once in this file.`);
      if (codeCell) codesSeenInFile.add(codeCell);

      const doj = new Date(dojCell);
      if (Number.isNaN(doj.getTime())) throw new Error(`Invalid date of joining "${dojCell}" — use YYYY-MM-DD.`);

      let dateOfBirth: Date | null = null;
      if (dobCell) {
        dateOfBirth = new Date(dobCell);
        if (Number.isNaN(dateOfBirth.getTime())) throw new Error(`Invalid date of birth "${dobCell}" — use YYYY-MM-DD.`);
      }

      const genderUpper = genderCell.toUpperCase();
      if (genderCell && !GENDER_VALUES.includes(genderUpper as Gender)) {
        throw new Error(`Invalid gender "${genderCell}" — use MALE, FEMALE, or OTHER.`);
      }

      const employmentTypeUpper = (empTypeCell || "FULL_TIME").toUpperCase();
      if (!EMPLOYMENT_TYPE_VALUES.includes(employmentTypeUpper as EmploymentType)) {
        throw new Error(`Invalid employment type "${empTypeCell}".`);
      }

      let departmentId: string | null = null;
      if (deptCell) {
        departmentId = deptByName.get(deptCell.toLowerCase()) ?? null;
        if (!departmentId) throw new Error(`Department "${deptCell}" not found — create it in Settings first.`);
      }
      let branchId: string | null = null;
      if (branchCell) {
        branchId = branchByName.get(branchCell.toLowerCase()) ?? null;
        if (!branchId) throw new Error(`Branch "${branchCell}" not found — create it in Settings first.`);
      }

      const data = {
        firstName,
        lastName,
        email: email.toLowerCase(),
        phone: phone || null,
        gender: (genderCell ? genderUpper : null) as Gender | null,
        dateOfBirth,
        designation,
        departmentId,
        branchId,
        employmentType: employmentTypeUpper as EmploymentType,
        dateOfJoining: doj,
        bankAccountNumber: bankAccountNumber || null,
        bankIfsc: bankIfsc || null,
        bankName: bankName || null,
      };

      if (codeCell && codeToId.has(codeCell)) {
        const employeeId = codeToId.get(codeCell)!;
        await prisma.employee.update({ where: { id: employeeId }, data });
        rowEmployeeId[i] = employeeId;
        results.push({ row: rowNum, status: "updated" });
      } else {
        const employeeCode = codeCell || `MW-${codeCounter++}`;
        const created = await prisma.employee.create({
          data: {
            ...data,
            companyId,
            employeeCode,
            avatarColor: ["#1f4d3a", "#c1712f", "#2c6a8c", "#7a5a2f", "#5a3a7a"][i % 5],
          },
        });
        codeToId.set(employeeCode, created.id);
        rowEmployeeId[i] = created.id;
        results.push({ row: rowNum, status: "created" });
      }

      if (managerCodeCell) rowManagerCode[i] = managerCodeCell;
    } catch (err) {
      results.push({ row: rowNum, status: "error", message: err instanceof Error ? err.message : "Unknown error." });
    }
  }

  // Second pass: manager linkage, once every row's employee exists.
  for (let i = 0; i < dataRows.length; i++) {
    const managerCode = rowManagerCode[i];
    const employeeId = rowEmployeeId[i];
    if (!managerCode || !employeeId || results[i].status === "error") continue;

    const managerId = codeToId.get(managerCode);
    if (!managerId) {
      results[i] = { row: results[i].row, status: "error", message: `Manager Employee Code "${managerCode}" not found.` };
      continue;
    }
    if (managerId === employeeId) {
      results[i] = { row: results[i].row, status: "error", message: "An employee cannot be their own manager." };
      continue;
    }
    if (await wouldCreateManagerCycle(employeeId, managerId)) {
      results[i] = { row: results[i].row, status: "error", message: `Setting "${managerCode}" as manager would create a reporting cycle.` };
      continue;
    }
    await prisma.employee.update({ where: { id: employeeId }, data: { managerId } });
  }

  const created = results.filter((r) => r.status === "created").length;
  const updated = results.filter((r) => r.status === "updated").length;
  const errors = results.filter((r) => r.status === "error");

  await recordAudit({
    companyId,
    actorId: session.sub,
    action: "BULK_IMPORT",
    entityType: "Employee",
    entityId: companyId,
    meta: { created, updated, errorCount: errors.length, totalRows: dataRows.length },
  });

  revalidatePath("/dashboard/employees");
  return { done: true, created, updated, errors };
}
