"use client";

import { useActionState } from "react";
import { runPayroll, type RunPayrollState } from "@/lib/actions/payroll";
import { AlertCircle, Loader2, Play } from "lucide-react";

const initialState: RunPayrollState = {};

export function RunPayrollButton({ month, year, label }: { month: number; year: number; label: string }) {
  const [state, formAction, pending] = useActionState(runPayroll, initialState);
  const defaultPayDate = new Date(year, month, 0).toISOString().slice(0, 10); // last day of the month

  return (
    <div>
      <form action={formAction} className="flex items-end gap-2">
        <input type="hidden" name="month" value={month} />
        <input type="hidden" name="year" value={year} />
        <div>
          <label className="field-label">Salary date</label>
          <input type="date" name="payDate" required defaultValue={defaultPayDate} className="input" />
        </div>
        <button type="submit" disabled={pending} className="btn btn-primary disabled:opacity-70">
          {pending ? <><Loader2 size={15} className="animate-spin" /> Running…</> : <><Play size={15} /> {label}</>}
        </button>
      </form>
      {state.error && (
        <div className="flex items-center gap-1.5 text-xs text-danger mt-1.5">
          <AlertCircle size={13} className="shrink-0" /> {state.error}
        </div>
      )}
    </div>
  );
}
