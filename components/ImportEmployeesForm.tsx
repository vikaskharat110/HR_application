"use client";

import { useActionState } from "react";
import { importEmployees, type ImportEmployeesState } from "@/lib/actions/employees";
import { AlertCircle, CheckCircle2, Loader2, UploadCloud } from "lucide-react";

const initialState: ImportEmployeesState = {};

export function ImportEmployeesForm() {
  const [state, formAction, pending] = useActionState(importEmployees, initialState);

  return (
    <div className="space-y-5">
      <form action={formAction} className="card p-6 space-y-4">
        <div>
          <label className="field-label">CSV file</label>
          <input type="file" name="file" accept=".csv,text/csv" required className="input" />
          <p className="text-xs text-muted mt-1.5">
            Columns must match the template — download it below if you haven&apos;t already.
          </p>
        </div>

        {state.error && (
          <div className="flex items-start gap-2 rounded-lg bg-[var(--color-danger-soft)] text-[var(--color-danger)] text-sm px-3 py-2.5">
            <AlertCircle size={16} className="mt-0.5 shrink-0" />
            <span>{state.error}</span>
          </div>
        )}

        <button type="submit" disabled={pending} className="btn btn-primary disabled:opacity-70">
          {pending ? <><Loader2 size={16} className="animate-spin" /> Importing…</> : <><UploadCloud size={16} /> Import</>}
        </button>
      </form>

      {state.done && (
        <div className="card p-6">
          <div className="flex items-center gap-2 text-sm font-semibold text-ink mb-3">
            <CheckCircle2 size={16} className="text-brand" /> Import complete
          </div>
          <div className="flex items-center gap-4 text-sm mb-4">
            <span className="text-ink-soft"><span className="font-semibold text-ink">{state.created}</span> created</span>
            <span className="text-ink-soft"><span className="font-semibold text-ink">{state.updated}</span> updated</span>
            <span className="text-ink-soft"><span className="font-semibold text-danger">{state.errors?.length ?? 0}</span> failed</span>
          </div>
          {state.errors && state.errors.length > 0 && (
            <div className="max-h-64 overflow-y-auto border-t border-border-soft pt-3">
              <ul className="space-y-1.5">
                {state.errors.map((e) => (
                  <li key={e.row} className="text-xs text-ink-soft">
                    <span className="font-mono text-danger">Row {e.row}</span> — {e.message}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
