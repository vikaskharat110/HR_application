import { requireSession, ROLE_LABEL } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { Sidebar } from "@/components/Sidebar";
import { MobileMenu } from "@/components/MobileMenu";
import { NotificationBell } from "@/components/NotificationBell";
import { UserMenu } from "@/components/UserMenu";
import { logout } from "@/lib/actions/auth";
import { hiddenHrefsForSession } from "@/lib/permissions";
import { ShieldAlert } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const session = await requireSession();

  // Company status is checked here, not baked into the JWT, so a
  // suspension/archive takes effect on the very next page load instead of
  // waiting out the 7-day session — without adding a DB round trip to every
  // page, since this layout already fetches the company once per request.
  const [company, notifications] = await Promise.all([
    session.companyId
      ? prisma.company.findUnique({ where: { id: session.companyId }, select: { name: true, status: true } })
      : Promise.resolve(null),
    prisma.notification.findMany({
      where: { userId: session.sub },
      orderBy: { createdAt: "desc" },
      take: 15,
    }),
  ]);

  if (company && company.status !== "ACTIVE") {
    return (
      <div className="min-h-screen grid place-items-center bg-[var(--color-surface)] px-6">
        <div className="card max-w-md w-full p-8 text-center">
          <div className="grid place-items-center w-12 h-12 rounded-full bg-danger-soft text-danger mx-auto mb-4">
            <ShieldAlert size={22} />
          </div>
          <h1 className="font-display text-xl font-semibold text-ink">
            {company.status === "SUSPENDED" ? "Access suspended" : "Workspace archived"}
          </h1>
          <p className="text-sm text-muted mt-2">
            {company.status === "SUSPENDED"
              ? "Your company's access to Verdant HR has been suspended by the platform administrator."
              : "Your company's workspace has been archived and is no longer accessible."}
          </p>
          <form action={logout} className="mt-6">
            <button type="submit" className="btn btn-secondary w-full justify-center">Sign out</button>
          </form>
        </div>
      </div>
    );
  }

  const companyName = company?.name ?? "Platform-wide";
  const hiddenHrefs = await hiddenHrefsForSession(session);

  return (
    <div className="flex min-h-screen bg-[var(--color-surface)]">
      <Sidebar role={session.role} companyName={companyName} hiddenHrefs={hiddenHrefs} />

      <div className="flex-1 flex flex-col min-w-0">
        <header className="flex items-center justify-between gap-3 px-4 sm:px-6 h-16 border-b border-[var(--color-border)] bg-[var(--color-card)]/80 backdrop-blur sticky top-0 z-40">
          <div className="flex items-center gap-2">
            <MobileMenu role={session.role} companyName={companyName} hiddenHrefs={hiddenHrefs} />
          </div>
          <div className="flex items-center gap-2">
            <NotificationBell items={notifications} />
            <div className="w-px h-6 bg-[var(--color-border)] mx-1" />
            <UserMenu name={session.name} roleLabel={session.customRoleName ?? ROLE_LABEL[session.role]} email={session.email} />
          </div>
        </header>

        <main className="flex-1 p-4 sm:p-6 lg:p-8 max-w-[1400px] w-full mx-auto">{children}</main>
      </div>
    </div>
  );
}
