import Link from "next/link";
import { redirect } from "next/navigation";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { Folder, ChevronRight } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function EmployeeDocumentFoldersPage() {
  const session = await requireModuleAccess("/dashboard/documents");
  const empIds = await visibleEmployeeIds(session);

  // A plain employee's visible scope is always just themselves — a folder
  // list with exactly one entry is a pointless extra click, so skip straight
  // to their own page. Anyone who can see more than one employee (Admin, HR,
  // a Manager with reports) still gets the real folder list below.
  if (empIds.length === 1) {
    redirect(`/dashboard/documents/${empIds[0]}`);
  }

  const documents = await prisma.document.findMany({
    where: { companyId: session.companyId ?? undefined, employeeId: { in: empIds } },
    include: { employee: true },
    orderBy: { uploadedAt: "desc" },
  });

  const employeeFolders = new Map<string, { employee: NonNullable<(typeof documents)[number]["employee"]>; count: number }>();
  for (const d of documents) {
    if (!d.employee) continue;
    const existing = employeeFolders.get(d.employee.id);
    if (existing) existing.count += 1;
    else employeeFolders.set(d.employee.id, { employee: d.employee, count: 1 });
  }
  const folders = Array.from(employeeFolders.values()).sort((a, b) => a.employee.firstName.localeCompare(b.employee.firstName));

  return (
    <div>
      <BackLink fallbackHref="/dashboard/documents">Back to documents</BackLink>
      <PageHeader title="Employee files" subtitle="Each employee's own documents — visible to them, HR, and Admin." />

      <div className="card p-5 max-w-2xl">
        {folders.length === 0 ? (
          <EmptyState icon={Folder} title="No employee-specific documents yet" />
        ) : (
          <ul className="divide-y divide-border-soft">
            {folders.map(({ employee, count }) => (
              <li key={employee.id}>
                <Link href={`/dashboard/documents/${employee.id}`} className="flex items-center justify-between gap-3 py-2.5 hover:bg-brand-softer -mx-1 px-1 rounded-lg">
                  <div className="flex items-center gap-3 min-w-0">
                    <Avatar firstName={employee.firstName} lastName={employee.lastName} color={employee.avatarColor} />
                    <span className="text-sm font-medium text-ink">{employee.firstName} {employee.lastName}</span>
                  </div>
                  <span className="flex items-center gap-1.5 text-xs text-muted shrink-0">
                    {count} file{count === 1 ? "" : "s"} <ChevronRight size={14} />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
