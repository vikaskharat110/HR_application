import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { DocumentRow } from "@/components/DocumentRow";

export const dynamic = "force-dynamic";

export default async function CompanyDocumentsPage() {
  const session = await requireModuleAccess("/dashboard/documents");

  const documents = await prisma.document.findMany({
    where: { companyId: session.companyId ?? undefined, employeeId: null },
    orderBy: { uploadedAt: "desc" },
  });

  return (
    <div>
      <BackLink fallbackHref="/dashboard/documents">Back to documents</BackLink>
      <PageHeader title="Company documents" subtitle="Policies and other files visible to everyone in the company." />

      <div className="card p-5 max-w-2xl">
        {documents.length === 0 ? (
          <p className="text-sm text-muted py-2">No company-wide documents yet.</p>
        ) : (
          <ul className="divide-y divide-border-soft">
            {documents.map((d) => <DocumentRow key={d.id} d={d} viewerRole={session.role} viewerEmployeeId={session.employeeId} />)}
          </ul>
        )}
      </div>
    </div>
  );
}
