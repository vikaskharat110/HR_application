"use client";

import { useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import type { ReactNode } from "react";

/**s
 * "Back to X" everywhere used to be an a plain <Link> to a fixed URL, which
 * always lands on that page's default state (e.g. Documents always re-opens
 * on its first tab) instead of wherever the user actually came from. This
 * uses real browser history so it restores whatever tab/filter/scroll
 * position was there — falling back to the fixed href only when there's
 * nothing to go back to (a bookmarked or freshly-opened URL).
 */
export function BackLink({ fallbackHref, children }: { fallbackHref: string; children: ReactNode }) {
  const router = useRouter();

  return (
    <button
      type="button"
      onClick={() => {
        if (window.history.length > 1) {
          router.back();
        } else {
          router.push(fallbackHref);
        }
      }}
      className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink mb-4"
    >
      <ArrowLeft size={15} /> {children}
    </button>
  );
}
