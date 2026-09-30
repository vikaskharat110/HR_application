import "server-only";
import { prisma } from "./prisma";

/**
 * Unauthorized-absence days per employee within [start, end] (inclusive): a
 * weekday with no check-in, that isn't a company holiday and isn't covered by
 * an approved leave request. No Attendance row is ever written with an
 * "ABSENT" status (check-in only ever produces PRESENT/LATE, regularization
 * approval only ever produces PRESENT) — so this has to be computed on read,
 * the same way probation due-dates and promotion eligibility are.
 */
export async function unauthorizedAbsenceDays(
  companyId: string,
  employeeIds: string[],
  start: Date,
  end: Date
): Promise<Map<string, number>> {
  const result = new Map(employeeIds.map((id) => [id, 0]));
  if (employeeIds.length === 0) return result;

  const [holidays, attendanceRows, approvedLeaves, employees] = await Promise.all([
    prisma.holiday.findMany({ where: { companyId, date: { gte: start, lte: end } }, select: { date: true } }),
    prisma.attendance.findMany({
      where: { employeeId: { in: employeeIds }, date: { gte: start, lte: end }, checkIn: { not: null } },
      select: { employeeId: true, date: true },
    }),
    prisma.leaveRequest.findMany({
      where: { employeeId: { in: employeeIds }, status: "APPROVED", startDate: { lte: end }, endDate: { gte: start } },
      select: { employeeId: true, startDate: true, endDate: true },
    }),
    prisma.employee.findMany({ where: { id: { in: employeeIds } }, select: { id: true, dateOfJoining: true } }),
  ]);

  const holidayDates = new Set(holidays.map((h) => new Date(h.date).toDateString()));

  const presentByEmployee = new Map<string, Set<string>>();
  for (const a of attendanceRows) {
    const set = presentByEmployee.get(a.employeeId) ?? new Set<string>();
    set.add(new Date(a.date).toDateString());
    presentByEmployee.set(a.employeeId, set);
  }

  const leavesByEmployee = new Map<string, { startDate: Date; endDate: Date }[]>();
  for (const l of approvedLeaves) {
    const arr = leavesByEmployee.get(l.employeeId) ?? [];
    arr.push(l);
    leavesByEmployee.set(l.employeeId, arr);
  }

  const joinDateByEmployee = new Map(employees.map((e) => [e.id, e.dateOfJoining]));

  for (const employeeId of employeeIds) {
    const present = presentByEmployee.get(employeeId) ?? new Set<string>();
    const leaves = leavesByEmployee.get(employeeId) ?? [];
    const joinedOn = joinDateByEmployee.get(employeeId);
    const joinedAtMidnight = joinedOn ? new Date(joinedOn.getFullYear(), joinedOn.getMonth(), joinedOn.getDate()) : null;

    let count = 0;
    const cursor = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    while (cursor <= last) {
      const dow = cursor.getDay();
      const key = cursor.toDateString();
      const beforeJoining = joinedAtMidnight ? cursor < joinedAtMidnight : false;
      if (
        dow !== 0 && dow !== 6 &&
        !beforeJoining &&
        !holidayDates.has(key) &&
        !present.has(key) &&
        !leaves.some((l) => cursor >= l.startDate && cursor <= l.endDate)
      ) {
        count++;
      }
      cursor.setDate(cursor.getDate() + 1);
    }
    result.set(employeeId, count);
  }

  return result;
}
