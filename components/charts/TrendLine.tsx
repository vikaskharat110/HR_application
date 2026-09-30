"use client";

import { ResponsiveContainer, LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid } from "recharts";

export function TrendLine({
  data,
  dataKey,
  xKey,
  unit,
}: {
  data: Record<string, string | number>[];
  dataKey: string;
  xKey: string;
  unit?: string;
}) {
  return (
    <div className="h-56 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: -12 }}>
          <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey={xKey} tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} />
          <YAxis tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} allowDecimals={false} />
          <Tooltip formatter={(v) => `${v}${unit ?? ""}`} />
          <Line
            type="monotone"
            dataKey={dataKey}
            stroke="var(--color-brand)"
            strokeWidth={2}
            dot={{ r: 3 }}
            activeDot={{ r: 5 }}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
