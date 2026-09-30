import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { approvalQueueWhere, isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { requestRegularization, decideRegularization } from "@/lib/actions/attendance";
import { formatDate, formatTime } from "@/lib/format";
import { Check, X, ClipboardList } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function RegularizationsPage() {
  const session = await requireModuleAccess("/dashboard/attendance");
  const managerial = isManagerial(session);

  const [mine, queue] = await Promise.all([
    session.employeeId
      ? prisma.attendanceRegularization.findMany({ where: { employeeId: session.employeeId }, orderBy: { createdAt: "desc" } })
      : Promise.resolve([]),
    managerial
      ? prisma.attendanceRegularization.findMany({
          where: { ...(await approvalQueueWhere(session)), status: "PENDING" },
          include: { employee: true },
          orderBy: { createdAt: "asc" },
        })
      : Promise.resolve([]),
  ]);

  async function decide(formData: FormData) {
    "use server";
    await decideRegularization(String(formData.get("id")), formData.get("approve") === "1");
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/attendance">Back to attendance</BackLink>
      <PageHeader title="Attendance regularizations" subtitle="Fix a missed punch or request a correction — routed to your manager for approval." />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Request a correction</p>
          {session.employeeId ? (
            <form action={requestRegularization} className="space-y-4">
              <div>
                <label className="field-label">Date</label>
                <input type="date" name="date" required max={new Date().toISOString().slice(0, 10)} className="input" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="field-label">Check-in</label>
                  <input type="time" name="checkIn" className="input" />
                </div>
                <div>
                  <label className="field-label">Check-out</label>
                  <input type="time" name="checkOut" className="input" />
                </div>
              </div>
              <div>
                <label className="field-label">Reason</label>
                <textarea name="reason" required rows={3} className="input" placeholder="e.g. Forgot to check out" />
              </div>
              <button type="submit" className="btn btn-primary w-full justify-center">Submit request</button>
            </form>
          ) : (
            <p className="text-sm text-muted">This account isn&rsquo;t linked to an employee record, so it can&rsquo;t submit attendance corrections.</p>
          )}
        </div>

        <div className="lg:col-span-2 space-y-5">
          {managerial && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Awaiting your decision</p>
              {queue.length === 0 ? (
                <EmptyState icon={ClipboardList} title="Nothing pending" />
              ) : (
                <ul className="divide-y divide-border-soft">
                  {queue.map((r) => (
                    <li key={r.id} className="py-3 flex items-start justify-between gap-3">
                      <div className="flex items-start gap-2.5">
                        <Avatar firstName={r.employee.firstName} lastName={r.employee.lastName} color={r.employee.avatarColor} />
                        <div>
                          <p className="text-sm font-medium text-ink">{r.employee.firstName} {r.employee.lastName} · {formatDate(r.date)}</p>
                          <p className="text-xs text-muted mt-0.5">
                            {r.requestedCheckIn ? formatTime(r.requestedCheckIn) : "—"} – {r.requestedCheckOut ? formatTime(r.requestedCheckOut) : "—"}
                          </p>
                          <p className="text-sm text-ink-soft mt-1">{r.reason}</p>
                        </div>
                      </div>
                      <div className="flex gap-1.5 shrink-0">
                        <form action={decide}>
                          <input type="hidden" name="id" value={r.id} />
                          <input type="hidden" name="approve" value="1" />
                          <button className="btn btn-secondary !py-1.5 !px-2.5" title="Approve"><Check size={14} /></button>
                        </form>
                        <form action={decide}>
                          <input type="hidden" name="id" value={r.id} />
                          <input type="hidden" name="approve" value="0" />
                          <button className="btn btn-secondary !py-1.5 !px-2.5 text-danger" title="Reject"><X size={14} /></button>
                        </form>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3">My requests</p>
            {mine.length === 0 ? (
              <p className="text-sm text-muted py-4">You haven&rsquo;t requested any corrections.</p>
            ) : (
              <ul className="divide-y divide-border-soft">
                {mine.map((r) => (
                  <li key={r.id} className="py-3 flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium text-ink">{formatDate(r.date)}</p>
                      <p className="text-xs text-muted mt-0.5">{r.reason}</p>
                    </div>
                    <StatusBadge status={r.status} />
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
