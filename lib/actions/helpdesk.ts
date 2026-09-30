"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole } from "@/lib/session";
import { isManagerial } from "@/lib/scope";
import { recordAudit, notify } from "@/lib/workflow";
import type { TicketPriority, TicketStatus } from "@prisma/client";

/** FR-110: category-based routing to the HR/IT/Payroll/Finance queue that owns that kind of request. */
const CATEGORY_QUEUE_ROLE: Record<string, "IT_ADMIN" | "PAYROLL_ADMIN" | "FINANCE" | "HR"> = {
  IT: "IT_ADMIN",
  Payroll: "PAYROLL_ADMIN",
  Finance: "FINANCE",
};

export async function createTicket(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId || !session.companyId) throw new Error("No employee record linked to this account");

  const category = String(formData.get("category"));
  const queueRole = CATEGORY_QUEUE_ROLE[category] ?? "HR";
  const owner = await prisma.user.findFirst({ where: { companyId: session.companyId, role: queueRole }, include: { employee: true } });

  const ticket = await prisma.ticket.create({
    data: {
      companyId: session.companyId,
      raisedById: session.employeeId,
      category,
      subject: String(formData.get("subject")),
      description: String(formData.get("description") || ""),
      priority: String(formData.get("priority") || "MEDIUM") as TicketPriority,
      assignedToId: owner?.employee?.id,
    },
  });

  if (owner) {
    await notify({ userId: owner.id, title: "New helpdesk ticket", message: `${session.name} raised: ${ticket.subject}`, link: `/dashboard/helpdesk/${ticket.id}` });
  }

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Ticket", entityId: ticket.id });
  revalidatePath("/dashboard/helpdesk");
  redirect(`/dashboard/helpdesk/${ticket.id}`);
}

export async function addComment(ticketId: string, message: string) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const ticket = await prisma.ticket.findFirst({
    where: { id: ticketId, companyId: session.companyId ?? undefined },
    include: { raisedBy: { include: { user: true } }, assignedTo: { include: { user: true } } },
  });
  if (!ticket) throw new Error("Ticket not found.");
  const canComment =
    isManagerial(session) || ticket.raisedById === session.employeeId || ticket.assignedToId === session.employeeId;
  if (!canComment) throw new Error("You are not authorized to comment on this ticket.");

  await prisma.ticketComment.create({ data: { ticketId, authorId: session.employeeId, message } });

  const notifyUserId = [ticket.raisedBy.user?.id, ticket.assignedTo?.user?.id]
    .filter((id) => id && id !== session.sub)[0];
  if (notifyUserId) {
    await notify({ userId: notifyUserId, title: "New comment on your ticket", message, link: `/dashboard/helpdesk/${ticketId}` });
  }

  revalidatePath(`/dashboard/helpdesk/${ticketId}`);
}

export async function updateTicket(ticketId: string, status: TicketStatus) {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "MANAGER", "IT_ADMIN", "PAYROLL_ADMIN", "FINANCE"]);
  const existing = await prisma.ticket.findFirst({ where: { id: ticketId, companyId: session.companyId ?? undefined } });
  if (!existing) throw new Error("Ticket not found.");

  const isQueueOwner = ["IT_ADMIN", "PAYROLL_ADMIN", "FINANCE"].includes(session.role);
  if (isQueueOwner && existing.assignedToId !== session.employeeId) {
    throw new Error("You can only update tickets assigned to your queue.");
  }

  await prisma.ticket.update({
    where: { id: ticketId },
    data: { status, resolvedAt: status === "RESOLVED" || status === "CLOSED" ? new Date() : null },
  });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "STATUS_CHANGE", entityType: "Ticket", entityId: ticketId, meta: { status } });
  revalidatePath(`/dashboard/helpdesk/${ticketId}`);
  revalidatePath("/dashboard/helpdesk");
}
