"use client";

import { useState, useTransition } from "react";
import Link from "next/link";
import { Bell } from "lucide-react";
import { markAllNotificationsRead, markNotificationRead } from "@/lib/actions/notifications";
import { formatDateTime } from "@/lib/format";

type Item = {
  id: string;
  title: string;
  message: string;
  link: string | null;
  isRead: boolean;
  createdAt: Date | string;
};

export function NotificationBell({ items }: { items: Item[] }) {
  const [open, setOpen] = useState(false);
  const [, startTransition] = useTransition();
  const unread = items.filter((i) => !i.isRead).length;

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="relative grid place-items-center w-9 h-9 rounded-full hover:bg-[var(--color-surface)]"
        aria-label="Notifications"
      >
        <Bell size={18} />
        {unread > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] leading-4 text-center">
            {unread > 9 ? "9+" : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-80 max-w-[90vw] card z-50 overflow-hidden">
          <div className="flex items-center justify-between px-4 py-2.5 border-b border-[var(--color-border)]">
            <p className="text-sm font-semibold">Notifications</p>
            {unread > 0 && (
              <button
                type="button"
                className="text-xs text-[var(--color-brand)] hover:underline"
                onClick={() => startTransition(() => markAllNotificationsRead())}
              >
                Mark all read
              </button>
            )}
          </div>
          <ul className="max-h-96 overflow-y-auto">
            {items.length === 0 && <li className="px-4 py-6 text-sm text-center text-[var(--color-muted)]">You&apos;re all caught up.</li>}
            {items.map((n) => {
              const body = (
                <>
                  <p className={`text-sm ${n.isRead ? "" : "font-semibold"}`}>{n.title}</p>
                  <p className="text-xs text-[var(--color-muted)]">{n.message}</p>
                  <p className="text-[11px] text-[var(--color-muted)] mt-0.5">{formatDateTime(n.createdAt)}</p>
                </>
              );
              const onClick = () => {
                setOpen(false);
                if (!n.isRead) startTransition(() => markNotificationRead(n.id));
              };
              return (
                <li key={n.id} className="border-b last:border-0 border-[var(--color-border)]">
                  {n.link ? (
                    <Link href={n.link} onClick={onClick} className="block px-4 py-2.5 hover:bg-[var(--color-surface)]">
                      {body}
                    </Link>
                  ) : (
                    <button type="button" onClick={onClick} className="block w-full text-left px-4 py-2.5 hover:bg-[var(--color-surface)]">
                      {body}
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}
