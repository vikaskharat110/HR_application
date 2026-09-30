"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { Role } from "@prisma/client";
import { navForRole } from "@/lib/nav";
import { Leaf } from "lucide-react";

export function Sidebar({
  role,
  companyName,
  hiddenHrefs = [],
}: {
  role: Role;
  companyName: string;
  hiddenHrefs?: string[];
}) {
  const pathname = usePathname();
  const items = navForRole(role).filter((item) => !hiddenHrefs.includes(item.href));

  return (
    <aside className="hidden lg:flex w-56 shrink-0 flex-col border-r border-[var(--color-border)] bg-[var(--color-brand-dark)] text-[#eef3ea] sticky top-0 h-screen">
      <div className="flex items-center gap-2 px-5 py-5 border-b border-white/10">
        <div className="grid place-items-center w-8 h-8 rounded-lg bg-[var(--color-accent)] text-white">
          <Leaf size={18} />
        </div>
        <div className="leading-tight">
          <p className="font-display font-semibold text-[15px] text-white">Verdant HR</p>
          <p className="text-[11px] text-white/50 truncate max-w-[9.5rem]">{companyName}</p>
        </div>
      </div>

      <nav className="flex-1 min-h-0 overflow-y-auto no-scrollbar py-4 px-3 space-y-0.5">
        {items.map((item) => {
          const active =
            item.href === "/dashboard"
              ? pathname === "/dashboard"
              : pathname.startsWith(item.href);
          const Icon = item.icon;
          return (
            <Link
              key={item.href}
              href={item.href}
              className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                active
                  ? "bg-white/10 text-white"
                  : "text-white/65 hover:bg-white/5 hover:text-white"
              }`}
            >
              <Icon size={17} strokeWidth={2} />
              {item.label}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
