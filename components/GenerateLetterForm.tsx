"use client";

import { useActionState } from "react";
import { generateLetter, type GenerateLetterState } from "@/lib/actions/documents";
import { AlertCircle, CheckCircle2, Loader2 } from "lucide-react";
import type { LetterType } from "@prisma/client";

const initialState: GenerateLetterState = {};

export function GenerateLetterForm({
  employees,
  letterTypes,
  letterLabel,
}: {
  employees: { id: string; firstName: string; lastName: string }[];
  letterTypes: LetterType[];
  letterLabel: Record<LetterType, string>;
}) {
  const [state, formAction, pending] = useActionState(generateLetter, initialState);

  return (
    <form action={formAction} className="space-y-3">
      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="field-label">Employee</label>
          <select name="employeeId" required className="input">
            {employees.map((e) => <option key={e.id} value={e.id}>{e.firstName} {e.lastName}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label">Letter type</label>
          <select name="type" required className="input">
            {letterTypes.map((t) => <option key={t} value={t}>{letterLabel[t]}</option>)}
          </select>
        </div>
      </div>

      {state.error && (
        <div className="flex items-start gap-2 text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">
          <AlertCircle size={15} className="mt-0.5 shrink-0" /> <span>{state.error}</span>
        </div>
      )}
      {state.success && (
        <div className="flex items-center gap-2 text-sm text-success bg-success-soft rounded-lg px-3 py-2.5">
          <CheckCircle2 size={15} /> Letter generated — find it under &ldquo;All documents&rdquo;.
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center disabled:opacity-70">
        {pending ? <><Loader2 size={15} className="animate-spin" /> Generating…</> : "Generate"}
      </button>
    </form>
  );
}

