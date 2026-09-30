import Link from "next/link";
import { requireModuleAccess } from "@/lib/permissions";
import { prisma } from "@/lib/prisma";
import { visibleEmployeeIds, isManagerial } from "@/lib/scope";
import { PageHeader, StatusBadge, Avatar, EmptyState } from "@/components/ui";
import { CheckInOutCard } from "@/components/CheckInOutCard";
import { formatDate, formatTime } from "@/lib/format";
import { ClipboardList, Fingerprint } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function AttendancePage() {
  const session = await requireModuleAccess("/dashboard/attendance");
  const managerial = isManagerial(session);
  const empIds = await visibleEmployeeIds(session);

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const [myToday, myHistory, teamToday, pendingRegCount, policy, myBranch] = await Promise.all([
    session.employeeId
      ? prisma.attendance.findUnique({ where: { employeeId_date: { employeeId: session.employeeId, date: today } } })
      : Promise.resolve(null),
    session.employeeId
      ? prisma.attendance.findMany({ where: { employeeId: session.employeeId }, orderBy: { date: "desc" }, take: 10 })
      : Promise.resolve([]),
    managerial
      ? prisma.attendance.findMany({
          where: { employeeId: { in: empIds }, date: today },
          include: { employee: true },
          orderBy: { employee: { firstName: "asc" } },
        })
      : Promise.resolve([]),
    managerial
      ? prisma.attendanceRegularization.count({ where: { employeeId: { in: empIds }, status: "PENDING" } })
      : Promise.resolve(0),
    prisma.companyPolicy.findUnique({ where: { companyId: session.companyId ?? "" } }),
    session.employeeId
      ? prisma.employee.findUnique({ where: { id: session.employeeId }, select: { branch: true } })
      : Promise.resolve(null),
  ]);
  const geofenceEnabled = policy?.geofenceAttendanceEnabled ?? false;
  const branch = myBranch?.branch ?? null;

  return (
    <div>
      <PageHeader
        title="Attendance"
        subtitle="Check in, review history, and manage regularizations."
        action={
          <Link href="/dashboard/attendance/regularizations" className="btn btn-secondary">
            <ClipboardList size={15} />
            Regularizations {pendingRegCount > 0 && `(${pendingRegCount})`}
          </Link>
        }
      />

      <div className="grid lg:grid-cols-3 gap-5">
        <div className="space-y-5">
          {session.employeeId && (
            <CheckInOutCard
              checkInAt={myToday?.checkIn ?? null}
              checkOutAt={myToday?.checkOut ?? null}
              checkInLat={myToday?.checkInLat ?? null}
              checkInLng={myToday?.checkInLng ?? null}
              checkOutLat={myToday?.checkOutLat ?? null}
              checkOutLng={myToday?.checkOutLng ?? null}
              branch={branch ? { name: branch.name, latitude: branch.latitude, longitude: branch.longitude } : null}
              geofenceEnabled={geofenceEnabled}
            />
          )}

          <div className="card p-5">
            <p className="text-sm font-semibold text-ink mb-3">My recent attendance</p>
            <ul className="space-y-2.5">
              {myHistory.map((a) => (
                <li key={a.id} className="flex items-center justify-between text-sm">
                  <span className="text-ink-soft">{formatDate(a.date)}</span>
                  <span className="text-muted">{a.checkIn ? formatTime(a.checkIn) : "—"} – {a.checkOut ? formatTime(a.checkOut) : "—"}</span>
                  <StatusBadge status={a.status} />
                </li>
              ))}
              {myHistory.length === 0 && <p className="text-sm text-muted py-4">No records yet.</p>}
            </ul>
          </div>
        </div>

        {managerial && (
          <div className="lg:col-span-2 card p-5">
            <p className="text-sm font-semibold text-ink mb-3">Team — today, {formatDate(today)}</p>
            {teamToday.length === 0 ? (
              <EmptyState icon={Fingerprint} title="No attendance marked yet" message="Check back once your team checks in." />
            ) : (
              <table className="table-clean">
                <thead><tr><th>Employee</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
                <tbody>
                  {teamToday.map((a) => (
                    <tr key={a.id}>
                      <td className="flex items-center gap-2.5 py-2.5">
                        <Avatar firstName={a.employee.firstName} lastName={a.employee.lastName} color={a.employee.avatarColor} />
                        {a.employee.firstName} {a.employee.lastName}
                      </td>
                      <td>{a.checkIn ? formatTime(a.checkIn) : "—"}</td>
                      <td>{a.checkOut ? formatTime(a.checkOut) : "—"}</td>
                      <td><StatusBadge status={a.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
