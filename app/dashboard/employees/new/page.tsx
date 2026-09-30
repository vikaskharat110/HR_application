import Link from "next/link";
import { requireRole, ROLE_LABEL, ASSIGNABLE_ROLES } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { createEmployee } from "@/lib/actions/employees";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { UserCheck } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function NewEmployeePage({
  searchParams,
}: {
  searchParams: Promise<{ candidateId?: string }>;
}) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  await requireModuleAccess("/dashboard/employees");
  const { candidateId } = await searchParams;

  const [departments, branches, managers, customRoles] = await Promise.all([
    prisma.department.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.branch.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    // Only employees whose login role is Manager (this also naturally covers
    // any custom role built on top of Manager, e.g. "Team Lead" — a custom
    // role keeps User.role set to its base role) can be picked as a
    // reporting manager here.
    prisma.employee.findMany({
      where: { companyId: session.companyId ?? undefined, user: { role: "MANAGER" } },
      orderBy: { firstName: "asc" },
    }),
    prisma.customRole.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
  ]);

  const candidate = candidateId
    ? await prisma.candidate.findFirst({
        where: { id: candidateId, jobPosting: { companyId: session.companyId ?? undefined } },
        include: { jobPosting: true },
      })
    : null;

  const [prefillFirst, ...prefillLastParts] = candidate?.name.trim().split(/\s+/) ?? [""];
  const prefillLast = prefillLastParts.join(" ");
  const prefillDept = candidate ? departments.find((d) => d.name.toLowerCase() === candidate.jobPosting.department.toLowerCase()) : null;

  return (
    <div className="max-w-2xl">
      <BackLink fallbackHref="/dashboard/employees">Back to employees</BackLink>
      <PageHeader title="Add employee" subtitle="Create an employee master record and, optionally, a login." />

      {candidate && (
        <div className="flex items-center gap-2.5 rounded-lg bg-brand-softer border border-border-soft px-4 py-3 mb-5 text-sm text-ink-soft">
          <UserCheck size={16} className="text-brand shrink-0" />
          Onboarding <b className="text-ink">{candidate.name}</b>, hired for <b className="text-ink">{candidate.jobPosting.title}</b> — details prefilled below.
        </div>
      )}

      <form action={createEmployee} className="card p-6 space-y-5">
        {candidate && <input type="hidden" name="candidateId" value={candidate.id} />}

        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">First name</label>
            <input name="firstName" required defaultValue={prefillFirst} className="input" />
          </div>
          <div>
            <label className="field-label">Last name</label>
            <input name="lastName" required defaultValue={prefillLast} className="input" />
          </div>
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">Work email</label>
            <input name="email" type="email" required defaultValue={candidate?.email} className="input" />
          </div>
          <div>
            <label className="field-label">Designation</label>
            <input name="designation" required defaultValue={candidate?.jobPosting.title} placeholder="e.g. Software Engineer" className="input" />
          </div>
        </div>

        <div className="grid sm:grid-cols-3 gap-4">
          <div>
            <label className="field-label">Department</label>
            <select name="departmentId" defaultValue={prefillDept?.id ?? ""} className="input">
              <option value="">— None —</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">Branch</label>
            <select name="branchId" className="input">
              <option value="">— None —</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">Reporting manager</label>
            <select name="managerId" className="input">
              <option value="">— None —</option>
              {managers.map((m) => <option key={m.id} value={m.id}>{m.firstName} {m.lastName}</option>)}
            </select>
          </div>
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">Employment type</label>
            <select name="employmentType" className="input">
              <option value="FULL_TIME">Full time</option>
              <option value="PART_TIME">Part time</option>
              <option value="CONTRACT">Contract</option>
              <option value="INTERN">Intern</option>
            </select>
          </div>
          <div>
            <label className="field-label">Date of joining</label>
            <input name="dateOfJoining" type="date" required className="input" />
          </div>
        </div>

        <div className="rounded-lg border border-border-soft bg-brand-softer p-4">
          <label className="flex items-center gap-2.5 text-sm font-medium text-ink">
            <input type="checkbox" name="createLogin" defaultChecked className="w-4 h-4 accent-[var(--color-brand)]" />
            Create a portal login for this employee
          </label>
          <div className="mt-3">
            <label className="field-label">Access role</label>
            <select name="role" className="input max-w-xs">
              <optgroup label="Standard roles">
                {ASSIGNABLE_ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
              </optgroup>
              {customRoles.length > 0 && (
                <optgroup label="Custom roles">
                  {customRoles.map((cr) => <option key={cr.id} value={`custom:${cr.id}`}>{cr.name}</option>)}
                </optgroup>
              )}
            </select>
          </div>
          <p className="text-xs text-muted mt-2">Temporary password: <span className="font-mono">Welcome@123</span></p>
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Link href="/dashboard/employees" className="btn btn-secondary">Cancel</Link>
          <button type="submit" className="btn btn-primary">Create employee</button>
        </div>
      </form>
    </div>
  );
}
