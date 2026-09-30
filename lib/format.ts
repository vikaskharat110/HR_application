import { format } from "date-fns";

type DateInput = Date | string | number | null | undefined;

const DASH = "—";

export function formatDate(d: DateInput) {
  if (d == null) return DASH;
  return format(new Date(d), "dd MMM yyyy");
}

export function formatTime(d: DateInput) {
  if (d == null) return DASH;
  return format(new Date(d), "hh:mm a");
}

export function formatDateTime(d: DateInput) {
  if (d == null) return DASH;
  return format(new Date(d), "dd MMM yyyy, hh:mm a");
}

export function formatCurrency(n: number) {
  return new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: 2,
  }).format(n);
}

/** 1-based month number -> "January". */
export function monthName(month: number) {
  return format(new Date(2000, month - 1, 1), "MMMM");
}

export function initials(first: string, last = "") {
  return `${first[0] ?? ""}${last[0] ?? ""}`.toUpperCase();
}
