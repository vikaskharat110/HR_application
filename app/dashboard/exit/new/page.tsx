import Link from "next/link";
import { redirect } from "next/navigation";
import { requireSession } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { PageHeader } from "@/components/ui";
import { BackLink } from "@/components/BackLink";
import { initiateResignation } from "@/lib/actions/exit";

export const dynamic = "force-dynamic";

/**
 * Deliberately not gated by requireModuleAccess("/dashboard/exit") — that
 * module's nav visibility is for the HR/Manager/IT/Finance clearance queue,
 * but any employee must be able to submit their own resignation regardless
 * of whether they can see that queue. Authorization is enforced inside
 * initiateResignation itself (self, or HR on someone's behalf).
 */
export default async function NewExitPage({ searchParams }: { searchParams: Promise<{ employeeId?: string }> }) {
  const session = await requireSession();
  const { employeeId: employeeIdParam } = await searchParams;

  const isHR = session.role === "COMPANY_ADMIN" || session.role === "HR";
  const employeeId = employeeIdParam && isHR ? employeeIdParam : session.employeeId;
  if (!employeeId) redirect("/dashboard");

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId ?? undefined } });
  if (!employee) redirect("/dashboard/exit");

  async function submit(formData: FormData) {
    "use server";
    const exitId = await initiateResignation(formData);
    redirect(`/dashboard/exit/${exitId}`);
  }

  return (
    <div className="max-w-xl">
      <BackLink fallbackHref="/dashboard/exit">Back to exit / offboarding</BackLink>
      <PageHeader title="Submit resignation" subtitle={`For ${employee.firstName} ${employee.lastName}`} />

      <form action={submit} className="card p-6 space-y-4">
        <input type="hidden" name="employeeId" value={employee.id} />
        <div>
          <label className="field-label">Resignation date</label>
          <input type="date" name="resignationDate" required defaultValue={new Date().toISOString().slice(0, 10)} className="input" />
        </div>
        <div>
          <label className="field-label">Reason</label>
          <textarea name="reason" rows={4} required className="input" placeholder="Reason for resignation" />
        </div>
        <div className="flex justify-end gap-2">
          <Link href="/dashboard/exit" className="btn btn-secondary">Cancel</Link>
          <button type="submit" className="btn btn-primary">Submit resignation</button>
        </div>
      </form>
    </div>
  );
}
