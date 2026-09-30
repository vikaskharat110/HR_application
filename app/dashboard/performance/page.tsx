import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds, isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar, EmptyState } from "@/components/ui";
import { createReviewCycle, addGoal, submitReview, submitSelfAppraisal } from "@/lib/actions/performance";
import { Target, Plus, Star } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function PerformancePage() {
  const session = await requireModuleAccess("/dashboard/performance");
  const canManageCycles = session.role === "COMPANY_ADMIN" || session.role === "HR";
  const managerial = isManagerial(session);
  const empIds = await visibleEmployeeIds(session);

  const [cycles, activeCycle, myGoals, myReviews, teamReviewRows] = await Promise.all([
    prisma.reviewCycle.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { startDate: "desc" } }),
    prisma.reviewCycle.findFirst({ where: { companyId: session.companyId ?? undefined, status: "ACTIVE" } }),
    session.employeeId
      ? prisma.goal.findMany({ where: { employeeId: session.employeeId }, include: { cycle: true }, orderBy: { id: "desc" } })
      : Promise.resolve([]),
    session.employeeId
      ? prisma.performanceReview.findMany({ where: { employeeId: session.employeeId }, include: { cycle: true, reviewer: true } })
      : Promise.resolve([]),
    managerial
      ? prisma.employee.findMany({
          // A Manager reviews only their direct reports (the assigned-reviewer
          // rule matches assertCanActOn); HR/Admin keep the company-wide view.
          where:
            session.role === "MANAGER"
              ? { managerId: session.employeeId }
              : { id: { in: empIds.filter((id) => id !== session.employeeId) } },
          include: { reviewsReceived: { include: { cycle: true } } },
          orderBy: { firstName: "asc" },
        })
      : Promise.resolve([]),
  ]);

  return (
    <div>
      <PageHeader title="Performance" subtitle="Goals, review cycles, and ratings." />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="space-y-5">
          {canManageCycles && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Review cycles</p>
              <ul className="space-y-2 mb-4">
                {cycles.map((c) => (
                  <li key={c.id} className="flex items-center justify-between text-sm">
                    <span className="text-ink-soft">{c.name}</span>
                    <StatusBadge status={c.status} />
                  </li>
                ))}
                {cycles.length === 0 && <p className="text-sm text-muted">No cycles yet.</p>}
              </ul>
              <details className="text-sm">
                <summary className="cursor-pointer text-brand font-medium">+ New cycle</summary>
                <form action={createReviewCycle} className="space-y-3 mt-3">
                  <input name="name" required placeholder="e.g. H1 2027 Review" className="input" />
                  <div className="grid grid-cols-2 gap-2">
                    <input type="date" name="startDate" required className="input" />
                    <input type="date" name="endDate" required className="input" />
                  </div>
                  <button type="submit" className="btn btn-primary w-full justify-center">Create cycle</button>
                </form>
              </details>
            </div>
          )}

          {activeCycle && session.employeeId && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Add a goal — {activeCycle.name}</p>
              <form action={addGoal} className="space-y-3">
                <input type="hidden" name="cycleId" value={activeCycle.id} />
                <input name="title" required placeholder="Goal title" className="input" />
                <textarea name="description" rows={2} placeholder="Description (optional)" className="input" />
                <div>
                  <label className="field-label">Weight (%)</label>
                  <input type="number" name="weight" min={0} max={100} defaultValue={20} className="input" />
                </div>
                <button type="submit" className="btn btn-primary w-full justify-center"><Plus size={14} /> Add goal</button>
              </form>
            </div>
          )}
        </div>

        <div className="lg:col-span-2 space-y-5">
          {session.employeeId && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">My goals</p>
              {myGoals.length === 0 ? (
                <EmptyState icon={Target} title="No goals set" message="Add a goal for the active review cycle." />
              ) : (
                <ul className="divide-y divide-border-soft">
                  {myGoals.map((g) => (
                    <li key={g.id} className="py-3">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-medium text-ink">{g.title}</p>
                        <span className="text-xs text-muted">{g.weight}% · {g.cycle.name}</span>
                      </div>
                      {g.description && <p className="text-sm text-ink-soft mt-1">{g.description}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {session.employeeId && activeCycle && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-1">Self-appraisal — {activeCycle.name}</p>
              <p className="text-xs text-muted mb-3">Rate your own performance this cycle — your manager sees this alongside their own review.</p>
              <form action={submitSelfAppraisal} className="flex flex-wrap items-start gap-2">
                <input type="hidden" name="cycleId" value={activeCycle.id} />
                <select name="selfRating" defaultValue={myReviews.find((r) => r.cycle.id === activeCycle.id)?.selfRating ?? 3} className="input !w-auto text-sm">
                  {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} / 5</option>)}
                </select>
                <input
                  name="selfComments"
                  defaultValue={myReviews.find((r) => r.cycle.id === activeCycle.id)?.selfComments ?? ""}
                  placeholder="How would you describe your own performance?"
                  className="input flex-1 min-w-[200px] text-sm"
                />
                <button className="btn btn-secondary text-sm shrink-0">Submit</button>
              </form>
            </div>
          )}

          {session.employeeId && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">My reviews</p>
              {myReviews.length === 0 ? (
                <p className="text-sm text-muted py-4">No reviews yet.</p>
              ) : (
                <ul className="divide-y divide-border-soft">
                  {myReviews.map((r) => (
                    <li key={r.id} className="py-3">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-medium text-ink">{r.cycle.name}</p>
                        {r.rating && (
                          <span className="flex items-center gap-1 text-sm text-accent-dark font-semibold">
                            <Star size={14} className="fill-current" /> {r.rating}/5
                          </span>
                        )}
                      </div>
                      {r.comments && <p className="text-sm text-ink-soft mt-1">Manager: {r.comments}</p>}
                      {r.rating && <p className="text-xs text-muted mt-1">by {r.reviewer.firstName} {r.reviewer.lastName}</p>}
                      {r.selfRating && (
                        <p className="text-sm text-ink-soft mt-2 pt-2 border-t border-border-soft">
                          <span className="font-medium text-ink">Your self-rating: {r.selfRating}/5</span>
                          {r.selfComments && ` — ${r.selfComments}`}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          {managerial && activeCycle && (
            <div className="card p-5">
              <p className="text-sm font-semibold text-ink mb-3">Rate your team — {activeCycle.name}</p>
              <ul className="divide-y divide-border-soft">
                {teamReviewRows.map((emp) => {
                  const existing = emp.reviewsReceived.find((r) => r.cycle.id === activeCycle.id);
                  return (
                    <li key={emp.id} className="py-4 flex flex-wrap items-center gap-4">
                      <Avatar firstName={emp.firstName} lastName={emp.lastName} color={emp.avatarColor} />
                      <span className="text-sm font-medium text-ink w-40 shrink-0">{emp.firstName} {emp.lastName}</span>
                      <form action={submitReview} className="flex items-center gap-2 flex-1">
                        <input type="hidden" name="employeeId" value={emp.id} />
                        <input type="hidden" name="cycleId" value={activeCycle.id} />
                        <select name="rating" defaultValue={existing?.rating ?? 3} className="input !w-auto text-sm">
                          {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} / 5</option>)}
                        </select>
                        <input name="comments" defaultValue={existing?.comments ?? ""} placeholder="Comments" className="input text-sm" />
                        <button className="btn btn-secondary text-sm shrink-0">Save</button>
                      </form>
                    </li>
                  );
                })}
                {teamReviewRows.length === 0 && <p className="text-sm text-muted py-4">No direct reports to review.</p>}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
