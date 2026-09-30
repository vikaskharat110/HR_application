import Link from "next/link";
import { notFound } from "next/navigation";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, StatusBadge } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { addCandidate, updateCandidateStage, toggleJobStatus } from "@/lib/actions/recruitment";
import { formatDate } from "@/lib/format";
import { Mail, Phone, UserPlus } from "lucide-react";
import { AutoSubmitSelect } from "@/components/AutoSubmitSelect";
import type { CandidateStage } from "@prisma/client";

export const dynamic = "force-dynamic";

const STAGES: CandidateStage[] = ["APPLIED", "SCREENING", "INTERVIEW", "OFFERED", "HIRED", "REJECTED"];

export default async function JobDetailPage({ params }: { params: Promise<{ jobId: string }> }) {
  const { jobId } = await params;
  const session = await requireRole(["COMPANY_ADMIN", "HR", "RECRUITER"]);
  await requireModuleAccess("/dashboard/recruitment");
  // BRD Section 10: Onboarding is "Support" for Recruiter but "Full" for HR/Admin —
  // creating the actual Employee Master record stays with HR/Company Admin.
  const canOnboard = session.role === "COMPANY_ADMIN" || session.role === "HR";

  const job = await prisma.jobPosting.findFirst({
    where: { id: jobId, companyId: session.companyId ?? undefined },
    include: { candidates: { orderBy: { appliedAt: "desc" }, include: { employee: true } } },
  });
  if (!job) notFound();

  async function setStage(formData: FormData) {
    "use server";
    await updateCandidateStage(String(formData.get("candidateId")), jobId, formData.get("stage") as CandidateStage);
  }

  async function setStatus(formData: FormData) {
    "use server";
    await toggleJobStatus(jobId, formData.get("status") as "OPEN" | "ON_HOLD" | "CLOSED");
  }

  return (
    <div>
      <BackLink fallbackHref="/dashboard/recruitment">Back to recruitment</BackLink>

      <PageHeader
        title={job.title}
        subtitle={`${job.department} · ${job.location} · ${job.openings} opening(s)`}
        action={
          <form action={setStatus} className="flex items-center gap-2">
            <select name="status" defaultValue={job.status} className="input !w-auto text-sm">
              <option value="OPEN">Open</option>
              <option value="ON_HOLD">On hold</option>
              <option value="CLOSED">Closed</option>
            </select>
            <button className="btn btn-secondary text-sm">Update</button>
          </form>
        }
      />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="card p-6">
          <p className="text-sm font-semibold text-ink mb-4">Add candidate</p>
          <form action={addCandidate} className="space-y-3">
            <input type="hidden" name="jobPostingId" value={job.id} />
            <div>
              <label className="field-label">Full name</label>
              <input name="name" required className="input" />
            </div>
            <div>
              <label className="field-label">Email</label>
              <input name="email" type="email" required className="input" />
            </div>
            <div>
              <label className="field-label">Phone</label>
              <input name="phone" className="input" />
            </div>
            <div>
              <label className="field-label">Notes</label>
              <textarea name="notes" rows={2} className="input" />
            </div>
            <button type="submit" className="btn btn-primary w-full justify-center">Add to pipeline</button>
          </form>

          {job.description && (
            <div className="mt-5 pt-5 border-t border-border-soft">
              <p className="text-sm font-semibold text-ink mb-1.5">Description</p>
              <p className="text-sm text-ink-soft whitespace-pre-line">{job.description}</p>
            </div>
          )}
        </div>

        <div className="lg:col-span-2 card p-5">
          <p className="text-sm font-semibold text-ink mb-4">Candidates ({job.candidates.length})</p>
          {job.candidates.length === 0 ? (
            <p className="text-sm text-muted py-6 text-center">No candidates yet.</p>
          ) : (
            <ul className="space-y-3">
              {job.candidates.map((c) => (
                <li key={c.id} className="rounded-lg border border-border p-4 flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium text-ink">{c.name}</p>
                    <div className="flex items-center gap-3 text-xs text-muted mt-1">
                      <span className="flex items-center gap-1"><Mail size={12} /> {c.email}</span>
                      {c.phone && <span className="flex items-center gap-1"><Phone size={12} /> {c.phone}</span>}
                      <span>Applied {formatDate(c.appliedAt)}</span>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {c.stage === "HIRED" && (
                      c.employee ? (
                        <Link href={`/dashboard/employees/${c.employee.id}`} className="text-xs font-medium text-brand hover:underline">
                          View employee
                        </Link>
                      ) : canOnboard ? (
                        <Link href={`/dashboard/employees/new?candidateId=${c.id}`} className="btn btn-secondary !py-1.5 !px-2.5 text-xs">
                          <UserPlus size={13} /> Onboard as Employee
                        </Link>
                      ) : (
                        <span className="text-xs text-muted">Awaiting HR to onboard</span>
                      )
                    )}
                    <StatusBadge status={c.stage} />
                    <form action={setStage}>
                      <input type="hidden" name="candidateId" value={c.id} />
                      <AutoSubmitSelect name="stage" defaultValue={c.stage} options={STAGES} />
                    </form>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
