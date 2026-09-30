"use client";

import { ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip, CartesianGrid } from "recharts";

export function RankBar({
  data,
  dataKey,
  xKey,
  horizontal,
}: {
  data: Record<string, string | number>[];
  dataKey: string;
  xKey: string;
  horizontal?: boolean;
}) {
  const height = horizontal ? Math.max(180, data.length * 36) : 224;
  return (
    <div className="w-full" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} layout={horizontal ? "vertical" : "horizontal"} margin={{ top: 4, right: 12, bottom: 0, left: 4 }}>
          <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" horizontal={!horizontal} vertical={!!horizontal} />
          {horizontal ? (
            <>
              <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} />
              <YAxis type="category" dataKey={xKey} width={110} tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} />
            </>
          ) : (
            <>
              <XAxis dataKey={xKey} tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} />
              <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: "var(--color-muted)" }} tickLine={false} axisLine={false} />
            </>
          )}
          <Tooltip />
          <Bar dataKey={dataKey} fill="var(--color-brand)" radius={4} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
