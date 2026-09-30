import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { approvalQueueWhere } from "@/lib/scope";
import { PageHeader, Avatar, EmptyState } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { decideLeave } from "@/lib/actions/leave";
import { formatDate } from "@/lib/format";
import { Check, X, CalendarCheck2 } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function LeaveApprovalsPage() {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER"]);
  await requireModuleAccess("/dashboard/leave");

  const requests = await prisma.leaveRequest.findMany({
    where: { ...(await approvalQueueWhere(session)), status: "PENDING" },
    include: { employee: true, leaveType: true },
    orderBy: { appliedAt: "asc" },
  });

  async function decide(formData: FormData) {
    "use server";
    await decideLeave(String(formData.get("id")), formData.get("approve") === "1");
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/leave">Back to leave</BackLink>
      <PageHeader title="Leave approvals" subtitle="Requests from your reporting line waiting on a decision." />

      <div className="card p-5">
        {requests.length === 0 ? (
          <EmptyState icon={CalendarCheck2} title="All caught up" message="No pending leave requests right now." />
        ) : (
          <ul className="divide-y divide-border-soft">
            {requests.map((r) => (
              <li key={r.id} className="py-4 flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                  <Avatar firstName={r.employee.firstName} lastName={r.employee.lastName} color={r.employee.avatarColor} />
                  <div>
                    <p className="text-sm font-medium text-ink">{r.employee.firstName} {r.employee.lastName}</p>
                    <p className="text-xs text-muted mt-0.5">
                      {r.leaveType.name} · {r.days} day(s) · {formatDate(r.startDate)} – {formatDate(r.endDate)}
                    </p>
                    <p className="text-sm text-ink-soft mt-1.5 max-w-md">{r.reason}</p>
                  </div>
                </div>
                <div className="flex gap-1.5 shrink-0">
                  <form action={decide}>
                    <input type="hidden" name="id" value={r.id} />
                    <input type="hidden" name="approve" value="1" />
                    <button className="btn btn-secondary !py-1.5 !px-2.5"><Check size={14} /> Approve</button>
                  </form>
                  <form action={decide}>
                    <input type="hidden" name="id" value={r.id} />
                    <input type="hidden" name="approve" value="0" />
                    <button className="btn btn-secondary !py-1.5 !px-2.5 text-danger"><X size={14} /> Reject</button>
                  </form>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
