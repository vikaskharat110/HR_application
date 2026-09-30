import type { ReactNode } from "react";
import Link from "next/link";
import { ChevronLeft, ChevronRight } from "lucide-react";
import type { LucideIcon } from "lucide-react";

export function PageHeader({
  title,
  subtitle,
  action,
}: {
  title: string;
  subtitle?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
      <div>
        <h1 className="font-display text-2xl font-semibold text-[var(--color-ink)]">{title}</h1>
        {subtitle && <p className="text-sm text-[var(--color-muted)] mt-1">{subtitle}</p>}
      </div>
      {action && <div className="flex items-center gap-2">{action}</div>}
    </div>
  );
}

export function StatCard({
  label,
  value,
  icon: Icon,
  tone = "brand",
  hint,
}: {
  label: string;
  value: string | number;
  icon: LucideIcon;
  tone?: "brand" | "accent" | "info" | "warning" | "danger";
  hint?: string;
}) {
  const toneMap: Record<string, string> = {
    brand: "bg-[var(--color-brand-soft)] text-[var(--color-brand)]",
    accent: "bg-[var(--color-accent-soft)] text-[var(--color-accent-dark)]",
    info: "bg-[var(--color-info-soft)] text-[var(--color-info)]",
    warning: "bg-[var(--color-warning-soft)] text-[var(--color-warning)]",
    danger: "bg-[var(--color-danger-soft)] text-[var(--color-danger)]",
  };
  return (
    <div className="stat-card flex items-start justify-between">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]">{label}</p>
        <p className="font-display text-2xl font-semibold text-[var(--color-ink)] mt-1.5">{value}</p>
        {hint && <p className="text-xs text-[var(--color-muted)] mt-1">{hint}</p>}
      </div>
      <div className={`grid place-items-center w-10 h-10 rounded-lg shrink-0 ${toneMap[tone]}`}>
        <Icon size={18} />
      </div>
    </div>
  );
}

export function EmptyState({ icon: Icon, title, message }: { icon: LucideIcon; title: string; message?: string }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-14 px-6">
      <div className="grid place-items-center w-12 h-12 rounded-full bg-[var(--color-brand-softer)] text-[var(--color-brand)] mb-3">
        <Icon size={20} />
      </div>
      <p className="text-sm font-semibold text-[var(--color-ink)]">{title}</p>
      {message && <p className="text-sm text-[var(--color-muted)] mt-1 max-w-sm">{message}</p>}
    </div>
  );
}

const STATUS_VARIANTS: Record<string, string> = {
  APPROVED: "badge-success",
  PAID: "badge-success",
  PROCESSED: "badge-success",
  PRESENT: "badge-success",
  RESOLVED: "badge-success",
  CLOSED: "badge-neutral",
  HIRED: "badge-success",
  SELECTED: "badge-success",
  OFFERED: "badge-info",
  PENDING: "badge-warning",
  LATE: "badge-warning",
  IN_PROGRESS: "badge-info",
  SCREENING: "badge-info",
  INTERVIEW: "badge-info",
  APPLIED: "badge-neutral",
  DRAFT: "badge-neutral",
  ON_LEAVE: "badge-info",
  HALF_DAY: "badge-warning",
  WEEK_OFF: "badge-neutral",
  HOLIDAY: "badge-neutral",
  REJECTED: "badge-danger",
  ABSENT: "badge-danger",
  CANCELLED: "badge-neutral",
  OPEN: "badge-warning",
  AVAILABLE: "badge-success",
  ASSIGNED: "badge-info",
  MAINTENANCE: "badge-warning",
  RETIRED: "badge-neutral",
  ACTIVE: "badge-success",
  SUSPENDED: "badge-danger",
  ARCHIVED: "badge-neutral",
  ON_HOLD: "badge-warning",
  UPCOMING: "badge-info",
  NOTICE_PERIOD: "badge-warning",
  EXITED: "badge-neutral",
  URGENT: "badge-danger",
  HIGH: "badge-warning",
  MEDIUM: "badge-info",
  LOW: "badge-neutral",
  LOCKED: "badge-success",
  DONE: "badge-success",
  PENDING_CLEARANCE: "badge-warning",
  CLEARED: "badge-success",
  MANAGER_APPROVED: "badge-info",
  HR_APPROVED: "badge-info",
  CLEARANCE: "badge-warning",
  SETTLED: "badge-success",
  COMPLETED: "badge-success",
  CONFIRMED: "badge-success",
  EXTENDED: "badge-warning",
};

export function StatusBadge({ status }: { status: string }) {
  const variant = STATUS_VARIANTS[status] ?? "badge-neutral";
  return <span className={`badge ${variant}`}>{status.replace(/_/g, " ").toLowerCase()}</span>;
}

/** Prev/Next pager — same pattern as the Audit Log page, factored out so every growing list uses it. */
export function Pagination({
  page,
  totalPages,
  basePath,
  query = {},
}: {
  page: number;
  totalPages: number;
  basePath: string;
  query?: Record<string, string | undefined>;
}) {
  if (totalPages <= 1) return null;

  function href(p: number) {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) if (v) params.set(k, v);
    params.set("page", String(p));
    return `${basePath}?${params.toString()}`;
  }

  return (
    <div className="flex items-center justify-between mt-4 text-sm">
      <Link
        href={href(page - 1)}
        aria-disabled={page <= 1}
        className={`btn btn-secondary ${page <= 1 ? "pointer-events-none opacity-40" : ""}`}
      >
        <ChevronLeft size={14} /> Previous
      </Link>
      <span className="text-muted">Page {page} of {totalPages}</span>
      <Link
        href={href(page + 1)}
        aria-disabled={page >= totalPages}
        className={`btn btn-secondary ${page >= totalPages ? "pointer-events-none opacity-40" : ""}`}
      >
        Next <ChevronRight size={14} />
      </Link>
    </div>
  );
}

export function Avatar({ firstName, lastName, color }: { firstName: string; lastName: string; color?: string }) {
  return (
    <span
      className="grid place-items-center w-8 h-8 rounded-full text-white text-[11px] font-semibold shrink-0"
      style={{ background: color ?? "var(--color-brand)" }}
    >
      {firstName?.[0]}
      {lastName?.[0]}
    </span>
  );
}
