import "server-only";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import PizZip from "pizzip";
import Docxtemplater from "docxtemplater";
import PDFDocument from "pdfkit";
import { prisma } from "./prisma";
import type { LetterType } from "@prisma/client";

const DOCUMENT_TYPE_FOR_LETTER: Record<LetterType, "OFFER_LETTER" | "APPOINTMENT_LETTER" | "CONFIRMATION_LETTER" | "PROMOTION_LETTER" | "RELIEVING_LETTER" | "EXPERIENCE_LETTER"> = {
  OFFER: "OFFER_LETTER",
  APPOINTMENT: "APPOINTMENT_LETTER",
  CONFIRMATION: "CONFIRMATION_LETTER",
  PROMOTION: "PROMOTION_LETTER",
  RELIEVING: "RELIEVING_LETTER",
  EXPERIENCE: "EXPERIENCE_LETTER",
};

function formatDate(d: Date | null | undefined) {
  return d ? new Date(d).toLocaleDateString("en-IN", { day: "2-digit", month: "long", year: "numeric" }) : "—";
}

/** {{placeholder}} substitution — the merge fields every letter template can use. */
async function mergeFields(employeeId: string) {
  const employee = await prisma.employee.findUniqueOrThrow({
    where: { id: employeeId },
    include: { company: true, department: true, branch: true, manager: true, salaryStructure: true },
  });

  const ctc = employee.salaryStructure
    ? (employee.salaryStructure.basic + employee.salaryStructure.hra + employee.salaryStructure.conveyance + employee.salaryStructure.medicalAllowance + employee.salaryStructure.specialAllowance) * 12
    : null;

  return {
    employeeName: `${employee.firstName} ${employee.lastName}`,
    firstName: employee.firstName,
    lastName: employee.lastName,
    employeeCode: employee.employeeCode,
    designation: employee.designation,
    department: employee.department?.name ?? "—",
    branch: employee.branch?.name ?? "—",
    manager: employee.manager ? `${employee.manager.firstName} ${employee.manager.lastName}` : "—",
    dateOfJoining: formatDate(employee.dateOfJoining),
    dateOfExit: formatDate(employee.dateOfExit),
    companyName: employee.company.name,
    ctc: ctc != null ? `₹${ctc.toLocaleString("en-IN")} per annum` : "—",
    today: formatDate(new Date()),
  };
}

function substitute(template: string, fields: Record<string, string>) {
  return template.replace(/\{\{(\w+)\}\}/g, (_, key: string) => fields[key] ?? "");
}

/** Renders a LetterTemplate's subject/body text against an employee's data, merge-field style. */
export async function renderLetter(templateId: string, employeeId: string) {
  const template = await prisma.letterTemplate.findUniqueOrThrow({ where: { id: templateId } });
  const fields = await mergeFields(employeeId);
  return { subject: substitute(template.subject, fields), bodyHtml: substitute(template.bodyHtml, fields), type: template.type };
}

async function generatedFileDir(companyId: string) {
  const dir = path.join(process.cwd(), "public", "uploads", companyId, "generated");
  await mkdir(dir, { recursive: true });
  return dir;
}

function safeFileStem(s: string) {
  return s.replace(/[^a-zA-Z0-9_.-]/g, "_").slice(0, 80);
}

/** Fills an admin-uploaded .docx template's {{placeholders}} with the employee's data. */
async function renderDocxTemplate(templateFileUrl: string, fields: Record<string, string>): Promise<Buffer> {
  const absPath = path.join(process.cwd(), "public", templateFileUrl);
  const content = await readFile(absPath);
  const zip = new PizZip(content);
  const doc = new Docxtemplater(zip, {
    paragraphLoop: true,
    linebreaks: true,
    delimiters: { start: "{{", end: "}}" },
    nullGetter: () => "",
  });
  doc.render(fields);
  return doc.getZip().generate({ type: "nodebuffer" });
}

/**
 * Form submission normalizes textarea newlines to CRLF (per the HTML "constructing
 * the form data set" algorithm) before it ever reaches the server. PDFKit's text
 * layout only understands bare "\n" and renders a stray "\r" as a garbage glyph, so
 * every saved subject/body must be normalized back to "\n" before it's drawn.
 */
function normalizeLineEndings(s: string) {
  return s.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
}

/** Draws the built-in professional letter layout (letterhead + subject + body) as a PDF. */
async function renderBuiltInPdf(companyId: string, subject: string, bodyText: string): Promise<Buffer> {
  const company = await prisma.company.findUnique({ where: { id: companyId } });

  let logoBuffer: Buffer | null = null;
  if (company?.logoUrl && company.logoUrl !== "#") {
    try {
      logoBuffer = await readFile(path.join(process.cwd(), "public", company.logoUrl));
    } catch {
      logoBuffer = null; // missing file or a format pdfkit can't decode (only PNG/JPEG) — fall back to the name.
    }
  }

  const hasAddressBlock = !!(company && (company.address || company.phone || company.panNumber));

  /** Draws the logo/name + address block + rule at the current page's top margin. */
  function drawLetterhead(doc: PDFKit.PDFDocument) {
    const top = doc.y;

    if (logoBuffer) {
      try {
        doc.image(logoBuffer, 56, top, { height: 40 });
      } catch {
        doc.font("Helvetica-Bold").fontSize(16).fillColor("#1f4d3a").text(company?.name ?? "", 56, top);
      }
    } else {
      doc.font("Helvetica-Bold").fontSize(16).fillColor("#1f4d3a").text(company?.name ?? "", 56, top);
    }

    if (hasAddressBlock) {
      doc.font("Helvetica").fontSize(9).fillColor("#444444");
      const lines = [company!.address, company!.panNumber ? `PAN No: ${company!.panNumber}` : null, company!.phone ? `Phone No: ${company!.phone}` : null]
        .filter(Boolean) as string[];
      doc.text(lines.join("\n"), 300, top, { width: 239, align: "right" });
    }

    doc.y = Math.max(doc.y, top + (logoBuffer ? 40 : 20)) + 12;
    doc.x = 56;
    doc.strokeColor("#1f4d3a").lineWidth(2).moveTo(56, doc.y).lineTo(539, doc.y).stroke();
    doc.moveDown(1.2);
    doc.x = 56;
  }

  return new Promise<Buffer>((resolve, reject) => {
    const doc = new PDFDocument({ size: "A4", margin: 56 });
    const chunks: Buffer[] = [];
    doc.on("data", (c: Buffer) => chunks.push(c));
    doc.on("end", () => resolve(Buffer.concat(chunks)));
    doc.on("error", reject);

    // PDFKit auto-paginates mid-flow when body text overflows a page; redraw
    // the letterhead on every page it adds after the first, not just page one.
    doc.on("pageAdded", () => drawLetterhead(doc));
    drawLetterhead(doc);

    doc.font("Helvetica-Bold").fontSize(13).fillColor("#1a1a1a").text(normalizeLineEndings(subject), 56, doc.y, { width: 483 });
    doc.moveDown(1);
    doc.font("Helvetica").fontSize(11).fillColor("#1a1a1a").text(normalizeLineEndings(bodyText), 56, doc.y, { width: 483, align: "left", lineGap: 4 });

    doc.end();
  });
}

/** Generates a letter for `employeeId` from the company's template for `type`, storing it as a Document. */
export async function generateLetterForEmployee(companyId: string, employeeId: string, type: LetterType) {
  const template = await prisma.letterTemplate.findUnique({ where: { companyId_type: { companyId, type } } });
  if (!template) return null;

  const fields = await mergeFields(employeeId);
  const subject = substitute(template.subject, fields);
  const bodyText = substitute(template.bodyHtml, fields);
  const dir = await generatedFileDir(companyId);
  const stem = `${Date.now()}-${safeFileStem(subject || type)}`;

  let fileUrl: string;
  if (template.templateFileUrl) {
    try {
      const buffer = await renderDocxTemplate(template.templateFileUrl, fields);
      const filename = `${stem}.docx`;
      await writeFile(path.join(dir, filename), buffer);
      fileUrl = `/uploads/${companyId}/generated/${filename}`;
    } catch (err) {
      // A broken uploaded template (bad {{placeholder}} syntax, corrupt file)
      // must never block the underlying HR workflow (confirmation, exit, manual
      // generate) — fall back to the built-in PDF layout instead of throwing.
      console.error(`Letter template ${template.id} failed to render, falling back to built-in PDF:`, err);
      const buffer = await renderBuiltInPdf(companyId, subject, bodyText);
      const filename = `${stem}.pdf`;
      await writeFile(path.join(dir, filename), buffer);
      fileUrl = `/uploads/${companyId}/generated/${filename}`;
    }
  } else {
    const buffer = await renderBuiltInPdf(companyId, subject, bodyText);
    const filename = `${stem}.pdf`;
    await writeFile(path.join(dir, filename), buffer);
    fileUrl = `/uploads/${companyId}/generated/${filename}`;
  }

  const doc = await prisma.document.create({
    data: {
      companyId,
      employeeId,
      name: subject || `${type} letter`,
      type: DOCUMENT_TYPE_FOR_LETTER[type],
      fileUrl,
    },
  });
  return doc;
}
