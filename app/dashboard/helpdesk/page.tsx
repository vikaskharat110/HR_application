import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, EmptyState } from "@/components/ui";
import { createTicket } from "@/lib/actions/helpdesk";
import { formatDate } from "@/lib/format";
import { LifeBuoy } from "lucide-react";

export const dynamic = "force-dynamic";

const CATEGORIES = ["IT", "Payroll", "Finance", "Facilities", "HR Policy", "Other"];

export default async function HelpdeskPage() {
  const session = await requireModuleAccess("/dashboard/helpdesk");
  const managerial = isManagerial(session);
  const isQueueOwner = ["IT_ADMIN", "PAYROLL_ADMIN", "FINANCE"].includes(session.role);
  const canSeeAll = managerial || isQueueOwner;

  const tickets = await prisma.ticket.findMany({
    where: managerial
      ? { companyId: session.companyId ?? undefined }
      : isQueueOwner
      ? { assignedToId: session.employeeId ?? undefined }
      : { raisedById: session.employeeId ?? undefined },
    include: { raisedBy: true },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div>
      <PageHeader title="Helpdesk" subtitle="Raise a ticket and track resolution." />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Raise a ticket</p>
          {session.employeeId ? (
            <form action={createTicket} className="space-y-3">
              <div>
                <label className="field-label">Category</label>
                <select name="category" className="input">
                  {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              <div>
                <label className="field-label">Priority</label>
                <select name="priority" className="input">
                  <option value="LOW">Low</option>
                  <option value="MEDIUM">Medium</option>
                  <option value="HIGH">High</option>
                  <option value="URGENT">Urgent</option>
                </select>
              </div>
              <div>
                <label className="field-label">Subject</label>
                <input name="subject" required className="input" />
              </div>
              <div>
                <label className="field-label">Description</label>
                <textarea name="description" rows={3} className="input" />
              </div>
              <button type="submit" className="btn btn-primary w-full justify-center">Submit ticket</button>
            </form>
          ) : (
            <p className="text-sm text-muted">This account isn&rsquo;t linked to an employee record, so it can&rsquo;t raise tickets.</p>
          )}
        </div>

        <div className="lg:col-span-2 card overflow-x-auto">
          {tickets.length === 0 ? (
            <EmptyState icon={LifeBuoy} title="No tickets yet" />
          ) : (
            <table className="table-clean">
              <thead><tr><th>Subject</th>{canSeeAll && <th>Raised by</th>}<th>Category</th><th>Priority</th><th>Status</th><th>Opened</th></tr></thead>
              <tbody>
                {tickets.map((t) => (
                  <tr key={t.id}>
                    <td><Link href={`/dashboard/helpdesk/${t.id}`} className="font-medium text-ink hover:underline">{t.subject}</Link></td>
                    {canSeeAll && <td>{t.raisedBy.firstName} {t.raisedBy.lastName}</td>}
                    <td>{t.category}</td>
                    <td><StatusBadge status={t.priority} /></td>
                    <td><StatusBadge status={t.status} /></td>
                    <td>{formatDate(t.createdAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
