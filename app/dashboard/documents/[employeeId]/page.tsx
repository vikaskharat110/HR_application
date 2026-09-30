import { notFound } from "next/navigation";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { DocumentRow } from "@/components/DocumentRow";
import { Folder } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function EmployeeDocumentsPage({ params }: { params: Promise<{ employeeId: string }> }) {
  const { employeeId } = await params;
  const session = await requireModuleAccess("/dashboard/documents");

  const empIds = await visibleEmployeeIds(session);
  if (!empIds.includes(employeeId)) notFound();

  const employee = await prisma.employee.findFirst({
    where: { id: employeeId, companyId: session.companyId ?? undefined },
  });
  if (!employee) notFound();

  const employeeDocs = await prisma.document.findMany({
    where: { companyId: session.companyId ?? undefined, employeeId },
    orderBy: { uploadedAt: "desc" },
  });

  return (
    <div>
      <BackLink fallbackHref="/dashboard/documents">Back to documents</BackLink>
      <PageHeader
        title={`${employee.firstName} ${employee.lastName}`}
        subtitle={employee.designation}
        action={<Avatar firstName={employee.firstName} lastName={employee.lastName} color={employee.avatarColor} />}
      />

      <div className="card p-5 max-w-2xl">
        <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><Folder size={16} className="text-brand" /> {employee.firstName}&rsquo;s files</p>
        {employeeDocs.length === 0 ? (
          <p className="text-sm text-muted py-2">No documents for this employee yet.</p>
        ) : (
          <ul className="divide-y divide-border-soft">
            {employeeDocs.map((d) => <DocumentRow key={d.id} d={d} viewerRole={session.role} viewerEmployeeId={session.employeeId} />)}
          </ul>
        )}
      </div>
    </div>
  );
}
