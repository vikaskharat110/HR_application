import Link from "next/link";
import { requireSession, ROLE_LABEL } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { PageHeader } from "@/components/ui";
import { ChangePasswordForm } from "@/components/ChangePasswordForm";
import { formatDate } from "@/lib/format";
import { User, Building2, ArrowRight } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function ProfilePage() {
  const session = await requireSession();

  const employee = session.employeeId
    ? await prisma.employee.findUnique({
        where: { id: session.employeeId },
        include: { department: true, branch: true, manager: true },
      })
    : null;

  return (
    <div>
      <PageHeader title="My profile" subtitle="Your account details and password." />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 space-y-5">
          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><User size={15} /> Account</p>
            <ul className="space-y-2 text-sm">
              <li className="flex justify-between"><span className="text-muted">Name</span><span className="text-ink">{session.name}</span></li>
              <li className="flex justify-between"><span className="text-muted">Email</span><span className="text-ink">{session.email}</span></li>
              <li className="flex justify-between"><span className="text-muted">Role</span><span className="text-ink">{session.customRoleName ?? ROLE_LABEL[session.role]}</span></li>
            </ul>
          </div>

          {employee && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><Building2 size={15} /> Employment</p>
              <ul className="space-y-2 text-sm">
                <li className="flex justify-between"><span className="text-muted">Designation</span><span className="text-ink">{employee.designation}</span></li>
                <li className="flex justify-between"><span className="text-muted">Department</span><span className="text-ink">{employee.department?.name ?? "—"}</span></li>
                <li className="flex justify-between"><span className="text-muted">Branch</span><span className="text-ink">{employee.branch?.name ?? "—"}</span></li>
                <li className="flex justify-between"><span className="text-muted">Manager</span><span className="text-ink">{employee.manager ? `${employee.manager.firstName} ${employee.manager.lastName}` : "—"}</span></li>
                <li className="flex justify-between"><span className="text-muted">Date of joining</span><span className="text-ink">{formatDate(employee.dateOfJoining)}</span></li>
              </ul>
              <Link href={`/dashboard/employees/${employee.id}`} className="inline-flex items-center gap-1.5 text-sm text-brand hover:underline mt-3">
                View full employee record <ArrowRight size={14} />
              </Link>
            </div>
          )}

          <ChangePasswordForm />
        </div>
      </div>
    </div>
  );
}
