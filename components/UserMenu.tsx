"use client";

import { useState, useRef, useEffect } from "react";
import Link from "next/link";
import { ChevronDown, LogOut, User } from "lucide-react";
import { logout } from "@/lib/actions/auth";
import { initials } from "@/lib/format";

export function UserMenu({
  name,
  roleLabel,
  email,
}: {
  name: string;
  roleLabel: string;
  email: string;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const [first, ...rest] = name.split(" ");
  const last = rest.join(" ");

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2 rounded-lg pl-1.5 pr-2 py-1.5 hover:bg-[var(--color-brand-softer)]"
      >
        <span className="grid place-items-center w-7 h-7 rounded-full bg-[var(--color-brand)] text-white text-[11px] font-semibold">
          {initials(first ?? "", last ?? "")}
        </span>
        <span className="hidden sm:block text-left">
          <span className="block text-sm font-medium text-[var(--color-ink)] leading-tight">{name}</span>
          <span className="block text-[11px] text-[var(--color-muted)] leading-tight">{roleLabel}</span>
        </span>
        <ChevronDown size={14} className="text-[var(--color-muted)]" />
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-52 card shadow-lg py-1.5 z-50">
          <div className="px-3.5 py-2 border-b border-[var(--color-border-soft)]">
            <p className="text-sm font-medium text-[var(--color-ink)] flex items-center gap-1.5">
              <User size={13} /> {name}
            </p>
            <p className="text-xs text-[var(--color-muted)] mt-0.5 truncate">{email}</p>
          </div>
          <Link
            href="/dashboard/profile"
            className="flex items-center gap-2 px-3.5 py-2 text-sm text-[var(--color-ink-soft)] hover:bg-[var(--color-brand-softer)]"
            onClick={() => setOpen(false)}
          >
            <User size={15} /> My profile
          </Link>
          <form action={logout}>
            <button type="submit" className="w-full flex items-center gap-2 px-3.5 py-2 text-sm text-[var(--color-danger)] hover:bg-[var(--color-danger-soft)]">
              <LogOut size={15} /> Sign out
            </button>
          </form>
        </div>
      )}
    </div>
  );
}
