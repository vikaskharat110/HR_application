"use client";

import { useActionState } from "react";
import Link from "next/link";
import { login, type LoginState } from "@/lib/actions/auth";
import { AlertCircle, ArrowRight, Loader2 } from "lucide-react";

const initialState: LoginState = {};

export function LoginForm({ next }: { next: string }) {
  const [state, formAction, pending] = useActionState(login, initialState);

  return (
    <form action={formAction} className="space-y-4">
      <input type="hidden" name="next" value={next} />

      <div>
        <label className="field-label" htmlFor="email">Work email</label>
        <input
          id="email"
          name="email"
          type="email"
          required
          autoFocus
          placeholder="you@company.com"
          className="input"
        />
      </div>

      <div>
        <div className="flex items-center justify-between">
          <label className="field-label" htmlFor="password">Password</label>
          <Link href="/forgot-password" className="text-xs text-brand font-medium hover:underline mb-1.5">
            Forgot password?
          </Link>
        </div>
        <input
          id="password"
          name="password"
          type="password"
          required
          placeholder="••••••••"
          className="input"
        />
      </div>

      {state.error && (
        <div className="flex items-start gap-2 rounded-lg bg-[var(--color-danger-soft)] text-[var(--color-danger)] text-sm px-3 py-2.5">
          <AlertCircle size={16} className="mt-0.5 shrink-0" />
          <span>{state.error}</span>
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70">
        {pending ? (
          <>
            <Loader2 size={16} className="animate-spin" /> Signing in…
          </>
        ) : (
          <>
            Sign in <ArrowRight size={16} />
          </>
        )}
      </button>
    </form>
  );
}
