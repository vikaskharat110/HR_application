import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { ImportEmployeesForm } from "@/components/ImportEmployeesForm";
import { Download } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function ImportEmployeesPage() {
  await requireRole(["COMPANY_ADMIN", "HR"]);
  await requireModuleAccess("/dashboard/employees");

  return (
    <div className="max-w-xl">
      <BackLink fallbackHref="/dashboard/employees">Back to employees</BackLink>
      <PageHeader
        title="Import employees"
        subtitle="Bulk create or update employee records from a CSV file."
        action={
          <a href="/api/employees/import-template" className="btn btn-secondary">
            <Download size={15} /> Download template
          </a>
        }
      />

      <div className="card p-4 mb-5 text-sm text-ink-soft bg-[var(--color-brand-softer)] border-none">
        Rows with a Employee Code that already exists <b>update</b> that record; a blank or unrecognized code
        <b> creates</b> a new one. Department and Branch must already exist under Settings — a typo won&apos;t
        auto-create one. Status isn&apos;t imported — use the exit or confirmation workflow to change it.
      </div>

      <ImportEmployeesForm />
    </div>
  );
}
