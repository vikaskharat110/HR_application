import Link from "next/link";
import { notFound } from "next/navigation";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { updateEmployee } from "@/lib/actions/employees";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";

export const dynamic = "force-dynamic";

export default async function EditEmployeePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  await requireModuleAccess("/dashboard/employees");

  const [employee, departments, branches, managers] = await Promise.all([
    prisma.employee.findFirst({ where: { id, companyId: session.companyId ?? undefined }, include: { manager: true } }),
    prisma.department.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.branch.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    // Only employees whose login role is Manager (also covers a custom role
    // built on top of Manager, e.g. "Team Lead") are selectable going
    // forward — see app/dashboard/employees/new/page.tsx for the same rule.
    prisma.employee.findMany({
      where: { companyId: session.companyId ?? undefined, id: { not: id }, user: { role: "MANAGER" } },
      orderBy: { firstName: "asc" },
    }),
  ]);
  if (!employee) notFound();

  // If this employee already reports to someone who isn't a Manager (common
  // in this data — plenty of people report straight to the Company Admin or
  // HR), keep that person selectable too, so re-saving the form without
  // touching this field doesn't silently reassign them to whoever happens
  // to be first in the filtered list.
  const managerOptions =
    employee.manager && !managers.some((m) => m.id === employee.manager!.id)
      ? [...managers, employee.manager].sort((a, b) => a.firstName.localeCompare(b.firstName))
      : managers;

  const updateWithId = updateEmployee.bind(null, id);

  return (
    <div className="max-w-2xl">
      <BackLink fallbackHref={`/dashboard/employees/${id}`}>Back to profile</BackLink>
      <PageHeader title={`Edit ${employee.firstName} ${employee.lastName}`} subtitle={employee.employeeCode} />

      <form action={updateWithId} className="card p-6 space-y-5">
        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">First name</label>
            <input name="firstName" required defaultValue={employee.firstName} className="input" />
          </div>
          <div>
            <label className="field-label">Last name</label>
            <input name="lastName" required defaultValue={employee.lastName} className="input" />
          </div>
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">Work email</label>
            <input name="email" type="email" required defaultValue={employee.email} className="input" />
          </div>
          <div>
            <label className="field-label">Phone</label>
            <input name="phone" defaultValue={employee.phone ?? ""} className="input" />
          </div>
        </div>

        <div>
          <label className="field-label">Designation</label>
          <input name="designation" required defaultValue={employee.designation} className="input" />
        </div>

        <div className="grid sm:grid-cols-3 gap-4">
          <div>
            <label className="field-label">Department</label>
            <select name="departmentId" defaultValue={employee.departmentId ?? ""} className="input">
              <option value="">— None —</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">Branch</label>
            <select name="branchId" defaultValue={employee.branchId ?? ""} className="input">
              <option value="">— None —</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">Reporting manager</label>
            <select name="managerId" defaultValue={employee.managerId ?? ""} className="input">
              <option value="">— None —</option>
              {managerOptions.map((m) => <option key={m.id} value={m.id}>{m.firstName} {m.lastName}</option>)}
            </select>
          </div>
        </div>

        <div>
          <label className="field-label">Employment type</label>
          <select name="employmentType" defaultValue={employee.employmentType} className="input max-w-xs">
            <option value="FULL_TIME">Full time</option>
            <option value="PART_TIME">Part time</option>
            <option value="CONTRACT">Contract</option>
            <option value="INTERN">Intern</option>
          </select>
        </div>

        <p className="text-xs text-muted">
          Employee code, date of joining, and status aren&rsquo;t edited here — status changes from the profile page.
        </p>

        <div className="flex justify-end gap-2 pt-1">
          <Link href={`/dashboard/employees/${id}`} className="btn btn-secondary">Cancel</Link>
          <button type="submit" className="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  );
}
