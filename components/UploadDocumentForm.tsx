"use client";

import { useActionState, useState } from "react";
import { uploadDocument, type UploadDocumentState } from "@/lib/actions/documents";
import { AlertCircle, CheckCircle2, Loader2 } from "lucide-react";

const MAX_UPLOAD_MB = 10;

const initialState: UploadDocumentState = {};

type EmployeeOption = { id: string; firstName: string; lastName: string; department: { id: string; name: string } | null };

export function UploadDocumentForm({ employees }: { employees: EmployeeOption[] }) {
  const [state, formAction, pending] = useActionState(uploadDocument, initialState);
  const [clientError, setClientError] = useState<string | null>(null);
  const [scope, setScope] = useState<"company" | "employee">("company");
  const [department, setDepartment] = useState("");

  const departments = Array.from(
    new Map(employees.filter((e) => e.department).map((e) => [e.department!.id, e.department!.name])).entries()
  ).sort((a, b) => a[1].localeCompare(b[1]));

  const filteredEmployees = department ? employees.filter((e) => e.department?.id === department) : employees;

  return (
    <form
      action={formAction}
      className="space-y-3"
      onSubmit={(e) => {
        const file = (e.currentTarget.elements.namedItem("file") as HTMLInputElement | null)?.files?.[0];
        if (file && file.size > MAX_UPLOAD_MB * 1024 * 1024) {
          e.preventDefault();
          setClientError(`That file is larger than ${MAX_UPLOAD_MB}MB — pick a smaller one.`);
        } else {
          setClientError(null);
        }
      }}
    >
      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="field-label">File</label>
          <input type="file" name="file" required className="input" />
          <p className="text-xs text-muted mt-1">Up to {MAX_UPLOAD_MB}MB.</p>
        </div>
        <div>
          <label className="field-label">Display name</label>
          <input name="name" className="input" placeholder="Defaults to file name" />
        </div>
      </div>
      <div className="grid sm:grid-cols-2 gap-4">
        <div>
          <label className="field-label">Type</label>
          <select name="type" className="input">
            <option value="POLICY">Policy</option>
            <option value="OFFER_LETTER">Offer letter</option>
            <option value="CONTRACT">Contract</option>
            <option value="ID_PROOF">ID proof</option>
            <option value="CERTIFICATE">Certificate</option>
            <option value="OTHER">Other</option>
          </select>
        </div>
        <div>
          <label className="field-label">Applies to</label>
          <div className="flex gap-3 mb-2">
            <label className="flex items-center gap-1.5 text-sm text-ink-soft">
              <input type="radio" checked={scope === "company"} onChange={() => setScope("company")} className="accent-brand" />
              Whole company
            </label>
            <label className="flex items-center gap-1.5 text-sm text-ink-soft">
              <input type="radio" checked={scope === "employee"} onChange={() => setScope("employee")} className="accent-brand" />
              One employee
            </label>
          </div>
          {scope === "employee" && (
            <div className="grid grid-cols-2 gap-2">
              <select value={department} onChange={(e) => setDepartment(e.target.value)} className="input" aria-label="Filter by department">
                <option value="">All departments</option>
                {departments.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
              </select>
              <select name="employeeId" required defaultValue="" className="input">
                <option value="" disabled>{filteredEmployees.length === 0 ? "No employees in this department" : "Select employee"}</option>
                {filteredEmployees.map((e) => <option key={e.id} value={e.id}>{e.firstName} {e.lastName}</option>)}
              </select>
            </div>
          )}
        </div>
      </div>
      <div className="sm:w-1/2 sm:pr-2">
        <label className="field-label">Expiry date (optional)</label>
        <input type="date" name="expiryDate" className="input" />
      </div>

      {(clientError || state.error) && (
        <div className="flex items-start gap-2 text-sm text-danger bg-danger-soft rounded-lg px-3 py-2.5">
          <AlertCircle size={15} className="mt-0.5 shrink-0" /> <span>{clientError ?? state.error}</span>
        </div>
      )}
      {state.success && (
        <div className="flex items-center gap-2 text-sm text-success bg-success-soft rounded-lg px-3 py-2.5">
          <CheckCircle2 size={15} /> Uploaded.
        </div>
      )}

      <button type="submit" disabled={pending} className="btn btn-primary w-full justify-center disabled:opacity-70">
        {pending ? <><Loader2 size={15} className="animate-spin" /> Uploading…</> : "Upload"}
      </button>
    </form>
  );
}
