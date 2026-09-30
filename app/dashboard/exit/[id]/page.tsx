import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { requireSession } from "@/lib/session";
import { hasModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { PageHeader, Avatar, StatusBadge } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { formatCurrency, formatDate } from "@/lib/format";
import {
  reviewExitAsManager, approveExitAsHR, clearDepartment,
  generateFullFinal, approveFullFinal, recordExitInterview,
} from "@/lib/actions/exit";
import { CheckCircle2, Circle } from "lucide-react";
import type { ExitDepartment } from "@prisma/client";

export const dynamic = "force-dynamic";

const DEPARTMENT_LABEL: Record<ExitDepartment, string> = {
  MANAGER: "Manager",
  HR: "HR",
  IT: "IT",
  FINANCE: "Finance",
  ASSET: "Asset",
};

export default async function ExitDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  // Not requireModuleAccess: the Exit/Offboarding *queue* is staff-only, but the
  // employee whose own exit this is must still be able to see its status — that's
  // exactly what they're sent here for right after submitting a resignation.
  const session = await requireSession();

  const exit = await prisma.employeeExit.findFirst({
    where: { id, employee: { companyId: session.companyId ?? undefined } },
    include: { employee: true, clearances: true, settlement: true },
  });
  if (!exit) notFound();

  const isOwner = session.employeeId === exit.employeeId;
  const staffAccess = await hasModuleAccess(session, "/dashboard/exit");
  const companyWide = staffAccess && ["COMPANY_ADMIN", "HR"].includes(session.role);
  if (!isOwner && staffAccess) {
    const visible = await visibleEmployeeIds(session);
    const ownsAClearance = exit.clearances.length > 0; // IT/Finance reach this page only via their queue link
    if (!companyWide && !visible.includes(exit.employeeId) && !ownsAClearance) redirect("/dashboard/exit");
  } else if (!isOwner && !staffAccess) {
    redirect("/dashboard");
  }

  const isTheirManager = session.employeeId === exit.employee.managerId;
  const canReviewAsManager = staffAccess && exit.status === "PENDING" && (isTheirManager || companyWide);
  const canApproveAsHR = staffAccess && exit.status === "MANAGER_APPROVED" && companyWide;
  const allCleared = exit.clearances.length > 0 && exit.clearances.every((c) => c.status === "CLEARED");
  const canGenerateSettlement = staffAccess && exit.status === "CLEARANCE" && allCleared && (companyWide || session.role === "FINANCE");
  const canApproveSettlement = staffAccess && exit.status === "SETTLED" && exit.settlement?.status === "DRAFT" && (companyWide || session.role === "FINANCE");
  const canClearAnything = staffAccess;

  async function managerDecide(formData: FormData) {
    "use server";
    await reviewExitAsManager(id, formData.get("approve") === "1");
  }
  async function clear(formData: FormData) {
    "use server";
    await clearDepartment(formData);
  }
  async function saveInterview(formData: FormData) {
    "use server";
    await recordExitInterview(formData);
  }
  async function generate() {
    "use server";
    await generateFullFinal(id);
  }
  async function approveSettlement() {
    "use server";
    await approveFullFinal(id);
  }

  return (
    <div className="max-w-3xl">
      <BackLink fallbackHref={staffAccess ? "/dashboard/exit" : "/dashboard"}>
        {staffAccess ? "Back to exit / offboarding" : "Back to dashboard"}
      </BackLink>

      <PageHeader
        title={isOwner && !staffAccess ? "Your resignation" : `${exit.employee.firstName} ${exit.employee.lastName}`}
        subtitle={`${exit.employee.designation} — resigned ${formatDate(exit.resignationDate)}`}
        action={<StatusBadge status={exit.status} />}
      />

      {isOwner && !staffAccess && (
        <div className="card p-4 mb-5 text-sm text-ink-soft bg-[var(--color-brand-softer)] border-none">
          Your resignation was submitted on {formatDate(exit.resignationDate)} and is being processed. This page
          updates as your manager, HR, and clearance departments act on it — no action is needed from you unless
          you&apos;re contacted directly.
        </div>
      )}

      <div className="card p-5 mb-5">
        <div className="flex items-center gap-3 mb-4">
          <Avatar firstName={exit.employee.firstName} lastName={exit.employee.lastName} color={exit.employee.avatarColor} />
          <Link href={`/dashboard/employees/${exit.employeeId}`} className="text-sm text-brand hover:underline">View employee profile</Link>
        </div>
        <p className="text-sm text-ink-soft"><span className="font-medium text-ink">Reason:</span> {exit.reason}</p>
        <p className="text-sm text-ink-soft mt-1">
          <span className="font-medium text-ink">Notice period:</span> {exit.noticePeriodDays} days
          {exit.lastWorkingDate && <> · <span className="font-medium text-ink">Last working day:</span> {formatDate(exit.lastWorkingDate)}</>}
        </p>
      </div>

      {canReviewAsManager && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-3">Manager review</p>
          <div className="flex gap-2">
            <form action={managerDecide}><input type="hidden" name="approve" value="1" /><button className="btn btn-primary">Approve</button></form>
            <form action={managerDecide}><input type="hidden" name="approve" value="0" /><button className="btn btn-secondary text-danger">Reject</button></form>
          </div>
        </div>
      )}

      {canApproveAsHR && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-3">HR sign-off — set last working day &amp; start clearance</p>
          <form action={approveExitAsHR} className="flex flex-wrap items-end gap-3">
            <input type="hidden" name="exitId" value={exit.id} />
            <div>
              <label className="field-label">Last working date</label>
              <input type="date" name="lastWorkingDate" required className="input" />
            </div>
            <button type="submit" className="btn btn-primary">Approve &amp; start clearance</button>
          </form>
        </div>
      )}

      {exit.clearances.length > 0 && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-3">Department clearance</p>
          <ul className="divide-y divide-border-soft">
            {exit.clearances.map((c) => (
              <li key={c.id} className="py-2.5 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                  {c.status === "CLEARED" ? <CheckCircle2 size={16} className="text-brand shrink-0" /> : <Circle size={16} className="text-muted shrink-0" />}
                  <span className="text-sm text-ink">{DEPARTMENT_LABEL[c.department]}</span>
                  {c.remarks && <span className="text-xs text-muted">— {c.remarks}</span>}
                </div>
                {c.status !== "CLEARED" && canClearAnything && (
                  <form action={clear} className="flex items-center gap-2">
                    <input type="hidden" name="clearanceId" value={c.id} />
                    <input name="remarks" placeholder="Remarks (optional)" className="input !py-1 text-xs !w-40" />
                    <button className="btn btn-secondary !py-1 !px-2.5 text-xs">Clear</button>
                  </form>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {canGenerateSettlement && (
        <div className="card p-5 mb-5">
          <p className="text-sm text-ink-soft mb-3">All departments have cleared — generate the Full &amp; Final settlement.</p>
          <form action={generate}><button className="btn btn-primary">Generate Full &amp; Final settlement</button></form>
        </div>
      )}

      {exit.settlement && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-3">Full &amp; Final settlement</p>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm mb-4">
            <Field label="Leave encashment" value={exit.settlement.leaveEncashment} />
            <Field label="LOP deduction" value={-exit.settlement.lopDeduction} />
            <Field label="Pending expenses" value={exit.settlement.pendingExpenses} />
            <Field label="Net payable" value={exit.settlement.netPayable} bold />
          </div>
          <StatusBadge status={exit.settlement.status} />
          {canApproveSettlement && (
            <form action={approveSettlement} className="mt-3">
              <button className="btn btn-primary">Approve settlement &amp; complete exit</button>
            </form>
          )}
        </div>
      )}

      {exit.status === "COMPLETED" && (
        <div className="card p-5 mb-5">
          <p className="text-sm font-semibold text-ink mb-2">Letters</p>
          <p className="text-sm text-ink-soft">Relieving and experience letters were generated — see the employee&apos;s Documents.</p>
        </div>
      )}

      {companyWide && (
        <div className="card p-5">
          <p className="text-sm font-semibold text-ink mb-3">Exit interview notes</p>
          <form action={saveInterview} className="space-y-3">
            <input type="hidden" name="exitId" value={exit.id} />
            <textarea name="notes" rows={4} defaultValue={exit.exitInterviewNotes ?? ""} className="input" placeholder="Notes from the exit interview" />
            <button type="submit" className="btn btn-secondary">Save notes</button>
          </form>
        </div>
      )}
    </div>
  );
}

function Field({ label, value, bold }: { label: string; value: number; bold?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted">{label}</p>
      <p className={`mt-0.5 ${bold ? "font-semibold text-ink" : "text-ink-soft"}`}>{formatCurrency(value)}</p>
    </div>
  );
}
