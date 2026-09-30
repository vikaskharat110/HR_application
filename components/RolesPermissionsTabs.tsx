"use client";

import { useState, type ReactNode } from "react";

export function RolesPermissionsTabs({
  modulesPanel,
  payrollPanel,
}: {
  modulesPanel: ReactNode;
  payrollPanel: ReactNode;
}) {
  const [tab, setTab] = useState<"modules" | "payroll">("modules");

  return (
    <div>
      <div className="inline-flex rounded-lg border border-[var(--color-border)] bg-[var(--color-card)] p-1 mb-4">
        <button
          type="button"
          onClick={() => setTab("modules")}
          className={`px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors ${
            tab === "modules" ? "bg-[var(--color-brand)] text-white" : "text-ink-soft hover:text-ink"
          }`}
        >
          Module access
        </button>
        <button
          type="button"
          onClick={() => setTab("payroll")}
          className={`px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors ${
            tab === "payroll" ? "bg-[var(--color-brand)] text-white" : "text-ink-soft hover:text-ink"
          }`}
        >
          Payroll access control
        </button>
      </div>

      <div hidden={tab !== "modules"}>{modulesPanel}</div>
      <div hidden={tab !== "payroll"}>{payrollPanel}</div>
    </div>
  );
}
