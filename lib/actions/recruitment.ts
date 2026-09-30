"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/lib/session";
import { recordAudit } from "@/lib/workflow";
import type { CandidateStage, Role } from "@prisma/client";

const RECRUITMENT_ROLES: Role[] = ["COMPANY_ADMIN", "HR", "RECRUITER"];

export async function createJobPosting(formData: FormData) {
  const session = await requireRole(RECRUITMENT_ROLES);
  if (!session.companyId) throw new Error("No company context");

  const job = await prisma.jobPosting.create({
    data: {
      companyId: session.companyId,
      title: String(formData.get("title")),
      department: String(formData.get("department")),
      location: String(formData.get("location")),
      openings: Number(formData.get("openings") || 1),
      description: String(formData.get("description") || ""),
      postedById: session.employeeId,
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "JobPosting", entityId: job.id });
  revalidatePath("/dashboard/recruitment");
  redirect(`/dashboard/recruitment/${job.id}`);
}

async function assertOwnsJob(companyId: string | null, jobId: string) {
  const job = await prisma.jobPosting.findFirst({ where: { id: jobId, companyId: companyId ?? undefined } });
  if (!job) throw new Error("Job posting not found.");
  return job;
}

export async function toggleJobStatus(jobId: string, status: "OPEN" | "ON_HOLD" | "CLOSED") {
  const session = await requireRole(RECRUITMENT_ROLES);
  await assertOwnsJob(session.companyId, jobId);
  await prisma.jobPosting.update({ where: { id: jobId }, data: { status } });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "STATUS_CHANGE", entityType: "JobPosting", entityId: jobId, meta: { status } });
  revalidatePath(`/dashboard/recruitment/${jobId}`);
}

export async function addCandidate(formData: FormData) {
  const session = await requireRole(RECRUITMENT_ROLES);
  const jobPostingId = String(formData.get("jobPostingId"));
  await assertOwnsJob(session.companyId, jobPostingId);

  const candidate = await prisma.candidate.create({
    data: {
      jobPostingId,
      name: String(formData.get("name")),
      email: String(formData.get("email")),
      phone: String(formData.get("phone") || ""),
      notes: String(formData.get("notes") || ""),
    },
  });

  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "CREATE", entityType: "Candidate", entityId: candidate.id });
  revalidatePath(`/dashboard/recruitment/${jobPostingId}`);
}

export async function updateCandidateStage(candidateId: string, jobPostingId: string, stage: CandidateStage) {
  const session = await requireRole(RECRUITMENT_ROLES);
  await assertOwnsJob(session.companyId, jobPostingId);

  const candidate = await prisma.candidate.findFirst({ where: { id: candidateId, jobPostingId } });
  if (!candidate) throw new Error("Candidate not found.");

  await prisma.candidate.update({ where: { id: candidateId }, data: { stage } });
  await recordAudit({ companyId: session.companyId, actorId: session.sub, action: "STAGE_CHANGE", entityType: "Candidate", entityId: candidateId, meta: { stage } });
  revalidatePath(`/dashboard/recruitment/${jobPostingId}`);
}

