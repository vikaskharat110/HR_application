"use client";

import { useActionState, useEffect, useRef, useState } from "react";
import { CheckCircle2, FileText, X } from "lucide-react";
import { upsertLetterTemplate, type UpsertLetterTemplateState } from "@/lib/actions/settings";
import type { LetterTemplate, LetterType } from "@prisma/client";

const initialState: UpsertLetterTemplateState = {};

export function LetterTemplateEditor({
  letterTypes,
  letterLabel,
  templates,
}: {
  letterTypes: LetterType[];
  letterLabel: Record<LetterType, string>;
  templates: LetterTemplate[];
}) {
  const templateByType = new Map(templates.map((t) => [t.type, t]));
  const [selected, setSelected] = useState<LetterType>(letterTypes[0]);
  const [state, formAction, pending] = useActionState(upsertLetterTemplate, initialState);
  const [savedAt, setSavedAt] = useState<number | null>(null);

  useEffect(() => {
    if (state.success) {
      setSavedAt(Date.now());
      const t = setTimeout(() => setSavedAt(null), 3000);
      return () => clearTimeout(t);
    }
  }, [state.success]);

  const tpl = templateByType.get(selected);
  const bodyRef = useRef<HTMLTextAreaElement>(null);
  const [removeFile, setRemoveFile] = useState(false);

  function autoGrow(el: HTMLTextAreaElement) {
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  }

  useEffect(() => {
    if (bodyRef.current) autoGrow(bodyRef.current);
    setRemoveFile(false);
  }, [selected]);

  return (
    <div>
      <p className="text-xs font-semibold uppercase text-muted mb-2">Choose a template to edit</p>
      <div className="flex flex-wrap gap-1.5 mb-4">
        {letterTypes.map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setSelected(t)}
            className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
              selected === t
                ? "bg-brand text-white"
                : "bg-card border border-border text-ink-soft hover:text-ink hover:bg-brand-softer"
            }`}
          >
            {letterLabel[t]}
          </button>
        ))}
      </div>

      <form key={selected} action={formAction} className="space-y-3">
        <input type="hidden" name="type" value={selected} />

        <div className="rounded-lg border border-dashed border-border p-3.5 bg-brand-softer/40">
          <label className="field-label">Word template (optional)</label>
          <p className="text-xs text-muted mb-2">
            Upload your own branded .docx letter with <code className="font-mono">{"{{employeeName}}"}</code>,{" "}
            <code className="font-mono">{"{{designation}}"}</code>, <code className="font-mono">{"{{department}}"}</code>,{" "}
            <code className="font-mono">{"{{dateOfJoining}}"}</code>, <code className="font-mono">{"{{ctc}}"}</code>,{" "}
            <code className="font-mono">{"{{manager}}"}</code>, <code className="font-mono">{"{{companyName}}"}</code> or{" "}
            <code className="font-mono">{"{{today}}"}</code> typed in as placeholders. Generating a letter fills these in and
            produces a completed .docx. Leave this empty to auto-generate a default PDF from the Subject/Body below instead.
          </p>

          {tpl?.templateFileName && !removeFile ? (
            <div className="flex items-center justify-between gap-3 rounded-lg bg-card border border-border px-3 py-2">
              <span className="flex items-center gap-2 text-sm text-ink min-w-0">
                <FileText size={15} className="text-brand shrink-0" />
                <span className="truncate">{tpl.templateFileName}</span>
              </span>
              <button
                type="button"
                onClick={() => setRemoveFile(true)}
                className="flex items-center gap-1 text-xs text-danger hover:opacity-70 shrink-0"
                title="Remove uploaded template"
              >
                <X size={13} /> Remove
              </button>
            </div>
          ) : (
            <input type="file" name="templateFile" accept=".docx" className="input text-sm" />
          )}
          {removeFile && (
            <>
              <input type="hidden" name="removeTemplateFile" value="1" />
              <p className="text-xs text-muted mt-1.5">Uploaded template will be removed on save — the default PDF layout will be used instead.</p>
            </>
          )}
        </div>

        <div>
          <label className="field-label">Subject</label>
          <input
            name="subject"
            placeholder="Subject — supports {{employeeName}}"
            defaultValue={tpl?.subject ?? ""}
            className="input text-sm"
          />
        </div>
        <div>
          <label className="field-label">Body {tpl?.templateFileName && !removeFile ? "(used only if the Word template above is removed)" : ""}</label>
          <textarea
            ref={bodyRef}
            name="bodyHtml"
            rows={8}
            placeholder="Body — {{designation}}, {{companyName}}, {{dateOfJoining}}, {{ctc}}, ..."
            defaultValue={tpl?.bodyHtml ?? ""}
            onInput={(e) => autoGrow(e.currentTarget)}
            className="input text-sm font-mono resize-none overflow-hidden min-h-[10rem]"
          />
        </div>
        <div className="flex items-center gap-3">
          <button type="submit" disabled={pending} className="btn btn-secondary text-sm">
            {pending ? "Saving…" : "Save template"}
          </button>
          {savedAt && (
            <span className="flex items-center gap-1 text-xs text-success">
              <CheckCircle2 size={14} /> Saved
            </span>
          )}
          {state.error && <span className="text-xs text-danger">{state.error}</span>}
        </div>
      </form>
    </div>
  );
}
