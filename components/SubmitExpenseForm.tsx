"use client";

import { useActionState } from "react";
import { submitExpense, type SubmitExpenseState } from "@/lib/actions/expenses";
import { AlertCircle, CheckCircle2, Loader2 } from "lucide-react";

const initialState: SubmitExpenseState = {};

export function SubmitExpenseForm({ categories }: { categories: string[] }) {
  const [state, formAction, pending] = useActionState(submitExpense, initialState);

  return (
    <form action={formAction} className="space-y-4">
      <div>
        <label className="field-label">Category</label>
        <select name="category" className="input">
          {categories.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>
      <div>
        <label className="field-label">Amount (₹)</label>
        <input type="number" name="amount" min={1} required className="input" />
      </div>
      <div>
        <label className="field-label">Description</label>
        <textarea name="description" rows={3} className="input" />
      </div>

      {state.error && (
        <div className="flex items-start gap-2 text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">
          <AlertCircle size={15} className="mt-0.5 shrink-0" /> <span>{state.error}</span>
        </div>
      )}
      {state.success && (
        <div className="flex items-center gap-2 text-sm text-success bg-success-soft rounded-lg px-3 py-2.5">
          <CheckCircle2 size={15} /> Claim submitted.
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center disabled:opacity-70">
        {pending ? <><Loader2 size={15} className="animate-spin" /> Submitting…</> : "Submit claim"}
      </button>
    </form>
  );
}
