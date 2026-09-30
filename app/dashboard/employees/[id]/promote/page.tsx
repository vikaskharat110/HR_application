import Link from "next/link";
import { notFound } from "next/navigation";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { requestPromotionTransfer } from "@/lib/actions/promotions";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";

export const dynamic = "force-dynamic";

export default async function PromoteTransferPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  await requireModuleAccess("/dashboard/employees");

  const [employee, departments, branches, managers] = await Promise.all([
    prisma.employee.findFirst({ where: { id, companyId: session.companyId ?? undefined }, include: { salaryStructure: true } }),
    prisma.department.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.branch.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.employee.findMany({
      where: { companyId: session.companyId ?? undefined, id: { not: id } },
      orderBy: { firstName: "asc" },
    }),
  ]);
  if (!employee) notFound();

  return (
    <div className="max-w-2xl">
      <BackLink fallbackHref={`/dashboard/employees/${id}`}>Back to profile</BackLink>
      <PageHeader
        title={`Promote / Transfer — ${employee.firstName} ${employee.lastName}`}
        subtitle="Leave a field unchanged to keep the employee's current value. Goes to their manager (or HR/Admin) for approval before anything changes."
      />

      <form action={requestPromotionTransfer} className="card p-6 space-y-5">
        <input type="hidden" name="employeeId" value={id} />

        <div>
          <label className="field-label">Type</label>
          <select name="type" className="input max-w-xs">
            <option value="PROMOTION">Promotion</option>
            <option value="TRANSFER">Transfer</option>
            <option value="BOTH">Both</option>
          </select>
        </div>

        <div>
          <label className="field-label">New designation <span className="text-muted font-normal">(current: {employee.designation})</span></label>
          <input name="newDesignation" placeholder="Leave blank for no change" className="input" />
        </div>

        <div className="grid sm:grid-cols-3 gap-4">
          <div>
            <label className="field-label">New department</label>
            <select name="newDepartmentId" className="input">
              <option value="">No change</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">New branch</label>
            <select name="newBranchId" className="input">
              <option value="">No change</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label">New manager</label>
            <select name="newManagerId" className="input">
              <option value="">No change</option>
              {managers.map((m) => <option key={m.id} value={m.id}>{m.firstName} {m.lastName}</option>)}
            </select>
          </div>
        </div>

        <div>
          <label className="field-label">
            New basic salary <span className="text-muted font-normal">(current: {employee.salaryStructure ? `₹${employee.salaryStructure.basic.toLocaleString("en-IN")}` : "not set"})</span>
          </label>
          <input name="newBasic" type="number" min={0} placeholder="Leave blank for no change" className="input max-w-xs" />
        </div>

        <div className="grid sm:grid-cols-2 gap-4">
          <div>
            <label className="field-label">Effective date</label>
            <input name="effectiveDate" type="date" required className="input" />
          </div>
        </div>

        <div>
          <label className="field-label">Reason</label>
          <textarea name="reason" required rows={3} className="input" />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <Link href={`/dashboard/employees/${id}`} className="btn btn-secondary">Cancel</Link>
          <button type="submit" className="btn btn-primary">Submit for approval</button>
        </div>
      </form>
    </div>
  );
}
