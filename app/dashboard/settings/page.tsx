import Link from "next/link";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader } from "@/components/ui";
import { updatePolicy, addBranch, addDepartment, addLeaveType, addHoliday, addOnboardingTaskTemplate, deleteOnboardingTaskTemplate } from "@/lib/actions/settings";
import { CompanyLetterheadForm } from "@/components/CompanyLetterheadForm";
import { BranchLocationForm } from "@/components/BranchLocationForm";
import { TabbedPanels } from "@/components/TabbedPanels";
import { ConfirmSubmitButton } from "@/components/ConfirmSubmitButton";
import { formatDate } from "@/lib/format";
import { Users2, Landmark, ShieldCheck, MapPin } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function SettingsPage() {
  const session = await requireRole(["COMPANY_ADMIN"]);
  await requireModuleAccess("/dashboard/settings");

  const [company, policy, branches, departments, leaveTypes, holidays, onboardingTemplates] = await Promise.all([
    prisma.company.findUnique({ where: { id: session.companyId ?? "" } }),
    prisma.companyPolicy.findUnique({ where: { companyId: session.companyId ?? "" } }),
    prisma.branch.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.department.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.leaveType.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { name: "asc" } }),
    prisma.holiday.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: { date: "asc" } }),
    prisma.onboardingTaskTemplate.findMany({ where: { companyId: session.companyId ?? undefined }, orderBy: [{ category: "asc" }, { sortOrder: "asc" }] }),
  ]);

  async function removeTemplate(formData: FormData) {
    "use server";
    await deleteOnboardingTaskTemplate(String(formData.get("id")));
  }

  const tabs = [
    {
      key: "letterhead",
      label: "Letterhead",
      content: (
        <div className="card p-6 max-w-2xl">
          <p className="text-sm font-semibold text-ink mb-1">Company letterhead</p>
          <p className="text-xs text-muted mb-4">Shown on every generated offer/appointment/relieving letter.</p>
          <CompanyLetterheadForm company={company ? { logoUrl: company.logoUrl, address: company.address, phone: company.phone, panNumber: company.panNumber } : null} />
        </div>
      ),
    },
    {
      key: "policy",
      label: "Policy",
      content: (
        <div className="card p-6 max-w-2xl">
          <p className="text-sm font-semibold text-ink mb-4">Policy</p>
          <form action={updatePolicy} className="space-y-4">
            <div>
              <label className="field-label">Work week</label>
              <input name="workWeek" defaultValue={policy?.workWeek ?? "MON-FRI"} className="input" />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <NumField name="checkInGraceMins" label="Check-in grace (mins)" defaultValue={policy?.checkInGraceMins ?? 15} />
              <NumField name="probationMonths" label="Probation (months)" defaultValue={policy?.probationMonths ?? 3} />
              <NumField name="casualLeaveDays" label="Casual leave/yr" defaultValue={policy?.casualLeaveDays ?? 12} />
              <NumField name="sickLeaveDays" label="Sick leave/yr" defaultValue={policy?.sickLeaveDays ?? 8} />
              <NumField name="earnedLeaveDays" label="Earned leave/yr" defaultValue={policy?.earnedLeaveDays ?? 15} />
              <NumField name="noticePeriodDays" label="Notice period (days)" defaultValue={policy?.noticePeriodDays ?? 30} />
            </div>

            <div className="rounded-lg border border-border-soft bg-brand-softer p-4">
              <label className="flex items-center gap-2.5 text-sm font-medium text-ink">
                <input type="checkbox" name="geofenceAttendanceEnabled" defaultChecked={policy?.geofenceAttendanceEnabled ?? false} className="w-4 h-4 accent-brand" />
                Require employees to be at their branch to check in/out
              </label>
              <p className="text-xs text-muted mt-2">
                When on, employees are asked for their location on check-in/out, and it's checked against the radius
                set for their branch under the Branches tab. Off by default — turning it on doesn&rsquo;t restrict
                anyone until a branch also has its location set.
              </p>
            </div>

            <button type="submit" className="btn btn-primary w-full justify-center">Save policy</button>
          </form>
        </div>
      ),
    },
    {
      key: "branches",
      label: "Branches",
      content: (
        <div className="card p-5 max-w-2xl">
          <p className="text-sm font-semibold text-ink mb-3">Branches</p>
          <ul className="divide-y divide-border-soft mb-3">
            {branches.map((b) => (
              <li key={b.id} className="py-3">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-sm text-ink">{b.name} — {b.city}</p>
                    <p className="text-xs text-muted flex items-center gap-1 mt-0.5">
                      <MapPin size={11} />
                      {b.latitude != null
                        ? `Geofence active — ${b.geofenceRadiusMeters}m radius`
                        : "No geofence — check-in isn't location-restricted"}
                    </p>
                  </div>
                  <details>
                    <summary className="text-xs text-brand font-medium cursor-pointer list-none whitespace-nowrap">
                      {b.latitude != null ? "Edit location" : "Set location"}
                    </summary>
                    <BranchLocationForm branch={b} />
                  </details>
                </div>
              </li>
            ))}
            {branches.length === 0 && <li className="text-sm text-muted py-2">None yet.</li>}
          </ul>
          <details>
            <summary className="cursor-pointer text-sm text-brand font-medium">+ Add</summary>
            <form action={addBranch} className="space-y-2 mt-3">
              <input name="name" required placeholder="Branch name" className="input" />
              <input name="city" required placeholder="City" className="input" />
              <button type="submit" className="btn btn-secondary w-full justify-center text-sm">Add</button>
            </form>
          </details>
        </div>
      ),
    },
    {
      key: "departments",
      label: "Departments",
      content: (
        <SettingsCard title="Departments" items={departments.map((d) => d.name)} action={addDepartment}>
          <input name="name" required placeholder="Department name" className="input" />
        </SettingsCard>
      ),
    },
    {
      key: "leave-types",
      label: "Leave types",
      content: (
        <SettingsCard title="Leave types" items={leaveTypes.map((l) => `${l.name} — ${l.daysPerYear} days/yr`)} action={addLeaveType}>
          <input name="name" required placeholder="Leave type name" className="input" />
          <input name="daysPerYear" type="number" required placeholder="Days per year" className="input" />
          <label className="flex items-center gap-2 text-sm text-ink-soft">
            <input type="checkbox" name="carryForward" className="w-4 h-4" /> Allow carry-forward
          </label>
        </SettingsCard>
      ),
    },
    {
      key: "holidays",
      label: "Holidays",
      content: (
        <SettingsCard title="Holidays" items={holidays.map((h) => `${h.name} — ${formatDate(h.date)}`)} action={addHoliday}>
          <input name="name" required placeholder="Holiday name" className="input" />
          <input name="date" type="date" required className="input" />
        </SettingsCard>
      ),
    },
    {
      key: "onboarding",
      label: "Onboarding templates",
      content: (
        <div className="card p-5 max-w-2xl">
          <p className="text-sm font-semibold text-ink mb-3">Onboarding task templates</p>
          <ul className="space-y-1.5 mb-3 text-sm text-ink-soft max-h-40 overflow-y-auto">
            {onboardingTemplates.map((t) => (
              <li key={t.id} className="flex items-center justify-between gap-2">
                <span><span className="text-xs text-muted uppercase">{t.category}</span> — {t.title}</span>
                <form action={removeTemplate}>
                  <input type="hidden" name="id" value={t.id} />
                  <ConfirmSubmitButton confirmMessage={`Remove the "${t.title}" onboarding task template?`} className="text-xs text-danger hover:underline">
                    Remove
                  </ConfirmSubmitButton>
                </form>
              </li>
            ))}
            {onboardingTemplates.length === 0 && <li className="text-muted">None yet.</li>}
          </ul>
          <details>
            <summary className="cursor-pointer text-sm text-brand font-medium">+ Add</summary>
            <form action={addOnboardingTaskTemplate} className="space-y-2 mt-3">
              <select name="category" className="input">
                <option value="HR">HR</option>
                <option value="IT">IT</option>
                <option value="MANAGER">Manager</option>
              </select>
              <input name="title" required placeholder="Task title" className="input" />
              <button type="submit" className="btn btn-secondary w-full justify-center text-sm">Add</button>
            </form>
          </details>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Company settings"
        subtitle="Org structure and policy configuration."
        action={
          <div className="flex gap-2">
            <Link href="/dashboard/settings/statutory" className="btn btn-secondary"><Landmark size={15} /> Statutory config</Link>
            <Link href="/dashboard/settings/users" className="btn btn-secondary"><Users2 size={15} /> Manage users</Link>
            <Link href="/dashboard/settings/roles" className="btn btn-secondary"><ShieldCheck size={15} /> Custom roles</Link>
          </div>
        }
      />

      <TabbedPanels tabs={tabs} />
    </div>
  );
}

function NumField({ name, label, defaultValue }: { name: string; label: string; defaultValue: number }) {
  return (
    <div>
      <label className="field-label">{label}</label>
      <input type="number" name={name} defaultValue={defaultValue} className="input" />
    </div>
  );
}

function SettingsCard({
  title, items, action, children,
}: { title: string; items: string[]; action: (formData: FormData) => void; children: React.ReactNode }) {
  return (
    <div className="card p-5 max-w-2xl">
      <p className="text-sm font-semibold text-ink mb-3">{title}</p>
      <ul className="space-y-1.5 mb-3 text-sm text-ink-soft max-h-32 overflow-y-auto">
        {items.map((it, i) => <li key={i}>{it}</li>)}
        {items.length === 0 && <li className="text-muted">None yet.</li>}
      </ul>
      <details>
        <summary className="cursor-pointer text-sm text-brand font-medium">+ Add</summary>
        <form action={action} className="space-y-2 mt-3">
          {children}
          <button type="submit" className="btn btn-secondary w-full justify-center text-sm">Add</button>
        </form>
      </details>
    </div>
  );
}
