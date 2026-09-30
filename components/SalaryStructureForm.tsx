"use client";

import { useState } from "react";
import { upsertSalaryStructure } from "@/lib/actions/payroll";

type Mode = "MONTHLY" | "YEARLY";

const FIELDS = [
  { name: "basic", label: "Basic" },
  { name: "hra", label: "HRA" },
  { name: "conveyance", label: "Conveyance" },
  { name: "medicalAllowance", label: "Medical allowance" },
  { name: "specialAllowance", label: "Special allowance" },
] as const;

type FieldName = (typeof FIELDS)[number]["name"];

/** Salary structures are always stored monthly in the DB — this just lets the
 * admin think and type in whichever unit is natural, converting on submit. */
export function SalaryStructureForm({
  employeeId,
  monthly,
}: {
  employeeId: string;
  monthly?: Partial<Record<FieldName, number>> | null;
}) {
  const [mode, setMode] = useState<Mode>("MONTHLY");
  const factor = mode === "YEARLY" ? 12 : 1;

  return (
    <form action={upsertSalaryStructure} className="mt-4">
      <input type="hidden" name="employeeId" value={employeeId} />
      <input type="hidden" name="payPeriod" value={mode} />

      <div className="flex items-center gap-2 mb-3">
        <label className="field-label mb-0">Enter figures as</label>
        <select
          value={mode}
          onChange={(e) => setMode(e.target.value as Mode)}
          className="input w-auto! text-sm"
        >
          <option value="MONTHLY">Monthly</option>
          <option value="YEARLY">Yearly</option>
        </select>
      </div>

      <div className="grid sm:grid-cols-3 gap-3">
        {FIELDS.map((f) => {
          const base = monthly?.[f.name];
          return (
            <div key={f.name}>
              <label className="field-label">{f.label} <span className="text-muted font-normal">({mode === "YEARLY" ? "per year" : "per month"})</span></label>
              <input
                key={`${f.name}-${mode}`}
                type="number"
                name={f.name}
                min={0}
                step={1}
                defaultValue={base != null ? base * factor : ""}
                className="input"
              />
            </div>
          );
        })}
        <div className="flex items-end">
          <button type="submit" className="btn btn-primary w-full justify-center">Save &amp; recalculate</button>
        </div>
      </div>
    </form>
  );
}
