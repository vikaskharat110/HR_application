"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { Role } from "@prisma/client";
import { Menu, X, Leaf } from "lucide-react";
import { navForRole } from "@/lib/nav";

export function MobileMenu({
  role,
  companyName,
  hiddenHrefs = [],
}: {
  role: Role;
  companyName: string;
  hiddenHrefs?: string[];
}) {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();
  const items = navForRole(role).filter((item) => !hiddenHrefs.includes(item.href));

  return (
    <div className="lg:hidden">
      <button onClick={() => setOpen(true)} className="grid place-items-center w-9 h-9 rounded-lg hover:bg-[var(--color-brand-softer)]" aria-label="Open menu">
        <Menu size={19} />
      </button>

      {open && (
        <div className="fixed inset-0 z-50 flex">
          <div className="w-72 h-full bg-[var(--color-brand-dark)] text-white flex flex-col">
            <div className="flex items-center justify-between px-5 py-5 border-b border-white/10">
              <div className="flex items-center gap-2">
                <div className="grid place-items-center w-8 h-8 rounded-lg bg-[var(--color-accent)]">
                  <Leaf size={18} />
                </div>
                <div>
                  <p className="font-display font-semibold text-[15px]">Verdant HR</p>
                  <p className="text-[11px] text-white/50">{companyName}</p>
                </div>
              </div>
              <button onClick={() => setOpen(false)} className="text-white/60 hover:text-white" aria-label="Close menu">
                <X size={20} />
              </button>
            </div>
            <nav className="flex-1 min-h-0 overflow-y-auto py-4 px-3 space-y-0.5">
              {items.map((item) => {
                const active = item.href === "/dashboard" ? pathname === "/dashboard" : pathname.startsWith(item.href);
                const Icon = item.icon;
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={() => setOpen(false)}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium ${
                      active ? "bg-white/10 text-white" : "text-white/65 hover:bg-white/5 hover:text-white"
                    }`}
                  >
                    <Icon size={17} /> {item.label}
                  </Link>
                );
              })}
            </nav>
          </div>
          <button className="flex-1 bg-black/30" onClick={() => setOpen(false)} aria-label="Close menu overlay" />
        </div>
      )}
    </div>
  );
}

