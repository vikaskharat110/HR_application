import Link from "next/link";
import { requireRole } from "@/lib/session";
import { prisma } from "@/lib/prisma";
import { PageHeader, EmptyState } from "@/components/ui";
import { formatDateTime } from "@/lib/format";
import { History, ChevronLeft, ChevronRight } from "lucide-react";

export const dynamic = "force-dynamic";

const PAGE_SIZE = 50;

export default async function AuditLogPage({
  searchParams,
}: {
  searchParams: Promise<{ companyId?: string; action?: string; entityType?: string; page?: string }>;
}) {
  const session = await requireRole(["SUPER_ADMIN", "AUDITOR", "COMPANY_ADMIN"]);
  // Everyone except the platform Super Admin is scoped to their own tenant only — the
  // cross-company filter below is a Super-Admin-only tool.
  const isTenantScoped = session.role !== "SUPER_ADMIN";
  const { companyId: companyIdParam, action, entityType, page: pageParam } = await searchParams;
  const companyId = isTenantScoped ? session.companyId ?? undefined : companyIdParam;
  const page = Math.max(1, Number(pageParam) || 1);

  const where = {
    ...(companyId ? (companyId === "__platform__" ? { companyId: null } : { companyId }) : {}),
    ...(action ? { action } : {}),
    ...(entityType ? { entityType } : {}),
  };

  const [companies, actions, entityTypes, total, logs] = await Promise.all([
    isTenantScoped ? Promise.resolve([]) : prisma.company.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.auditLog.findMany({ distinct: ["action"], select: { action: true }, orderBy: { action: "asc" } }),
    prisma.auditLog.findMany({ distinct: ["entityType"], select: { entityType: true }, orderBy: { entityType: "asc" } }),
    prisma.auditLog.count({ where }),
    prisma.auditLog.findMany({
      where,
      include: { actor: true, company: true },
      orderBy: { createdAt: "desc" },
      take: PAGE_SIZE,
      skip: (page - 1) * PAGE_SIZE,
    }),
  ]);

  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  function pageHref(p: number) {
    const params = new URLSearchParams();
    if (companyId) params.set("companyId", companyId);
    if (action) params.set("action", action);
    if (entityType) params.set("entityType", entityType);
    params.set("page", String(p));
    return `/dashboard/audit?${params.toString()}`;
  }

  return (
    <div>
      <PageHeader title="Audit Logs" subtitle={isTenantScoped ? `${total} recorded action(s) for your company.` : `${total} recorded action(s) across every tenant on the platform.`} />

      <form className="card p-4 mb-5 flex flex-wrap items-end gap-3" method="get">
        {!isTenantScoped && (
          <div>
            <label className="field-label">Company</label>
            <select name="companyId" defaultValue={companyId ?? ""} className="input">
              <option value="">All companies</option>
              <option value="__platform__">Platform (no company)</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
        )}
        <div>
          <label className="field-label">Action</label>
          <select name="action" defaultValue={action ?? ""} className="input">
            <option value="">All actions</option>
            {actions.map((a) => <option key={a.action} value={a.action}>{a.action}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label">Entity</label>
          <select name="entityType" defaultValue={entityType ?? ""} className="input">
            <option value="">All entities</option>
            {entityTypes.map((e) => <option key={e.entityType} value={e.entityType}>{e.entityType}</option>)}
          </select>
        </div>
        <button type="submit" className="btn btn-secondary">Filter</button>
        {(companyId || action || entityType) && (
          <Link href="/dashboard/audit" className="text-sm text-muted hover:text-ink">Clear</Link>
        )}
      </form>

      <div className="card overflow-x-auto">
        {logs.length === 0 ? (
          <EmptyState icon={History} title="No audit entries match these filters" />
        ) : (
          <table className="table-clean">
            <thead>
              <tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Company</th></tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td className="whitespace-nowrap">{formatDateTime(log.createdAt)}</td>
                  <td className="text-ink">{log.actor?.email ?? "system"}</td>
                  <td>{log.action}</td>
                  <td>{log.entityType} <span className="text-muted text-xs font-mono">{log.entityId.slice(0, 8)}</span></td>
                  <td>
                    {log.company ? (
                      <Link href={`/dashboard/companies/${log.company.id}`} className="text-brand hover:underline">{log.company.name}</Link>
                    ) : (
                      <span className="text-muted">—</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {totalPages > 1 && (
        <div className="flex items-center justify-between mt-4 text-sm">
          <Link
            href={pageHref(page - 1)}
            aria-disabled={page <= 1}
            className={`btn btn-secondary ${page <= 1 ? "pointer-events-none opacity-40" : ""}`}
          >
            <ChevronLeft size={14} /> Previous
          </Link>
          <span className="text-muted">Page {page} of {totalPages}</span>
          <Link
            href={pageHref(page + 1)}
            aria-disabled={page >= totalPages}
            className={`btn btn-secondary ${page >= totalPages ? "pointer-events-none opacity-40" : ""}`}
          >
            Next <ChevronRight size={14} />
          </Link>
        </div>
      )}
    </div>
  );
}
