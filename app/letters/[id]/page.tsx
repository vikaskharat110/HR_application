import { notFound } from "next/navigation";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { renderLetter } from "@/lib/letters";

export const dynamic = "force-dynamic";

/**
 * Print-friendly letter view, deliberately outside /dashboard so it renders
 * without the sidebar chrome — the project has no PDF library, so "generate
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

  const [{ subject, bodyHtml }, company] = await Promise.all([
    renderLetter(template.id, employeeId),
    prisma.company.findUnique({ where: { id: session.companyId ?? "" } }),
  ]);

  return (
    <div style={{ background: "#fff", color: "#1a1a1a", fontFamily: "Georgia, serif", minHeight: "100vh" }}>
      <div style={{ maxWidth: "720px", margin: "0 auto", padding: "48px 56px" }}>
        {company && (company.logoUrl || company.address || company.phone || company.panNumber) && (
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: "24px", paddingBottom: "16px", marginBottom: "24px", borderBottom: "3px solid #1f4d3a" }}>
            <div>
              {company.logoUrl && company.logoUrl !== "#" ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={company.logoUrl} alt={company.name} style={{ height: "48px", objectFit: "contain" }} />
              ) : (
                <p style={{ fontSize: "18px", fontWeight: 700, margin: 0 }}>{company.name}</p>
              )}
            </div>
            <div style={{ fontSize: "12px", color: "#444", textAlign: "right", lineHeight: 1.5 }}>
              {company.address && <p style={{ margin: 0, whiteSpace: "pre-line" }}>{company.address}</p>}
              {company.panNumber && <p style={{ margin: 0 }}>PAN No: {company.panNumber}</p>}
              {company.phone && <p style={{ margin: 0 }}>Phone No: {company.phone}</p>}
            </div>
          </div>
        )}

        <h1 style={{ fontSize: "20px", marginBottom: "24px" }}>{subject}</h1>
        <div style={{ fontSize: "15px", lineHeight: 1.7, whiteSpace: "pre-line" }} dangerouslySetInnerHTML={{ __html: bodyHtml }} />
        <p style={{ marginTop: "40px", fontSize: "13px", color: "#666" }}>
          Use your browser&apos;s Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this letter.
        </p>
      </div>
    </div>
  );
}

