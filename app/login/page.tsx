import Link from "next/link";
import { Leaf, ShieldCheck, Users2, CalendarClock } from "lucide-react";
import { LoginForm } from "@/components/LoginForm";

export const dynamic = "force-dynamic";

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string }>;
}) {
  const { next } = await searchParams;

  return (
    <div className="min-h-screen flex bg-[var(--color-surface)]">
      <div className="hidden lg:flex lg:w-[46%] relative flex-col justify-between bg-[var(--color-brand-dark)] text-white px-12 py-12 overflow-hidden">
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.08]"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 20%, white 1px, transparent 1px), radial-gradient(circle at 60% 70%, white 1px, transparent 1px)",
            backgroundSize: "48px 48px",
          }}
        />
        <div className="relative flex items-center gap-2.5">
          <div className="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-accent)]">
            <Leaf size={19} />
          </div>
          <span className="font-display text-lg font-semibold">Verdant HR</span>
        </div>

        <div className="relative max-w-md">
          <p className="font-display text-3xl leading-snug">
            One place for every person process — from offer letter to final settlement.
          </p>
          <p className="mt-4 text-white/60 text-sm leading-relaxed">
            Attendance, leave, payroll, hiring, performance, and approvals — all in a
            single tenant-isolated workspace built for how HR teams actually work.
          </p>

          <div className="mt-10 space-y-4 text-sm">
            <div className="flex items-center gap-3 text-white/80">
              <ShieldCheck size={17} className="text-[var(--color-accent)]" /> Role-based access with a full audit trail
            </div>
            <div className="flex items-center gap-3 text-white/80">
              <Users2 size={17} className="text-[var(--color-accent)]" /> Multi-company, multi-branch from day one
            </div>
            <div className="flex items-center gap-3 text-white/80">
              <CalendarClock size={17} className="text-[var(--color-accent)]" /> Approvals that route themselves
            </div>
          </div>
        </div>

        <p className="relative text-xs text-white/35">© {new Date().getFullYear()} Verdant HR. Internal HR platform.</p>
      </div>

      <div className="flex-1 flex items-center justify-center px-6 py-16">
        <div className="w-full max-w-sm">
          <div className="lg:hidden flex items-center gap-2.5 mb-8 justify-center">
            <div className="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-brand)] text-white">
              <Leaf size={18} />
            </div>
            <span className="font-display text-lg font-semibold text-[var(--color-ink)]">Verdant HR</span>
          </div>

          <h1 className="font-display text-2xl font-semibold text-[var(--color-ink)]">Welcome back</h1>
          <p className="mt-1.5 text-sm text-[var(--color-muted)]">
            Sign in with the account your admin set up for you.
          </p>

          <div className="mt-7 card p-6">
            <LoginForm next={next ?? "/dashboard"} />
          </div>

          <p className="mt-5 text-center text-xs text-[var(--color-muted)]">
            Trouble signing in? Contact your HR admin. See{" "}
            <Link href="/login/demo" className="text-[var(--color-brand)] font-medium underline underline-offset-2">
              demo credentials
            </Link>
            .
          </p>
        </div>
      </div>
    </div>
  );
}

