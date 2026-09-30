import { notFound } from "next/navigation";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { monthName } from "@/lib/format";

export const dynamic = "force-dynamic";

const STAFF_ROLES = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN", "FINANCE"];

type Breakdown = {
  basic: number; hra: number; conveyance: number; medicalAllowance: number; specialAllowance: number;
  pfEmployee: number; esi: number; professionalTax: number; tds: number; lopDays: number; lopDeduction: number;
};

/**
 * Print-friendly payslip, outside /dashboard so it renders without the sidebar
 * chrome — same "no PDF library, use the browser's print-to-PDF" approach as
 * /letters/[id]. An employee can only reach their own; staff can reach any
 * payslip in their company.
 */
export default async function PayslipPrintPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireSession();

  const payslip = await prisma.payslip.findFirst({
    where: { id, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: { include: { company: true, department: true } }, payrollRun: true },
  });
  if (!payslip) notFound();

  const isSelf = session.employeeId === payslip.employeeId;
  const isStaff = STAFF_ROLES.includes(session.role);
  if (!isSelf && !isStaff) notFound();
  // An employee can only see their own payslip once the run is locked and
  // published — matches the same restriction already applied to "My payslips".
  if (isSelf && !isStaff && payslip.payrollRun.status !== "LOCKED") notFound();

  const raw = (payslip.breakdown ?? {}) as Partial<Breakdown>;
  const b: Breakdown = {
    basic: raw.basic ?? 0,
    hra: raw.hra ?? 0,
    conveyance: raw.conveyance ?? 0,
    medicalAllowance: raw.medicalAllowance ?? 0,
    specialAllowance: raw.specialAllowance ?? 0,
    pfEmployee: raw.pfEmployee ?? 0,
    esi: raw.esi ?? 0,
    professionalTax: raw.professionalTax ?? 0,
    tds: raw.tds ?? 0,
    lopDays: raw.lopDays ?? 0,
    lopDeduction: raw.lopDeduction ?? 0,
  };
  const { employee } = payslip;
  const period = `${monthName(payslip.payrollRun.month)} ${payslip.payrollRun.year}`;

  return (
    <div style={{ background: "#fff", color: "#1a1a1a", fontFamily: "Georgia, serif", minHeight: "100vh" }}>
      <div style={{ maxWidth: "640px", margin: "0 auto", padding: "48px 56px" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", borderBottom: "2px solid #1a1a1a", paddingBottom: "16px", marginBottom: "24px" }}>
          <div>
            <h1 style={{ fontSize: "20px", margin: 0 }}>{employee.company.name}</h1>
            <p style={{ fontSize: "13px", color: "#666", margin: "4px 0 0" }}>Payslip — {period}</p>
          </div>
          <p style={{ fontSize: "12px", color: "#666", margin: 0 }}>Generated {new Date().toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })}</p>
        </div>

        <table style={{ width: "100%", fontSize: "14px", marginBottom: "28px", borderCollapse: "collapse" }}>
          <tbody>
            <Row label="Employee" value={`${employee.firstName} ${employee.lastName}`} />
            <Row label="Employee code" value={employee.employeeCode} />
            <Row label="Designation" value={employee.designation} />
            <Row label="Department" value={employee.department?.name ?? "—"} />
            <Row label="Paid days" value={`${payslip.paidDays} / ${payslip.workingDays}`} />
            {payslip.lopDays > 0 && <Row label="Loss of pay" value={`${payslip.lopDays} day(s)`} />}
          </tbody>
        </table>

        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "32px" }}>
          <div>
            <p style={{ fontSize: "12px", textTransform: "uppercase", letterSpacing: "0.05em", color: "#666", marginBottom: "8px" }}>Earnings</p>
            <LineItem label="Basic" value={b.basic} />
            <LineItem label="HRA" value={b.hra} />
            <LineItem label="Conveyance" value={b.conveyance} />
            <LineItem label="Medical allowance" value={b.medicalAllowance} />
            <LineItem label="Special allowance" value={b.specialAllowance} />
            <LineItem label="Gross pay" value={payslip.grossPay ?? 0} bold border />
          </div>
          <div>
            <p style={{ fontSize: "12px", textTransform: "uppercase", letterSpacing: "0.05em", color: "#666", marginBottom: "8px" }}>Deductions</p>
            <LineItem label="Provident Fund" value={b.pfEmployee} />
            <LineItem label="ESI" value={b.esi} />
            <LineItem label="Professional Tax" value={b.professionalTax} />
            <LineItem label="TDS" value={b.tds} />
            {b.lopDeduction > 0 && <LineItem label="Loss of pay" value={b.lopDeduction} />}
            <LineItem label="Total deductions" value={payslip.totalDeductions ?? 0} bold border />
          </div>
        </div>

        <div style={{ marginTop: "28px", paddingTop: "16px", borderTop: "2px solid #1a1a1a", display: "flex", justifyContent: "space-between", alignItems: "baseline" }}>
          <span style={{ fontSize: "15px", fontWeight: 700 }}>Net pay</span>
          <span style={{ fontSize: "22px", fontWeight: 700 }}>₹{(payslip.netPay ?? 0).toLocaleString("en-IN")}</span>
        </div>

        {payslip.isHeld && (
          <div style={{ marginTop: "16px", padding: "12px 16px", background: "#fdecea", border: "1px solid #d9534f", borderRadius: "6px", fontSize: "13px", color: "#a02a20" }}>
            <strong>Payment on hold.</strong> This amount has been calculated but is not being disbursed{payslip.holdReason ? ` — ${payslip.holdReason}` : "."} Contact HR for details.
          </div>
        )}

        <p style={{ marginTop: "40px", fontSize: "13px", color: "#666" }}>
          Use your browser&apos;s Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this payslip.
        </p>
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <tr>
      <td style={{ padding: "3px 0", color: "#666", width: "140px" }}>{label}</td>
      <td style={{ padding: "3px 0" }}>{value}</td>
    </tr>
  );
}

function LineItem({ label, value, bold, border }: { label: string; value: number; bold?: boolean; border?: boolean }) {
  return (
    <div
      style={{
        display: "flex", justifyContent: "space-between", fontSize: "14px", padding: "4px 0",
        fontWeight: bold ? 700 : 400,
        borderTop: border ? "1px solid #ccc" : "none",
        marginTop: border ? "4px" : 0,
      }}
    >
      <span>{label}</span>
      <span>₹{value.toLocaleString("en-IN")}</span>
    </div>
  );
}
