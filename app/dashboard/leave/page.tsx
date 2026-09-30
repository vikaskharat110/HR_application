import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge } from "@/components/ui";
import { applyLeave, cancelLeave } from "@/lib/actions/leave";
import { formatDate } from "@/lib/format";
import { CalendarCheck2, X } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function LeavePage() {
  const session = await requireModuleAccess("/dashboard/leave");
  const managerial = isManagerial(session);

  const [leaveTypes, balances, myRequests] = await Promise.all([
    prisma.leaveType.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    session.employeeId
      ? prisma.leaveBalance.findMany({
          where: { employeeId: session.employeeId, year: new Date().getFullYear() },
          include: { leaveType: true },
        })
      : Promise.resolve([]),
    session.employeeId
      ? prisma.leaveRequest.findMany({
          where: { employeeId: session.employeeId },
          include: { leaveType: true },
          orderBy: { appliedAt: "desc" },
        })
      : Promise.resolve([]),
  ]);

  async function cancel(formData: FormData) {
    "use server";
    await cancelLeave(String(formData.get("id")));
  }

  return (
    <div>
      <PageHeader
        title="Leave"
        subtitle="Apply for time off and track your balances."
        action={
          managerial && (
            <Link href="/dashboard/leave/approvals" className="btn btn-secondary">
              <CalendarCheck2 size={15} /> Approvals queue
            </Link>
          )
        }
      />

      <div className="grid sm:grid-cols-3 gap-4 mb-6">
        {balances.map((b) => (
          <div key={b.id} className="stat-card">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted">{b.leaveType.name}</p>
            <p className="font-display text-2xl font-semibold text-ink mt-1.5">
              {b.allocated - b.used}
              <span className="text-sm font-normal text-muted"> / {b.allocated} days left</span>
            </p>
          </div>
        ))}
      </div>

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Apply for leave</p>
          {session.employeeId ? (
            <form action={applyLeave} className="space-y-4">
              <div>
                <label className="field-label">Leave type</label>
                <select name="leaveTypeId" required className="input">
                  {leaveTypes.map((lt) => <option key={lt.id} value={lt.id}>{lt.name}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="field-label">From</label>
                  <input type="date" name="startDate" required className="input" />
                </div>
                <div>
                  <label className="field-label">To</label>
                  <input type="date" name="endDate" required className="input" />
                </div>
              </div>
              <div>
                <label className="field-label">Reason</label>
                <textarea name="reason" required rows={3} className="input" />
              </div>
              <button type="submit" className="btn btn-primary w-full justify-center">Submit request</button>
            </form>
          ) : (
            <p className="text-sm text-muted">This account isn&rsquo;t linked to an employee record, so it can&rsquo;t apply for leave.</p>
          )}
        </div>

        <div className="lg:col-span-2 card p-5">
          <p className="text-sm font-semibold text-ink mb-3">My requests</p>
          <table className="table-clean">
            <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th></th></tr></thead>
            <tbody>
              {myRequests.map((r) => (
                <tr key={r.id}>
                  <td>{r.leaveType.name}</td>
                  <td>{formatDate(r.startDate)} – {formatDate(r.endDate)}</td>
                  <td>{r.days}</td>
                  <td><StatusBadge status={r.status} /></td>
                  <td>
                    {r.status === "PENDING" && (
                      <form action={cancel}>
                        <input type="hidden" name="id" value={r.id} />
                        <button className="text-muted hover:text-danger" title="Cancel"><X size={15} /></button>
                      </form>
                    )}
                  </td>
                </tr>
              ))}
              {myRequests.length === 0 && (
                <tr><td colSpan={5} className="text-center text-muted py-6">No leave requests yet.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
