"use client";

import { useState, type ReactNode } from "react";

export function TabbedPanels({ tabs }: { tabs: { key: string; label: string; content: ReactNode }[] }) {
  const [active, setActive] = useState(tabs[0]?.key);

  return (
    <div>
      <div className="inline-flex flex-wrap rounded-lg border border-border bg-card p-1 mb-4">
        {tabs.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setActive(t.key)}
            className={`px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors ${
              active === t.key ? "bg-brand text-white" : "text-ink-soft hover:text-ink"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tabs.map((t) => (
        <div key={t.key} hidden={active !== t.key}>
          {t.content}
        </div>
      ))}
    </div>
  );
}
