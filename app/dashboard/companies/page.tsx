import Link from "next/link";
import { requireRole } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { PageHeader, EmptyState, StatusBadge, Pagination } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { Building2, Plus } from "lucide-react";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 12;

export default async function CompaniesPage({
  searchParams,
}: {
  searchParams: Promise<{ page?: string }>;
}) {
  await requireRole(["SUPER_ADMIN"]);
  const { page: pageParam } = await searchParams;
  const page = Math.max(1, Number(pageParam) || 1);

  const [total, companies] = await Promise.all([
    prisma.company.count(),
    prisma.company.findMany({
      include: {
        _count: { select: { employees: true, users: true, branches: true } },
      },
      orderBy: { createdAt: "desc" },
      take: PAGE_SIZE,
      skip: (page - 1) * PAGE_SIZE,
    }),
  ]);
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <div>
      <PageHeader
        title="Companies"
        subtitle="Every tenant provisioned on Verdant HR."
        action={<Link href="/dashboard/companies/new" className="btn btn-primary"><Plus size={15} /> New company</Link>}
      />

      <div className="card overflow-x-auto">
        {companies.length === 0 ? (
          <EmptyState icon={Building2} title="No companies yet" />
        ) : (
          <table className="table-clean">
            <thead>
              <tr><th>Company</th><th>Industry</th><th>Employees</th><th>Logins</th><th>Branches</th><th>Status</th><th>Created</th></tr>
            </thead>
            <tbody>
              {companies.map((c) => (
                <tr key={c.id}>
                  <td>
                    <Link href={`/dashboard/companies/${c.id}`} className="block">
                      <span className="font-display font-semibold text-ink hover:underline">{c.name}</span>
                      <span className="block text-xs text-muted mt-0.5">{c.slug}</span>
                    </Link>
                  </td>
                  <td className="whitespace-nowrap">{c.industry ?? "—"}</td>
                  <td className="whitespace-nowrap">{c._count.employees}</td>
                  <td className="whitespace-nowrap">{c._count.users}</td>
                  <td className="whitespace-nowrap">{c._count.branches}</td>
                  <td><StatusBadge status={c.status} /></td>
                  <td className="whitespace-nowrap">{formatDate(c.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <Pagination page={page} totalPages={totalPages} basePath="/dashboard/companies" />
    </div>
  );
}
