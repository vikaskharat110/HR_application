import { NextRequest, NextResponse } from "next/server";
import { getSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";

const ALLOWED_ROLES = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN", "FINANCE", "AUDITOR", "MANAGEMENT"];

export async function GET(req: NextRequest) {
  const session = await getSession();
  if (!session || !ALLOWED_ROLES.includes(session.role)) {
    return new NextResponse("Unauthorized", { status: 401 });
  }

  const runId = req.nextUrl.searchParams.get("runId");
  const format = req.nextUrl.searchParams.get("format");

  const run = await prisma.payrollRun.findFirst({
    where: { id: runId ?? undefined, companyId: session.companyId ?? undefined },
    orderBy: runId ? undefined : [{ year: "desc" }, { month: "desc" }],
    include: { payslips: { include: { employee: true }, orderBy: { employee: { firstName: "asc" } } } },
  });

  if (!run) return new NextResponse("No payroll runs yet", { status: 404 });

  if (format === "bank-file") {
    if (!["PAYROLL_ADMIN", "COMPANY_ADMIN"].includes(session.role)) {
      return new NextResponse("Unauthorized", { status: 401 });
    }
    if (run.status !== "LOCKED") {
      return new NextResponse("Bank file can only be generated for a locked payroll run.", { status: 400 });
    }
    const header = ["Employee Code", "Name", "Bank Account Number", "IFSC", "Net Pay"];
    const rows = run.payslips.map((p) => [
      p.employee.employeeCode,
      `${p.employee.firstName} ${p.employee.lastName}`,
      p.employee.bankAccountNumber ?? "",
      p.employee.bankIfsc ?? "",
      p.netPay.toFixed(2),
    ]);
    const csv = [header, ...rows].map((r) => r.map(csvEscape).join(",")).join("\r\n");
    return new NextResponse(csv, {
      headers: {
        "Content-Type": "text/csv; charset=utf-8",
        "Content-Disposition": `attachment; filename="bank-file-${run.month}-${run.year}.csv"`,
      },
    });
  }

  const header = ["Employee Code", "Name", "Gross Pay", "Deductions", "Net Pay", "LOP Days"];
  const rows = run.payslips.map((p) => [
    p.employee.employeeCode,
    `${p.employee.firstName} ${p.employee.lastName}`,
    p.grossPay.toFixed(2), p.totalDeductions.toFixed(2), p.netPay.toFixed(2), String(p.lopDays),
  ]);

  const csv = [header, ...rows].map((r) => r.map(csvEscape).join(",")).join("\r\n");

  return new NextResponse(csv, {
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="payroll-${run.month}-${run.year}.csv"`,
    },
  });
}

function csvEscape(value: string) {
  if (/[",\n]/.test(value)) return `"${value.replace(/"/g, '""')}"`;
  return value;
}
