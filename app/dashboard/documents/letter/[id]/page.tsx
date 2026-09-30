import { notFound } from "next/navigation";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { renderLetter } from "@/lib/letters";

export const dynamic = "force-dynamic";

/**
 * Print-friendly letter view — the project has no PDF library, so "generate
 * a letter" means render it here and let the browser's native print dialog
 * save it as a PDF (BRD 8.15: template-based letter generation).
 */
export default async function LetterPrintPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ employeeId?: string }>;
}) {
  const { id } = await params;
  const { employeeId } = await searchParams;
  const session = await requireSession();
  if (!employeeId) notFound();

  const empIds = await visibleEmployeeIds(session);
  const canManage = session.role === "COMPANY_ADMIN" || session.role === "HR";
  if (!canManage && !empIds.includes(employeeId)) notFound();

  const template = await prisma.letterTemplate.findFirst({ where: { id, companyId: session.companyId ?? undefined } });
  if (!template) notFound();

  const { subject, bodyHtml } = await renderLetter(template.id, employeeId);

  return (
    <html lang="en">
      <body style={{ margin: 0, background: "#fff", color: "#1a1a1a", fontFamily: "Georgia, serif" }}>
        <div style={{ maxWidth: "720px", margin: "0 auto", padding: "48px 56px" }}>
          <h1 style={{ fontSize: "20px", marginBottom: "24px" }}>{subject}</h1>
          <div style={{ fontSize: "15px", lineHeight: 1.7, whiteSpace: "pre-line" }} dangerouslySetInnerHTML={{ __html: bodyHtml }} />
          <p style={{ marginTop: "40px", fontSize: "13px", color: "#666" }}>
            Use your browser&apos;s Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this letter.
          </p>
        </div>
      </body>
    </html>
  );
}
