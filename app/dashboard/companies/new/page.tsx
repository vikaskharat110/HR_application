import { requireRole } from "@/lib/session";
import { NewCompanyForm } from "@/components/NewCompanyForm";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";

export const dynamic = "force-dynamic";

export default async function NewCompanyPage() {
  await requireRole(["SUPER_ADMIN"]);

  return (
    <div className="max-w-lg">
      <BackLink fallbackHref="/dashboard/companies">Back to companies</BackLink>
      <PageHeader title="Provision a new company" />

      <NewCompanyForm />
    </div>
  );
}
