import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { PageHeader, StatusBadge, EmptyState } from "@/components/ui";
import { createAsset, assignAsset, returnAsset } from "@/lib/actions/assets";
import { Boxes, Undo2 } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function AssetsPage() {
  const session = await requireModuleAccess("/dashboard/assets");
  const canManage = ["COMPANY_ADMIN", "HR", "IT_ADMIN"].includes(session.role);

  const [assets, employees] = await Promise.all([
    prisma.asset.findMany({
      where: { companyId: session.companyId ?? undefined },
      include: { assignments: { where: { returnedDate: null }, include: { employee: true } } },
      orderBy: { name: "asc" },
    }),
    canManage
      ? prisma.employee.findMany({ where: { companyId: session.companyId ?? undefined, status: "ACTIVE" }, orderBy: { firstName: "asc" } })
      : Promise.resolve([]),
  ]);

  async function returnIt(formData: FormData) {
    "use server";
    await returnAsset(String(formData.get("assignmentId")), String(formData.get("assetId")));
  }

  return (
    <div>
      <PageHeader title="Assets" subtitle="Company inventory and who's holding what." />

      <div className={canManage ? "grid lg:grid-cols-3 gap-5" : ""}>
        {canManage && (
          <div className="card p-6 space-y-6">
            <div>
              <p className="text-sm font-semibold text-ink mb-3">Add asset</p>
              <form action={createAsset} className="space-y-3">
                <input name="name" required placeholder="Asset name" className="input" />
                <input name="category" required placeholder="Category (Laptop, Phone...)" className="input" />
                <input name="serialNumber" required placeholder="Serial number" className="input" />
                <button type="submit" className="btn btn-primary w-full justify-center">Add to inventory</button>
              </form>
            </div>
          </div>
        )}

        <div className={canManage ? "lg:col-span-2 card overflow-x-auto" : "card overflow-x-auto"}>
          {assets.length === 0 ? (
            <EmptyState icon={Boxes} title="No assets yet" />
          ) : (
            <table className="table-clean">
              <thead><tr><th>Asset</th><th>Category</th><th>Serial</th><th>Status</th><th>Assigned to</th>{canManage && <th></th>}</tr></thead>
              <tbody>
                {assets.map((a) => {
                  const active = a.assignments[0];
                  return (
                    <tr key={a.id}>
                      <td className="font-medium text-ink">{a.name}</td>
                      <td>{a.category}</td>
                      <td className="font-mono text-xs">{a.serialNumber}</td>
                      <td><StatusBadge status={a.status} /></td>
                      <td>{active ? `${active.employee.firstName} ${active.employee.lastName}` : "—"}</td>
                      {canManage && (
                        <td>
                          {active ? (
                            <form action={returnIt}>
                              <input type="hidden" name="assignmentId" value={active.id} />
                              <input type="hidden" name="assetId" value={a.id} />
                              <button className="btn btn-secondary !py-1 !px-2 text-xs"><Undo2 size={12} /> Return</button>
                            </form>
                          ) : (
                            <form action={assignAsset} className="flex gap-1.5">
                              <input type="hidden" name="assetId" value={a.id} />
                              <select name="employeeId" required className="input !py-1 text-xs !w-auto">
                                <option value="">Assign to…</option>
                                {employees.map((e) => <option key={e.id} value={e.id}>{e.firstName} {e.lastName}</option>)}
                              </select>
                              <button className="btn btn-secondary !py-1 !px-2 text-xs">Go</button>
                            </form>
                          )}
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
          {assets.length > 0 && (
            <p className="px-5 py-3 text-xs text-muted border-t border-border-soft">
              Purchased dates omitted from this view — see individual employee profiles for assignment history.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

