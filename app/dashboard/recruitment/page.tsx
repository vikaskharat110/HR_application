import Link from "next/link";
import { requireRole } from "@/lib/session";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, StatusBadge, EmptyState } from "@/components/ui";
import { Briefcase, Plus, MapPin, Users } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function RecruitmentPage() {
  const session = await requireRole(["COMPANY_ADMIN", "HR", "RECRUITER"]);
  await requireModuleAccess("/dashboard/recruitment");

  const jobs = await prisma.jobPosting.findMany({
    where: { companyId: session.companyId ?? undefined },
    include: { _count: { select: { candidates: true } } },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div>
      <PageHeader
        title="Recruitment"
        subtitle="Open roles and candidate pipelines."
        action={<Link href="/dashboard/recruitment/new" className="btn btn-primary"><Plus size={15} /> New job posting</Link>}
      />

      {jobs.length === 0 ? (
        <div className="card"><EmptyState icon={Briefcase} title="No job postings yet" message="Create your first opening to start tracking candidates." /></div>
      ) : (
        <div className="grid gap-4 grid-cols-[repeat(auto-fit,minmax(280px,1fr))]">
          {jobs.map((job) => (
            <Link key={job.id} href={`/dashboard/recruitment/${job.id}`} className="card p-5 hover:border-brand-soft transition-colors">
              <div className="flex items-start justify-between gap-2 mb-2">
                <p className="font-display font-semibold text-ink">{job.title}</p>
                <StatusBadge status={job.status} />
              </div>
              <p className="text-sm text-muted flex items-center gap-1.5 mb-1"><MapPin size={13} /> {job.location}</p>
              <p className="text-sm text-muted flex items-center gap-1.5"><Users size={13} /> {job._count.candidates} candidate(s) · {job.openings} opening(s)</p>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
