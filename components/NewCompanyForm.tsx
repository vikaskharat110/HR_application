"use client";

import { useActionState } from "react";
import Link from "next/link";
import { createCompany, type CreateCompanyState } from "@/lib/actions/companies";
import { AlertCircle, Loader2 } from "lucide-react";

const initialState: CreateCompanyState = {};

export function NewCompanyForm() {
  const [state, formAction, pending] = useActionState(createCompany, initialState);

  return (
    <form action={formAction} className="card p-6 space-y-4">
      <div>
        <label className="field-label">Company name</label>
        <input name="name" required className="input" />
      </div>
      <div>
        <label className="field-label">Industry</label>
        <input name="industry" className="input" />
      </div>
      <div>
        <label className="field-label">Company admin email</label>
        <input name="adminEmail" type="email" required className="input" />
        <p className="text-xs text-muted mt-1.5">A Company Admin login is created with a temporary password: <span className="font-mono">Welcome@123</span></p>
      </div>

      {state.error && (
        <div className="flex items-start gap-2 rounded-lg bg-[var(--color-danger-soft)] text-[var(--color-danger)] text-sm px-3 py-2.5">
          <AlertCircle size={16} className="mt-0.5 shrink-0" />
          <span>{state.error}</span>
        </div>
      )}

      <div className="flex justify-end gap-2">
        <Link href="/dashboard/companies" className="btn btn-secondary">Cancel</Link>
        <button type="submit" disabled={pending} className="btn btn-primary disabled:opacity-70">
          {pending ? <><Loader2 size={15} className="animate-spin" /> Creating…</> : "Create company"}
        </button>
      </div>
    </form>
  );
}

