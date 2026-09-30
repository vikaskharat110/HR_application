import "server-only";

/**
 * The single column schema shared by export, the downloadable template, and
 * import — keeping all three in lockstep is the whole point of a round-trip
 * CSV format. Manager is referenced by Employee Code (not name) so re-import
 * is unambiguous. Status is exported for reference only and never imported —
 * status transitions go through Confirmation/Exit workflows, not a raw
 * overwrite, so letting a CSV set it would bypass clearance/F&F entirely.
 */
export const EMPLOYEE_CSV_HEADERS = [
  "Employee Code",
  "First Name",
  "Last Name",
  "Email",
  "Phone",
  "Gender",
  "Date of Birth",
  "Designation",
  "Department",
  "Branch",
  "Manager Employee Code",
  "Employment Type",
  "Date of Joining",
  "Bank Account Number",
  "Bank IFSC",
  "Bank Name",
  "Status (reference only, not imported)",
] as const;

function isoDate(d: Date | null | undefined): string {
  return d ? d.toISOString().slice(0, 10) : "";
}

export function employeeToRow(e: {
  employeeCode: string;
  firstName: string;
  lastName: string;
  email: string;
  phone: string | null;
  gender: string | null;
  dateOfBirth: Date | null;
  designation: string;
  department: { name: string } | null;
  branch: { name: string } | null;
  manager: { employeeCode: string } | null;
  employmentType: string;
  dateOfJoining: Date;
  bankAccountNumber: string | null;
  bankIfsc: string | null;
  bankName: string | null;
  status: string;
}): (string | number)[] {
  return [
    e.employeeCode,
    e.firstName,
    e.lastName,
    e.email,
    e.phone ?? "",
    e.gender ?? "",
    isoDate(e.dateOfBirth),
    e.designation,
    e.department?.name ?? "",
    e.branch?.name ?? "",
    e.manager?.employeeCode ?? "",
    e.employmentType,
    isoDate(e.dateOfJoining),
    e.bankAccountNumber ?? "",
    e.bankIfsc ?? "",
    e.bankName ?? "",
    e.status,
  ];
}

export const EMPLOYEE_CSV_TEMPLATE_EXAMPLE: (string | number)[] = [
  "",
  "Asha",
  "Verma",
  "asha.verma@example.com",
  "9876543210",
  "FEMALE",
  "1996-04-12",
  "Software Engineer",
  "Engineering",
  "Pune HQ",
  "",
  "FULL_TIME",
  "2026-09-15",
  "",
  "",
  "",
  "",
];
