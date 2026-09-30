"use client";

import { useActionState } from "react";
import { updateCompanyLetterhead, type UpdateLetterheadState } from "@/lib/actions/settings";
import { AlertCircle, CheckCircle2, Loader2 } from "lucide-react";

const initialState: UpdateLetterheadState = {};

export function CompanyLetterheadForm({
  company,
}: {
  company: { logoUrl: string | null; address: string | null; phone: string | null; panNumber: string | null } | null;
}) {
  const [state, formAction, pending] = useActionState(updateCompanyLetterhead, initialState);

  return (
    <form action={formAction} className="space-y-4">
      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="field-label">Logo</label>
          {company?.logoUrl && company.logoUrl !== "#" && (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={company.logoUrl} alt="Current logo" className="h-10 mb-2 object-contain" />
          )}
          <input type="file" name="logo" accept="image/*" className="input" />
          <p className="text-xs text-muted mt-1">Up to 10MB. Leave blank to keep the current logo.</p>
        </div>
        <div>
          <label className="field-label">Phone</label>
          <input name="phone" defaultValue={company?.phone ?? ""} placeholder="e.g. 72765 45228" className="input" />
        </div>
      </div>

      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="field-label">Registered address</label>
          <textarea name="address" defaultValue={company?.address ?? ""} rows={3} placeholder="Full postal address for the letterhead" className="input" />
        </div>
        <div>
          <label className="field-label">PAN number</label>
          <input name="panNumber" defaultValue={company?.panNumber ?? ""} placeholder="e.g. AAJFF7660C" className="input" />
        </div>
      </div>

      {state.error && (
        <div className="flex items-start gap-2 text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">
          <AlertCircle size={15} className="mt-0.5 shrink-0" /> <span>{state.error}</span>
        </div>
      )}
      {state.success && (
        <div className="flex items-center gap-2 text-sm text-success bg-success-soft rounded-lg px-3 py-2.5">
          <CheckCircle2 size={15} /> Letterhead saved.
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary disabled:opacity-70">
        {pending ? <><Loader2 size={15} className="animate-spin" /> Saving…</> : "Save letterhead"}
      </button>
    </form>
  );
}
