import Link from "next/link";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { createJobPosting } from "@/lib/actions/recruitment";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";

export const dynamic = "force-dynamic";

export default async function NewJobPostingPage() {
  await requireRole(["COMPANY_ADMIN", "HR", "RECRUITER"]);
  await requireModuleAccess("/dashboard/recruitment");

  return (
    <div className="max-w-xl">
      <BackLink fallbackHref="/dashboard/recruitment">Back to recruitment</BackLink>
      <PageHeader title="New job posting" />

      <form action={createJobPosting} className="card p-6 space-y-4">
        <div>
          <label className="field-label">Job title</label>
          <input name="title" required className="input" />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="field-label">Department</label>
            <input name="department" required className="input" />
          </div>
          <div>
            <label className="field-label">Location</label>
            <input name="location" required className="input" />
          </div>
        </div>
        <div>
          <label className="field-label">Openings</label>
          <input name="openings" type="number" min={1} defaultValue={1} className="input max-w-[8rem]" />
        </div>
        <div>
          <label className="field-label">Description</label>
          <textarea name="description" rows={4} className="input" />
        </div>
        <div className="flex justify-end gap-2">
          <Link href="/dashboard/recruitment" className="btn btn-secondary">Cancel</Link>
          <button type="submit" className="btn btn-primary">Publish posting</button>
        </div>
      </form>
    </div>
  );
}
