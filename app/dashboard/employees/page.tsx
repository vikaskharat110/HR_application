import Link from "next/link";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar, EmptyState, Pagination } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { UserPlus, Users, TrendingUp, UserCheck2, Download, UploadCloud } from "lucide-react";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 25;

export default async function EmployeesPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string }>;
}) {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER"]);
  await requireModuleAccess("/dashboard/employees");
  const empIds = await visibleEmployeeIds(session);
  const { page: pageParam } = await searchParams;
  const page = Math.max(1, Number(pageParam) || 1);

  const where = { id: { in: empIds } };

  const [total, employees] = await Promise.all([
    prisma.employee.count({ where }),
    prisma.employee.findMany({
      where,
      include: { department: true, branch: true, manager: true },
      orderBy: { firstName: "asc" },
      take: PAGE_SIZE,
      skip: (page - 1) * PAGE_SIZE,
    }),
  ]);
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  const canCreate = session.role === "COMPANY_ADMIN" || session.role === "HR";

  return (
    <div>
      <PageHeader
        title="Employees"
        subtitle={`${total} people in view`}
        action={
          <div className="flex flex-wrap gap-2">
            <a href="/api/reports/employees" className="btn btn-secondary">
              <Download size={15} /> Export
            </a>
            {canCreate && (
              <Link href="/dashboard/employees/import" className="btn btn-secondary">
                <UploadCloud size={15} /> Import
              </Link>
            )}
            <Link href="/dashboard/employees/confirmations" className="btn btn-secondary">
              <UserCheck2 size={15} /> Confirmations
            </Link>
            <Link href="/dashboard/employees/promotions" className="btn btn-secondary">
              <TrendingUp size={15} /> Promotions & Transfers
            </Link>
            {canCreate && (
              <Link href="/dashboard/employees/new" className="btn btn-primary">
                <UserPlus size={15} /> Add employee
              </Link>
            )}
          </div>
        }
      />

      <div className="card overflow-x-auto">
        {employees.length === 0 ? (
          <EmptyState icon={Users} title="No employees yet" message="Add your first employee to get started." />
        ) : (
          <table className="table-clean">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Designation</th>
                <th>Department</th>
                <th>Branch</th>
                <th>Manager</th>
                <th>Joined</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {employees.map((e) => (
                <tr key={e.id}>
                  <td>
                    <Link href={`/dashboard/employees/${e.id}`} className="flex items-center gap-3 hover:underline">
                      <Avatar firstName={e.firstName} lastName={e.lastName} color={e.avatarColor} />
                      <span>
                        <span className="block font-medium text-ink">{e.firstName} {e.lastName}</span>
                        <span className="block text-xs text-muted">{e.employeeCode}</span>
                      </span>
                    </Link>
                  </td>
                  <td>{e.designation}</td>
                  <td>{e.department?.name ?? "—"}</td>
                  <td>{e.branch?.name ?? "—"}</td>
                  <td>{e.manager ? `${e.manager.firstName} ${e.manager.lastName}` : "—"}</td>
                  <td>{formatDate(e.dateOfJoining)}</td>
                  <td><StatusBadge status={e.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <Pagination page={page} totalPages={totalPages} basePath="/dashboard/employees" />
    </div>
  );
}
