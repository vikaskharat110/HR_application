import { notFound, redirect } from "next/navigation";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { addComment, updateTicket } from "@/lib/actions/helpdesk";
import { formatDateTime } from "@/lib/format";
import { AutoSubmitSelect } from "@/components/AutoSubmitSelect";
import type { TicketStatus } from "@prisma/client";

const STATUS_OPTIONS: TicketStatus[] = ["OPEN", "IN_PROGRESS", "RESOLVED", "CLOSED"];

export const dynamic = "force-dynamic";

export default async function TicketDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireModuleAccess("/dashboard/helpdesk");
  const managerial = isManagerial(session);
  const isQueueOwner = ["IT_ADMIN", "PAYROLL_ADMIN", "FINANCE"].includes(session.role);

  const ticket = await prisma.ticket.findUnique({
    where: { id },
    include: { raisedBy: true, assignedTo: true, comments: { include: { author: true }, orderBy: { createdAt: "asc" } } },
  });
  if (!ticket) notFound();
  const isAssignedQueueOwner = isQueueOwner && ticket.assignedToId === session.employeeId;
  const canUpdateStatus = managerial || isAssignedQueueOwner;
  if (!canUpdateStatus && ticket.raisedById !== session.employeeId) redirect("/dashboard/helpdesk");

  async function postComment(formData: FormData) {
    "use server";
    const message = String(formData.get("message") || "").trim();
    if (message) await addComment(id, message);
  }

  async function setStatus(formData: FormData) {
    "use server";
    await updateTicket(id, formData.get("status") as TicketStatus);
  }

  return (
    <div className="max-w-3xl">
      <BackLink fallbackHref="/dashboard/helpdesk">Back to helpdesk</BackLink>

      <PageHeader
        title={ticket.subject}
        subtitle={`${ticket.category} · raised by ${ticket.raisedBy.firstName} ${ticket.raisedBy.lastName} on ${formatDateTime(ticket.createdAt)}`}
        action={
          <div className="flex items-center gap-2">
            <StatusBadge status={ticket.priority} />
            {canUpdateStatus ? (
              <form action={setStatus}>
                <AutoSubmitSelect name="status" defaultValue={ticket.status} options={STATUS_OPTIONS} className="input w-auto! text-sm" />
              </form>
            ) : (
              <StatusBadge status={ticket.status} />
            )}
          </div>
        }
      />

      <div className="card p-5 mb-5">
        <p className="text-sm text-ink-soft whitespace-pre-line">{ticket.description || "No further description provided."}</p>
        {ticket.assignedTo && <p className="text-xs text-muted mt-3">Assigned to {ticket.assignedTo.firstName} {ticket.assignedTo.lastName}</p>}
      </div>

      <div className="card p-5">
        <p className="text-sm font-semibold text-ink mb-4">Conversation</p>
        <ul className="space-y-4 mb-5">
          {ticket.comments.map((c) => (
            <li key={c.id} className="flex items-start gap-3">
              <Avatar firstName={c.author.firstName} lastName={c.author.lastName} color={c.author.avatarColor} />
              <div>
                <p className="text-sm"><span className="font-medium text-ink">{c.author.firstName} {c.author.lastName}</span> <span className="text-xs text-muted">{formatDateTime(c.createdAt)}</span></p>
                <p className="text-sm text-ink-soft mt-0.5">{c.message}</p>
              </div>
            </li>
          ))}
          {ticket.comments.length === 0 && <p className="text-sm text-muted">No comments yet.</p>}
        </ul>
        <form action={postComment} className="flex gap-2">
          <input name="message" required placeholder="Write a reply…" className="input" />
          <button type="submit" className="btn btn-primary shrink-0">Send</button>
        </form>
      </div>
    </div>
  );
}
