"use client";

import { useActionState } from "react";
import Link from "next/link";
import { requestPasswordReset, type ForgotPasswordState } from "@/lib/actions/auth";
import { ArrowLeft, ArrowRight, CheckCircle2, Loader2 } from "lucide-react";

const initialState: ForgotPasswordState = {};

export function ForgotPasswordForm() {
  const [state, formAction, pending] = useActionState(requestPasswordReset, initialState);

  if (state.sent) {
    return (
      <div className="card p-6 text-center">
        <CheckCircle2 size={28} className="mx-auto text-[var(--color-brand)]" />
        <p className="mt-3 text-sm text-[var(--color-ink)] font-medium">Check your email</p>
        <p className="mt-1.5 text-sm text-[var(--color-muted)]">
          If an account exists for that email, we&rsquo;ve sent a link to reset your password. It expires in 30 minutes.
        </p>
        <Link href="/login" className="inline-flex items-center gap-1.5 text-sm text-[var(--color-brand)] font-medium mt-5 hover:underline">
          <ArrowLeft size={14} /> Back to sign in
        </Link>
      </div>
    );
  }

  return (
    <form action={formAction} className="card p-6 space-y-4">
      <div>
        <label className="field-label" htmlFor="email">Work email</label>
        <input id="email" name="email" type="email" required autoFocus placeholder="you@company.com" className="input" />
      </div>

      {state.error && (
        <div className="text-sm text-[var(--color-danger)] bg-[var(--color-danger-soft)] rounded-lg px-3 py-2.5">{state.error}</div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70">
        {pending ? <><Loader2 size={16} className="animate-spin" /> Sending…</> : <>Send reset link <ArrowRight size={16} /></>}
      </button>

      <Link href="/login" className="flex items-center justify-center gap-1.5 text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)]">
        <ArrowLeft size={14} /> Back to sign in
      </Link>
    </form>
  );
}
