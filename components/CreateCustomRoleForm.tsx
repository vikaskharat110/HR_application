"use client";

import { useActionState } from "react";
import { createCustomRole, type CreateCustomRoleState } from "@/lib/actions/customRoles";
import { AlertCircle, Loader2 } from "lucide-react";
import type { Role } from "@prisma/client";

const initialState: CreateCustomRoleState = {};

export function CreateCustomRoleForm({ baseRoles }: { baseRoles: { value: Role; label: string }[] }) {
  const [state, formAction, pending] = useActionState(createCustomRole, initialState);

  return (
    <form action={formAction} className="space-y-3" key={state.error ? "error" : "ok"}>
      <div>
        <label className="field-label">Role name</label>
        <input name="name" required placeholder="e.g. Team Lead" className="input" />
      </div>
      <div>
        <label className="field-label">Based on</label>
        <select name="baseRole" required className="input">
          {baseRoles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
        </select>
        <p className="text-xs text-muted mt-1.5">
          This role inherits everything the base role can do (approvals, who it can act on, payroll rights). Only
          which dashboard sections it can see is independently adjustable below.
        </p>
      </div>

      {state.error && (
        <div className="flex items-start gap-2 text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">
          <AlertCircle size={15} className="mt-0.5 shrink-0" /> <span>{state.error}</span>
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center disabled:opacity-70">
        {pending ? <><Loader2 size={15} className="animate-spin" /> Creating…</> : "Create role"}
      </button>
    </form>
  );
}
