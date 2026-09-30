import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader } from "@/components/ui";
import { GenerateLetterForm } from "@/components/GenerateLetterForm";
import { UploadDocumentForm } from "@/components/UploadDocumentForm";
import { UploadOwnDocumentForm } from "@/components/UploadOwnDocumentForm";
import { LetterTemplateEditor } from "@/components/LetterTemplateEditor";
import { TabbedPanels } from "@/components/TabbedPanels";
import { FolderOpen, Folder, ChevronRight } from "lucide-react";
import type { LetterType } from "@prisma/client";

export const dynamic = "force-dynamic";

const LETTER_TYPES: LetterType[] = ["OFFER", "APPOINTMENT", "CONFIRMATION", "PROMOTION", "RELIEVING", "EXPERIENCE"];
const LETTER_LABEL: Record<LetterType, string> = {
  OFFER: "Offer letter",
  APPOINTMENT: "Appointment letter",
  CONFIRMATION: "Confirmation letter",
  PROMOTION: "Promotion letter",
  RELIEVING: "Relieving letter",
  EXPERIENCE: "Experience letter",
};

export default async function DocumentsPage() {
  const session = await requireModuleAccess("/dashboard/documents");
  const canUpload = session.role === "COMPANY_ADMIN" || session.role === "HR";
  const canSelfUpload = !canUpload && !!session.employeeId;
  const empIds = await visibleEmployeeIds(session);

  const [companyDocCount, employeeDocsByEmployee, employees, templates] = await Promise.all([
    prisma.document.count({ where: { companyId: session.companyId ?? undefined, employeeId: null } }),
    prisma.document.findMany({
      where: { companyId: session.companyId ?? undefined, employeeId: { in: empIds } },
      select: { employeeId: true },
      distinct: ["employeeId"],
    }),
    canUpload
      ? prisma.employee.findMany({
          where: { companyId: session.companyId ?? undefined },
          include: { department: true },
          orderBy: { firstName: "asc" },
        })
      : Promise.resolve([]),
    canUpload
      ? prisma.letterTemplate.findMany({ where: { companyId: session.companyId ?? undefined } })
      : Promise.resolve([]),
  ]);
  const employeeFolderCount = employeeDocsByEmployee.length;

  const tabs: { key: string; label: string; content: React.ReactNode }[] = [];

  if (canUpload) {
    tabs.push({
      key: "upload",
      label: "Upload document",
      content: (
        <div className="card p-6 max-w-2xl">
          <UploadDocumentForm employees={employees} />
        </div>
      ),
    });
  }

  if (canSelfUpload) {
    tabs.push({
      key: "upload-own",
      label: "Upload your document",
      content: (
        <div className="card p-6 max-w-2xl">
          <UploadOwnDocumentForm />
          <p className="text-xs text-muted mt-3">Only visible to you and HR/Admin.</p>
        </div>
      ),
    });
  }

  if (canUpload) {
    tabs.push({
      key: "generate-letter",
      label: "Generate letter",
      content: (
        <div className="card p-6 max-w-2xl">
          <GenerateLetterForm employees={employees} letterTypes={LETTER_TYPES} letterLabel={LETTER_LABEL} />

          <details className="mt-5 pt-5 border-t border-border-soft">
            <summary className="cursor-pointer text-sm text-brand font-medium">Edit letter templates</summary>
            <div className="mt-3">
              <LetterTemplateEditor letterTypes={LETTER_TYPES} letterLabel={LETTER_LABEL} templates={templates} />
            </div>
          </details>
        </div>
      ),
    });
  }

  tabs.push({
    key: "all-documents",
    label: "All documents",
    content: (
      <div className="grid sm:grid-cols-2 gap-5 max-w-2xl">
        <Link href="/dashboard/documents/company" className="card p-6 hover:border-[var(--color-brand)] transition-colors">
          <FolderOpen size={28} className="text-brand mb-3" />
          <p className="text-sm font-semibold text-ink">Company documents</p>
          <p className="text-xs text-muted mt-1 flex items-center gap-1">
            {companyDocCount} file{companyDocCount === 1 ? "" : "s"} <ChevronRight size={13} />
          </p>
        </Link>

        <Link href="/dashboard/documents/employees" className="card p-6 hover:border-[var(--color-brand)] transition-colors">
          <Folder size={28} className="text-brand mb-3" />
          <p className="text-sm font-semibold text-ink">Employee files</p>
          <p className="text-xs text-muted mt-1 flex items-center gap-1">
            {employeeFolderCount} employee{employeeFolderCount === 1 ? "" : "s"} <ChevronRight size={13} />
          </p>
        </Link>
      </div>
    ),
  });

  return (
    <div>
      <PageHeader title="Documents" subtitle="Policies, letters, and employee records." />
      <TabbedPanels tabs={tabs} />
    </div>
  );
}
