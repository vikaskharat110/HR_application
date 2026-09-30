import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { getStatutoryConfig } from "@/lib/statutory";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { updateStatutoryConfig } from "@/lib/actions/settings";

export const dynamic = "force-dynamic";

export default async function StatutoryConfigPage() {
  const session = await requireRole(["COMPANY_ADMIN", "PAYROLL_ADMIN"]);
  await requireModuleAccess("/dashboard/settings");
  if (!session.companyId) throw new Error("No company context");

  const config = await getStatutoryConfig(session.companyId);

  return (
    <div>
      <BackLink fallbackHref="/dashboard/settings">Back to settings</BackLink>
      <PageHeader
        title="Statutory configuration"
        subtitle="PF, ESI, Professional Tax and TDS rules used to auto-calculate salary structures — configurable per company, not fixed."
      />

      <form action={updateStatutoryConfig} className="card p-6 space-y-6 max-w-3xl">
        <div>
          <p className="text-sm font-semibold text-ink mb-3">Provident Fund (PF)</p>
          <div className="grid sm:grid-cols-3 gap-3">
            <Field name="pfEmployeeRate" label="Employee rate" step="0.001" defaultValue={config.pfEmployeeRate} />
            <Field name="pfEmployerRate" label="Employer rate" step="0.001" defaultValue={config.pfEmployerRate} />
            <Field name="pfWageCeiling" label="Wage ceiling (₹)" step="1" defaultValue={config.pfWageCeiling} />
          </div>
        </div>

        <div>
          <p className="text-sm font-semibold text-ink mb-3">Employee State Insurance (ESI)</p>
          <div className="grid sm:grid-cols-3 gap-3">
            <Field name="esiEmployeeRate" label="Employee rate" step="0.0001" defaultValue={config.esiEmployeeRate} />
            <Field name="esiEmployerRate" label="Employer rate" step="0.0001" defaultValue={config.esiEmployerRate} />
            <Field name="esiWageCeiling" label="Wage ceiling (₹)" step="1" defaultValue={config.esiWageCeiling} />
          </div>
        </div>

        <div>
          <label className="field-label">Professional Tax slabs (JSON — monthly basic → amount)</label>
          <textarea
            name="ptSlabs"
            rows={4}
            className="input font-mono text-xs"
            defaultValue={JSON.stringify(config.ptSlabs, null, 2)}
          />
        </div>

        <div>
          <label className="field-label">TDS slabs (JSON — annualized basic → monthly deduction)</label>
          <textarea
            name="tdsSlabs"
            rows={4}
            className="input font-mono text-xs"
            defaultValue={JSON.stringify(config.tdsSlabs, null, 2)}
          />
        </div>

        <button type="submit" className="btn btn-primary">Save statutory configuration</button>
      </form>
    </div>
  );
}

function Field({ name, label, defaultValue, step }: { name: string; label: string; defaultValue: number; step: string }) {
  return (
    <div>
      <label className="field-label">{label}</label>
      <input type="number" name={name} step={step} min={0} defaultValue={defaultValue} className="input" />
    </div>
  );
}
