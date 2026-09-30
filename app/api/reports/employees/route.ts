import { NextResponse } from "next/server";
import { getSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { toCSV } from "@/lib/csv";
import { EMPLOYEE_CSV_HEADERS, employeeToRow } from "@/lib/employeeCsv";

export async function GET() {
  const session = await getSession();
  if (!session || !["COMPANY_ADMIN", "HR", "MANAGER", "AUDITOR", "MANAGEMENT", "PAYROLL_ADMIN", "RECRUITER"].includes(session.role)) {
    return new NextResponse("Unauthorized", { status: 401 });
  }

  const employees = await prisma.employee.findMany({
    where: { companyId: session.companyId ?? undefined },
    include: { department: true, branch: true, manager: { select: { employeeCode: true } } },
    orderBy: { firstName: "asc" },
  });

  const csv = toCSV([[...EMPLOYEE_CSV_HEADERS], ...employees.map(employeeToRow)]);

  return new NextResponse(csv, {
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="employees.csv"`,
    },
  });
}
