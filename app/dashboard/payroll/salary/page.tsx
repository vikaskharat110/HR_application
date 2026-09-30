import { redirect } from "next/navigation";
import { requireSession } from "@/lib/session";
import { requireModuleAccess, hasPayrollAction } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { formatCurrency } from "@/lib/format";
import { SalaryStructureForm } from "@/components/SalaryStructureForm";

export const dynamic = "force-dynamic";

export default async function SalaryStructuresPage() {
  const session = await requireSession();
  await requireModuleAccess("/dashboard/payroll");
  if (!(await hasPayrollAction(session, "EDIT_SALARY"))) redirect("/dashboard/payroll");

  const employees = await prisma.employee.findMany({
    where: { companyId: session.companyId ?? undefined, status: "ACTIVE" },
    include: { salaryStructure: true },
    orderBy: { firstName: "asc" },
  });

  return (
    <div>
      <BackLink fallbackHref="/dashboard/payroll">Back to payroll</BackLink>
      <PageHeader
        title="Salary structures"
        subtitle="Enter the CTC components below — PF, ESI, Professional Tax and TDS are calculated automatically from the company's statutory configuration (Settings → Statutory)."
      />

      <div className="card divide-y divide-border-soft">
        {employees.map((e) => (
          <details key={e.id} className="group px-5 py-3.5">
            <summary className="flex items-center justify-between cursor-pointer list-none">
              <span className="text-sm font-medium text-ink">{e.firstName} {e.lastName} <span className="text-muted font-normal">— {e.designation}</span></span>
              <span className="text-sm text-brand">
                {e.salaryStructure ? `₹${e.salaryStructure.basic.toLocaleString("en-IN")} basic` : "Not set"}
              </span>
            </summary>
            <SalaryStructureForm
              employeeId={e.id}
              monthly={
                e.salaryStructure
                  ? {
                      basic: e.salaryStructure.basic,
                      hra: e.salaryStructure.hra,
                      conveyance: e.salaryStructure.conveyance,
                      medicalAllowance: e.salaryStructure.medicalAllowance,
                      specialAllowance: e.salaryStructure.specialAllowance,
                    }
                  : null
              }
            />
            {e.salaryStructure && (
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-border-soft text-xs text-muted">
                <span>PF (employee): <span className="text-ink font-medium">{formatCurrency(e.salaryStructure.pfEmployee)}</span></span>
                <span>ESI: <span className="text-ink font-medium">{formatCurrency(e.salaryStructure.esi)}</span></span>
                <span>Professional tax: <span className="text-ink font-medium">{formatCurrency(e.salaryStructure.professionalTax)}</span></span>
                <span>TDS: <span className="text-ink font-medium">{formatCurrency(e.salaryStructure.tds)}</span></span>
              </div>
            )}
          </details>
        ))}
      </div>
    </div>
  );
}
