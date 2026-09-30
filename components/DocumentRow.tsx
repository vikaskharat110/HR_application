"use client";

import { useActionState, useEffect, useState } from "react";
import { Download, FileText, Pencil, Trash2, CheckCircle2, AlertCircle } from "lucide-react";
import { formatDate } from "@/lib/format";
import { updateDocument, deleteDocument, type UpdateDocumentState } from "@/lib/actions/documents";
import { ConfirmSubmitButton } from "@/components/ConfirmSubmitButton";
import type { DocumentType, Role } from "@prisma/client";

const TYPE_OPTIONS: { value: DocumentType; label: string }[] = [
  { value: "POLICY", label: "Policy" },
  { value: "OFFER_LETTER", label: "Offer letter" },
  { value: "CONTRACT", label: "Contract" },
  { value: "ID_PROOF", label: "ID proof" },
  { value: "CERTIFICATE", label: "Certificate" },
  { value: "OTHER", label: "Other" },
];
const SELF_TYPE_OPTIONS = TYPE_OPTIONS.filter((t) => ["ID_PROOF", "CERTIFICATE", "OTHER"].includes(t.value));

type DocRow = {
  id: string;
  name: string;
  type: DocumentType;
  fileUrl: string;
  uploadedAt: Date;
  expiryDate: Date | null;
  employeeId: string | null;
};

const initialState: UpdateDocumentState = {};

export function DocumentRow({
  d,
  viewerRole,
  viewerEmployeeId,
}: {
  d: DocRow;
  viewerRole: Role;
  viewerEmployeeId: string | null;
}) {
  const isAdminOrHR = viewerRole === "COMPANY_ADMIN" || viewerRole === "HR";
  const isOwner = d.employeeId !== null && d.employeeId === viewerEmployeeId;
  const canManage = isAdminOrHR || isOwner;
  const typeOptions = isAdminOrHR ? TYPE_OPTIONS : SELF_TYPE_OPTIONS;

  const [open, setOpen] = useState(false);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [state, formAction, pending] = useActionState(updateDocument, initialState);

  // Close the edit panel and flash a confirmation once the save actually
  // succeeds — leaving it open with no feedback (the previous behavior) made
  // it look like nothing happened.
  useEffect(() => {
    if (state.success) {
      setOpen(false);
      setSavedAt(Date.now());
      const t = setTimeout(() => setSavedAt(null), 3000);
      return () => clearTimeout(t);
    }
  }, [state.success]);

  return (
    <li className="py-2.5">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2.5 min-w-0">
          <FileText size={16} className="text-brand shrink-0" />
          <div className="min-w-0">
            <p className="text-sm font-medium text-ink truncate">{d.name}</p>
            <p className="text-xs text-muted">
              {formatDate(d.uploadedAt)}
              {d.expiryDate && ` · expires ${formatDate(d.expiryDate)}`}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-3 shrink-0">
          {savedAt && (
            <span className="flex items-center gap-1 text-xs text-success">
              <CheckCircle2 size={14} /> Saved
            </span>
          )}
          {d.fileUrl !== "#" && (
            <a href={d.fileUrl} target="_blank" rel="noreferrer" className="text-brand" title="Download">
              <Download size={16} />
            </a>
          )}
          {canManage && (
            <>
              <details open={open} onToggle={(e) => setOpen(e.currentTarget.open)} className="relative">
                <summary className="text-muted hover:text-ink cursor-pointer list-none" title="Edit">
                  <Pencil size={15} />
                </summary>
                <form action={formAction} className="mt-2 w-64 card p-3 space-y-2 shadow-lg absolute right-0 z-10">
                  <input type="hidden" name="documentId" value={d.id} />
                  <input name="name" defaultValue={d.name} placeholder="Display name" className="input text-sm" />
                  <select name="type" defaultValue={d.type} className="input text-sm">
                    {typeOptions.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                  <div>
                    <label className="field-label">Expiry date</label>
                    <input type="date" name="expiryDate" defaultValue={d.expiryDate ? d.expiryDate.toISOString().slice(0, 10) : ""} className="input text-sm" />
                  </div>
                  <div>
                    <label className="field-label">Replace file (optional)</label>
                    <input type="file" name="file" className="input text-sm" />
                  </div>
                  {state.error && (
                    <div className="flex items-start gap-1.5 text-xs text-danger bg-danger-soft rounded-lg px-2.5 py-2">
                      <AlertCircle size={13} className="mt-0.5 shrink-0" /> <span>{state.error}</span>
                    </div>
                  )}
                  <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center text-sm disabled:opacity-70">
                    {pending ? "Saving…" : "Save changes"}
                  </button>
                </form>
              </details>
              <form action={deleteDocument}>
                <input type="hidden" name="documentId" value={d.id} />
                <ConfirmSubmitButton
                  confirmMessage={`Delete "${d.name}"? This can't be undone.`}
                  className="text-danger hover:opacity-70"
                  title="Delete"
                >
                  <Trash2 size={15} />
                </ConfirmSubmitButton>
              </form>
            </>
          )}
        </div>
      </div>
    </li>
  );
}

