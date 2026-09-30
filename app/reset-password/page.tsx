import Link from "next/link";
import { Leaf, AlertCircle } from "lucide-react";
import { prisma } from "@/lib/prisma";
import { ResetPasswordForm } from "@/components/ResetPasswordForm";

export const dynamic = "force-dynamic";

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string }>;
}) {
  const { token } = await searchParams;

  const resetToken = token
    ? await prisma.passwordResetToken.findUnique({ where: { token } })
    : null;
  const isValid = !!resetToken && !resetToken.usedAt && resetToken.expiresAt > new Date();

  return (
    <div className="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-6 py-16">
      <div className="w-full max-w-sm">
        <div className="flex items-center gap-2.5 mb-8 justify-center">
          <div className="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-brand)] text-white">
            <Leaf size={18} />
          </div>
          <span className="font-display text-lg font-semibold text-[var(--color-ink)]">Verdant HR</span>
        </div>

        {isValid ? (
          <>
            <h1 className="font-display text-2xl font-semibold text-[var(--color-ink)] text-center">Choose a new password</h1>
            <div className="mt-7">
              <ResetPasswordForm token={token!} />
            </div>
          </>
        ) : (
          <div className="card p-6 text-center">
            <AlertCircle size={28} className="mx-auto text-[var(--color-danger)]" />
            <p className="mt-3 text-sm text-[var(--color-ink)] font-medium">This link is invalid or has expired</p>
            <p className="mt-1.5 text-sm text-[var(--color-muted)]">Password reset links are only valid for 30 minutes.</p>
            <Link href="/forgot-password" className="btn btn-primary w-full justify-center mt-5">Request a new link</Link>
          </div>
        )}
      </div>
    </div>
  );
}
