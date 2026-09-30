import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds } from "@/lib/scope";
import { StatusBadge, Avatar } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { formatDate, formatDateTime } from "@/lib/format";
import { updateEmployeeStatus, updateOwnPhone } from "@/lib/actions/employees";
import { setSalaryHold } from "@/lib/actions/payroll";
import { startOnboarding } from "@/lib/actions/onboarding";
import { Mail, Phone, Building2, MapPin, UserCog, Pencil, History, TrendingUp, LogOut, PauseCircle, PlayCircle } from "lucide-react";

export const dynamic = "force-dynamic";

const FIELD_LABELS: Record<string, string> = {
  firstName: "First name",
  lastName: "Last name",
  email: "Email",
  phone: "Phone",
  designation: "Designation",
  departmentId: "Department",
  branchId: "Branch",
  managerId: "Manager",
  employmentType: "Employment type",
};

function describeAuditEntry(action: string, meta: unknown): string[] {
  const m = (meta && typeof meta === "object" ? meta : {}) as Record<string, unknown>;

  if (action === "CREATE") return ["Employee profile created"];

  if (action === "STATUS_CHANGE") {
    return [`Status changed to ${String(m.status ?? "—").replace(/_/g, " ").toLowerCase()}`];
  }

  // Any action that carries a field-level diff (plain edits, or an approved
  // promotion/transfer) renders the same way — the action name just labels
  // *why* it changed, not *how* to read the diff.
  if (m.changes && typeof m.changes === "object") {
    return Object.entries(m.changes as Record<string, { from: unknown; to: unknown }>).map(
      ([field, { from, to }]) =>
        `${FIELD_LABELS[field] ?? field} changed from "${from ?? "—"}" to "${to ?? "—"}"`
    );
  }

  if (action === "SALARY_REVISION") {
    const from = m.from != null ? `₹${Number(m.from).toLocaleString("en-IN")}` : "—";
    const to = m.to != null ? `₹${Number(m.to).toLocaleString("en-IN")}` : "—";
    return [`Basic salary revised from ${from} to ${to}`];
  }

  return [action.replace(/_/g, " ").toLowerCase()];
}

export default async function EmployeeProfilePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const session = await requireSession();
  const empIds = await visibleEmployeeIds(session);
  if (!empIds.includes(id)) redirect("/dashboard/employees");

  const employee = await prisma.employee.findUnique({
    where: { id },
    include: {
      department: true,
      branch: true,
      manager: true,
      reports: true,
      salaryStructure: true,
      documents: true,
      assetAssignments: { include: { asset: true }, where: { returnedDate: null } },
      leaveBalances: { include: { leaveType: true }, where: { year: new Date().getFullYear() } },
      attendance: { orderBy: { date: "desc" }, take: 8 },
      onboarding: { include: { tasks: true } },
      confirmations: { orderBy: { createdAt: "desc" }, take: 1 },
    },
  });
  if (!employee) notFound();
  const latestConfirmation = employee.confirmations[0];

  const canManage = session.role === "COMPANY_ADMIN" || session.role === "HR";
  const canHoldSalary = ["COMPANY_ADMIN", "HR", "PAYROLL_ADMIN"].includes(session.role);
  const isOwnProfile = session.employeeId === id;

  const history = canManage
    ? await prisma.auditLog.findMany({
        where: {
          OR: [
            { entityType: "Employee", entityId: id },
            { entityType: "SalaryStructure", entityId: id },
          ],
        },
        include: { actor: true },
        orderBy: { createdAt: "desc" },
        take: 30,
      })
    : [];

  async function setStatus(formData: FormData) {
    "use server";
    const status = String(formData.get("status")) as "ACTIVE" | "ON_LEAVE" | "NOTICE_PERIOD";
    await updateEmployeeStatus(id, status);
  }

  async function savePhone(formData: FormData) {
    "use server";
    await updateOwnPhone(formData);
  }

  async function startOnboardingAction() {
    "use server";
    await startOnboarding(id);
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/employees">Back to employees</BackLink>

      <div className="card p-6 mb-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-center gap-4">
            <span
              className="grid place-items-center w-14 h-14 rounded-full text-white text-lg font-semibold"
              style={{ background: employee.avatarColor }}
            >
              {employee.firstName[0]}{employee.lastName[0]}
            </span>
            <div>
              <h1 className="font-display text-xl font-semibold text-ink">{employee.firstName} {employee.lastName}</h1>
              <p className="text-sm text-muted">{employee.designation} · {employee.employeeCode}</p>
              <div className="mt-1.5"><StatusBadge status={employee.status} /></div>
            </div>
          </div>

          {canManage && (
            <div className="flex items-center gap-2">
              <Link href={`/dashboard/employees/${id}/edit`} className="btn btn-secondary text-sm">
                <Pencil size={14} /> Edit
              </Link>
              <Link href={`/dashboard/employees/${id}/promote`} className="btn btn-secondary text-sm">
                <TrendingUp size={14} /> Promote / Transfer
              </Link>
              {employee.status !== "EXITED" && (
                <Link href={`/dashboard/exit/new?employeeId=${id}`} className="btn btn-secondary text-sm">
                  <LogOut size={14} /> Initiate exit
                </Link>
              )}
              {employee.status !== "EXITED" && (
                <form action={setStatus} className="flex items-center gap-2">
                  <select name="status" defaultValue={employee.status} className="input !w-auto text-sm">
                    <option value="ACTIVE">Active</option>
                    <option value="ON_LEAVE">On leave</option>
                    <option value="NOTICE_PERIOD">Notice period</option>
                  </select>
                  <button type="submit" className="btn btn-secondary text-sm">
                    <UserCog size={14} /> Update status
                  </button>
                </form>
              )}
            </div>
          )}
        </div>

        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6 pt-6 border-t border-border-soft text-sm">
          <div className="flex items-center gap-2 text-ink-soft"><Mail size={15} /> {employee.email}</div>
          <div className="flex items-center gap-2 text-ink-soft">
            <Phone size={15} className="shrink-0" />
            {isOwnProfile ? (
              <details className="group">
                <summary className="cursor-pointer list-none flex items-center gap-1.5 hover:text-ink">
                  {employee.phone ?? "Add phone number"} <Pencil size={11} className="text-muted" />
                </summary>
                <form action={savePhone} className="mt-2 flex items-center gap-1.5">
                  <input name="phone" defaultValue={employee.phone ?? ""} placeholder="Phone number" className="input !w-36 !py-1 text-xs" />
                  <button type="submit" className="btn btn-secondary !py-1 !px-2 text-xs">Save</button>
                </form>
              </details>
            ) : (
              employee.phone ?? "—"
            )}
          </div>
          <div className="flex items-center gap-2 text-ink-soft"><Building2 size={15} /> {employee.department?.name ?? "—"}</div>
          <div className="flex items-center gap-2 text-ink-soft"><MapPin size={15} /> {employee.branch?.name ?? "—"}</div>
        </div>
      </div>

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 space-y-5">
          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3">Recent attendance</p>
            <table className="table-clean">
              <thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
              <tbody>
                {employee.attendance.map((a) => (
                  <tr key={a.id}>
                    <td>{formatDate(a.date)}</td>
                    <td>{a.checkIn ? formatDateTime(a.checkIn).split(", ")[1] : "—"}</td>
                    <td>{a.checkOut ? formatDateTime(a.checkOut).split(", ")[1] : "—"}</td>
                    <td><StatusBadge status={a.status} /></td>
                  </tr>
                ))}
                {employee.attendance.length === 0 && (
                  <tr><td colSpan={4} className="text-center text-muted py-6">No attendance recorded yet.</td></tr>
                )}
              </tbody>
            </table>
          </div>

          {canManage && employee.salaryStructure && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Salary structure</p>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                <Field label="Basic" value={employee.salaryStructure.basic} />
                <Field label="HRA" value={employee.salaryStructure.hra} />
                <Field label="Conveyance" value={employee.salaryStructure.conveyance} />
                <Field label="Medical" value={employee.salaryStructure.medicalAllowance} />
                <Field label="Special allowance" value={employee.salaryStructure.specialAllowance} />
                <Field label="PF (employee)" value={employee.salaryStructure.pfEmployee} />
              </div>
            </div>
          )}

          {canHoldSalary && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Salary hold</p>
              {employee.salaryOnHold ? (
                <div>
                  <p className="text-sm text-danger flex items-center gap-1.5 mb-1"><PauseCircle size={15} /> On hold</p>
                  {employee.salaryHoldReason && <p className="text-sm text-muted mb-3">Reason: {employee.salaryHoldReason}</p>}
                  <form action={setSalaryHold}>
                    <input type="hidden" name="employeeId" value={employee.id} />
                    <input type="hidden" name="hold" value="0" />
                    <button className="btn btn-secondary text-sm"><PlayCircle size={14} /> Release hold</button>
                  </form>
                </div>
              ) : (
                <div>
                  <p className="text-sm text-muted mb-3">Future payroll runs will pay this employee normally.</p>
                  <form action={setSalaryHold} className="space-y-2">
                    <input type="hidden" name="employeeId" value={employee.id} />
                    <input type="hidden" name="hold" value="1" />
                    <input name="reason" required placeholder="Reason for hold" className="input text-sm" />
                    <button className="btn btn-secondary text-sm w-full justify-center"><PauseCircle size={14} /> Hold salary</button>
                  </form>
                </div>
              )}
              <p className="text-xs text-muted mt-3">Applies from the next payroll run onward — it doesn&rsquo;t change any payslip already generated.</p>
            </div>
          )}

          {employee.reports.length > 0 && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Direct reports</p>
              <ul className="space-y-2.5">
                {employee.reports.map((r) => (
                  <li key={r.id}>
                    <Link href={`/dashboard/employees/${r.id}`} className="flex items-center gap-2.5 text-sm hover:underline">
                      <Avatar firstName={r.firstName} lastName={r.lastName} color={r.avatarColor} />
                      {r.firstName} {r.lastName}
                      <span className="text-muted">— {r.designation}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {canManage && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
                <History size={15} /> Change history
              </p>
              {history.length === 0 ? (
                <p className="text-sm text-muted py-2">No recorded changes yet.</p>
              ) : (
                <ul className="divide-y divide-border-soft">
                  {history.map((log) => (
                    <li key={log.id} className="py-2.5">
                      {describeAuditEntry(log.action, log.meta).map((line, i) => (
                        <p key={i} className="text-sm text-ink-soft">{line}</p>
                      ))}
                      <p className="text-xs text-muted mt-1">
                        {log.actor?.email ?? "system"} · {formatDateTime(log.createdAt)}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>

        <div className="space-y-5">
          {latestConfirmation && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-2">Confirmation</p>
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted">Due {formatDate(latestConfirmation.dueDate)}</span>
                <StatusBadge status={latestConfirmation.status} />
              </div>
              {canManage && (
                <Link href="/dashboard/employees/confirmations" className="text-sm text-brand hover:underline mt-2 inline-block">
                  Manage confirmations
                </Link>
              )}
            </div>
          )}

          {employee.onboarding && employee.onboarding.status === "IN_PROGRESS" && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-1">Onboarding checklist</p>
              <p className="text-xs text-muted mb-3">
                {employee.onboarding.tasks.filter((t) => t.status === "DONE").length}/{employee.onboarding.tasks.length} tasks done
              </p>
              <Link href={`/dashboard/onboarding/${employee.onboarding.id}`} className="text-sm text-brand hover:underline">
                View checklist
              </Link>
            </div>
          )}

          {canManage && !employee.onboarding && employee.status !== "EXITED" && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-1">Onboarding checklist</p>
              <p className="text-xs text-muted mb-3">
                No checklist yet — this can happen if they were hired before any task templates existed.
              </p>
              <form action={startOnboardingAction}>
                <button type="submit" className="btn btn-secondary text-sm">Start onboarding checklist</button>
              </form>
            </div>
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3">Leave balance — {new Date().getFullYear()}</p>
            <ul className="space-y-2.5">
              {employee.leaveBalances.map((b) => (
                <li key={b.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-soft">{b.leaveType.name}</span>
                  <span className="font-medium text-ink">{b.allocated - b.used} / {b.allocated}</span>
                </li>
              ))}
            </ul>
          </div>

          {employee.manager && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Reports to</p>
              <Link href={`/dashboard/employees/${employee.manager.id}`} className="flex items-center gap-2.5 text-sm hover:underline">
                <Avatar firstName={employee.manager.firstName} lastName={employee.manager.lastName} />
                {employee.manager.firstName} {employee.manager.lastName}
              </Link>
            </div>
          )}

          {employee.assetAssignments.length > 0 && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Assigned assets</p>
              <ul className="space-y-2 text-sm">
                {employee.assetAssignments.map((a) => (
                  <li key={a.id} className="flex items-center justify-between">
                    <span className="text-ink-soft">{a.asset.name}</span>
                    <span className="text-muted text-xs">{a.asset.serialNumber}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {employee.documents.length > 0 && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Documents</p>
              <ul className="space-y-2 text-sm">
                {employee.documents.map((d) => (
                  <li key={d.id} className="text-ink-soft">{d.name}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function Field({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <p className="text-xs text-muted">{label}</p>
      <p className="font-medium text-ink mt-0.5">₹{value.toLocaleString("en-IN")}</p>
    </div>
  );
}
