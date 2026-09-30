import { Leaf } from "lucide-react";
import { ForgotPasswordForm } from "@/components/ForgotPasswordForm";

export const dynamic = "force-dynamic";

export default function ForgotPasswordPage() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-6 py-16">
      <div className="w-full max-w-sm">
        <div className="flex items-center gap-2.5 mb-8 justify-center">
          <div className="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-brand)] text-white">
            <Leaf size={18} />
          </div>
          <span className="font-display text-lg font-semibold text-[var(--color-ink)]">Verdant HR</span>
        </div>

        <h1 className="font-display text-2xl font-semibold text-[var(--color-ink)] text-center">Reset your password</h1>
        <p className="mt-1.5 text-sm text-[var(--color-muted)] text-center">
          Enter your work email and we&rsquo;ll send you a link to reset it.
        </p>

        <div className="mt-7">
          <ForgotPasswordForm />
        </div>
      </div>
    </div>
  );
}
