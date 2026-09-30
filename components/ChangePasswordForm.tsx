"use client";

import { useActionState } from "react";
import { changeOwnPassword, type ChangePasswordState } from "@/lib/actions/profile";
import { CheckCircle2, KeyRound, Loader2 } from "lucide-react";

const initialState: ChangePasswordState = {};

export function ChangePasswordForm() {
  const [state, formAction, pending] = useActionState(changeOwnPassword, initialState);

  return (
    <div className="card p-5">
      <p className="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><KeyRound size={15} /> Change password</p>
      <form action={formAction} className="space-y-3 max-w-sm" key={state.success ? "done" : "form"}>
        <div>
          <label className="field-label">Current password</label>
          <input name="currentPassword" type="password" required className="input" />
        </div>
        <div>
          <label className="field-label">New password</label>
          <input name="newPassword" type="password" required placeholder="At least 8 characters" className="input" />
        </div>
        <div>
          <label className="field-label">Confirm new password</label>
          <input name="confirmPassword" type="password" required className="input" />
        </div>

        {state.error && (
          <div className="text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">{state.error}</div>
        )}
        {state.success && (
          <div className="flex items-center gap-2 text-sm text-success bg-success-soft rounded-lg px-3 py-2.5">
            <CheckCircle2 size={15} /> Password updated.
          </div>
        )}

        <button type="submit" disabled={pending} className="btn btn-primary disabled:opacity-70">
          {pending ? <><Loader2 size={15} className="animate-spin" /> Updating…</> : "Update password"}
        </button>
      </form>
    </div>
  );
}
