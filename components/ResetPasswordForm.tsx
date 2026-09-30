"use client";

import { useActionState } from "react";
import Link from "next/link";
import { resetPassword, type ResetPasswordState } from "@/lib/actions/auth";
import { ArrowRight, CheckCircle2, Loader2 } from "lucide-react";

const initialState: ResetPasswordState = {};

export function ResetPasswordForm({ token }: { token: string }) {
  const [state, formAction, pending] = useActionState(resetPassword, initialState);

  if (state.success) {
    return (
      <div className="card p-6 text-center">
        <CheckCircle2 size={28} className="mx-auto text-[var(--color-brand)]" />
        <p className="mt-3 text-sm text-[var(--color-ink)] font-medium">Password updated</p>
        <p className="mt-1.5 text-sm text-[var(--color-muted)]">You can now sign in with your new password.</p>
        <Link href="/login" className="btn btn-primary w-full justify-center mt-5">Go to sign in</Link>
      </div>
    );
  }

  return (
    <form action={formAction} className="card p-6 space-y-4">
      <input type="hidden" name="token" value={token} />

      <div>
        <label className="field-label" htmlFor="password">New password</label>
        <input id="password" name="password" type="password" required autoFocus placeholder="At least 8 characters" className="input" />
      </div>
      <div>
        <label className="field-label" htmlFor="confirmPassword">Confirm new password</label>
        <input id="confirmPassword" name="confirmPassword" type="password" required className="input" />
      </div>

      {state.error && (
        <div className="text-sm text-[var(--color-danger)] bg-[var(--color-danger-soft)] rounded-lg px-3 py-2.5">{state.error}</div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70">
        {pending ? <><Loader2 size={16} className="animate-spin" /> Updating…</> : <>Reset password <ArrowRight size={16} /></>}
      </button>
    </form>
  );
}
