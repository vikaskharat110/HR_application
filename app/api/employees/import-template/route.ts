import { NextResponse } from "next/server";
import { getSession } from "@/lib/session";
import { toCSV } from "@/lib/csv";
import { EMPLOYEE_CSV_HEADERS, EMPLOYEE_CSV_TEMPLATE_EXAMPLE } from "@/lib/employeeCsv";

export async function GET() {
  const session = await getSession();
  if (!session || !["COMPANY_ADMIN", "HR"].includes(session.role)) {
    return new NextResponse("Unauthorized", { status: 401 });
  }

  const csv = toCSV([[...EMPLOYEE_CSV_HEADERS], EMPLOYEE_CSV_TEMPLATE_EXAMPLE]);

  return new NextResponse(csv, {
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="employee-import-template.csv"`,
    },
  });
}
