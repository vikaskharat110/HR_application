import Link from "next/link";
import { ArrowLeft, Copy } from "lucide-react";

export const dynamic = "force-static";

const ACCOUNTS = [
  { role: "Super Admin", email: "superadmin@verdanthr.io", note: "Sees every tenant on the platform." },
  { role: "Company Admin", email: "admin@meridianworks.com", note: "Full control of Meridian Works." },
  { role: "HR / Recruiter", email: "hr@meridianworks.com", note: "People ops, hiring, payroll runs." },
  { role: "Manager", email: "manager@meridianworks.com", note: "Approves leave & expenses for their team." },
  { role: "Employee", email: "employee@meridianworks.com", note: "Self-service: attendance, leave, payslips." },
];

export default function DemoCredentialsPage() {
  return (
    <div className="min-h-screen bg-[var(--color-surface)] flex items-center justify-center px-6 py-16">
      <div className="w-full max-w-lg">
        <Link href="/login" className="inline-flex items-center gap-1.5 text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] mb-6">
          <ArrowLeft size={15} /> Back to sign in
        </Link>

        <h1 className="font-display text-2xl font-semibold text-[var(--color-ink)]">Demo credentials</h1>
        <p className="mt-1.5 text-sm text-[var(--color-muted)]">
          Once the database is seeded (<code className="px-1 py-0.5 rounded bg-[var(--color-border-soft)] text-[13px]">npm run db:seed</code>),
          every account below shares the same password.
        </p>

        <div className="mt-6 card divide-y divide-[var(--color-border-soft)]">
          {ACCOUNTS.map((a) => (
            <div key={a.email} className="flex items-center justify-between gap-4 px-5 py-4">
              <div>
                <p className="text-sm font-semibold text-[var(--color-ink)]">{a.role}</p>
                <p className="text-sm text-[var(--color-brand)] font-mono">{a.email}</p>
                <p className="text-xs text-[var(--color-muted)] mt-0.5">{a.note}</p>
              </div>
            </div>
          ))}
        </div>

        <div className="mt-4 flex items-center gap-2 rounded-lg bg-[var(--color-brand-softer)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
          <Copy size={15} className="shrink-0" />
          Password for every demo account: <span className="font-mono font-semibold text-[var(--color-ink)]">Passw0rd!</span>
        </div>
      </div>
    </div>
  );
}

