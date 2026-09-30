"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, resolveApprover } from "@/lib/workflow";
import { distanceMeters } from "@/lib/geo";

function startOfToday() {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
}

export type CheckAttendanceResult = { error?: string } | undefined;

/**
 * Two independent switches, both must be "on" for a check-in/out to actually
 * be checked: the company-wide `geofenceAttendanceEnabled` toggle (Settings →
 * Policy — an admin can turn the whole feature off in one place), and the
 * employee's own branch having coordinates set (Settings → Branches). Either
 * one being off means this returns null — never trusting a client-reported
 * "I'm in range" claim, only the raw lat/lng the browser's geolocation API
 * produced, checked here server-side.
 */
async function enforceGeofence(employeeId: string, latitude?: number, longitude?: number): Promise<string | null> {
  const employee = await prisma.employee.findUnique({ where: { id: employeeId }, include: { branch: true } });
  if (!employee) return null;

  const policy = await prisma.companyPolicy.findUnique({ where: { companyId: employee.companyId } });
  if (!policy?.geofenceAttendanceEnabled) return null;

  const branch = employee.branch;
  if (!branch || branch.latitude == null || branch.longitude == null) return null;

  if (latitude == null || longitude == null) {
    return "Location access is required to check in/out at this branch — please allow it and try again.";
  }

  const distance = distanceMeters(latitude, longitude, branch.latitude, branch.longitude);
  if (distance > branch.geofenceRadiusMeters) {
    return `You're ${Math.round(distance)}m from ${branch.name} — you must be within ${branch.geofenceRadiusMeters}m to do this.`;
  }
  return null;
}

/** Whether geofencing is switched on for this company at all — used by the attendance page to decide whether the check-in card should bother asking for location. */
export async function isGeofenceEnabled(companyId: string): Promise<boolean> {
  const policy = await prisma.companyPolicy.findUnique({ where: { companyId } });
  return !!policy?.geofenceAttendanceEnabled;
}

export async function checkIn(latitude?: number, longitude?: number): Promise<CheckAttendanceResult> {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const geofenceError = await enforceGeofence(session.employeeId, latitude, longitude);
  if (geofenceError) return { error: geofenceError };

  const shift = await prisma.shift.findFirst({ where: { companyId: session.companyId ?? undefined } });
  const now = new Date();
  const graceMinutes = shift?.graceMinutes ?? 15;
  const [h, m] = (shift?.startTime ?? "09:30").split(":").map(Number);
  const cutoff = new Date(now);
  cutoff.setHours(h, m + graceMinutes, 0, 0);
  const isLate = now > cutoff;

  await prisma.attendance.upsert({
    where: { employeeId_date: { employeeId: session.employeeId, date: startOfToday() } },
    create: {
      employeeId: session.employeeId,
      date: startOfToday(),
      checkIn: now,
      checkInLat: latitude ?? null,
      checkInLng: longitude ?? null,
      status: isLate ? "LATE" : "PRESENT",
      shiftId: shift?.id,
    },
    update: { checkIn: now, checkInLat: latitude ?? null, checkInLng: longitude ?? null, status: isLate ? "LATE" : "PRESENT" },
  });

  revalidatePath("/dashboard/attendance");
  revalidatePath("/dashboard");
}

export async function checkOut(latitude?: number, longitude?: number): Promise<CheckAttendanceResult> {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const geofenceError = await enforceGeofence(session.employeeId, latitude, longitude);
  if (geofenceError) return { error: geofenceError };

  const record = await prisma.attendance.findUnique({
    where: { employeeId_date: { employeeId: session.employeeId, date: startOfToday() } },
  });
  if (!record?.checkIn) throw new Error("Check in first");

  const now = new Date();
  const workedMins = Math.round((now.getTime() - record.checkIn.getTime()) / 60000);

  await prisma.attendance.update({
    where: { id: record.id },
    data: { checkOut: now, checkOutLat: latitude ?? null, checkOutLng: longitude ?? null, workedMins },
  });

  revalidatePath("/dashboard/attendance");
  revalidatePath("/dashboard");
}

export async function requestRegularization(formData: FormData) {
  const session = await requireSession();
  if (!session.employeeId) throw new Error("No employee record linked to this account");

  const date = new Date(String(formData.get("date")));
  const inTime = String(formData.get("checkIn") || "");
  const outTime = String(formData.get("checkOut") || "");
  const reason = String(formData.get("reason") || "").trim();
  if (!reason) throw new Error("Reason is required");

  const mk = (t: string) => {
    if (!t) return null;
    const [h, m] = t.split(":").map(Number);
    const d = new Date(date);
    d.setHours(h, m, 0, 0);
    return d;
  };

  const existing = await prisma.attendance.findUnique({
    where: { employeeId_date: { employeeId: session.employeeId, date } },
  });

  const approver = await resolveApprover(session.employeeId);

  const reg = await prisma.attendanceRegularization.create({
    data: {
      employeeId: session.employeeId,
      attendanceId: existing?.id,
      date,
      requestedCheckIn: mk(inTime),
      requestedCheckOut: mk(outTime),
      reason,
      approverId: approver?.id,
    },
  });

  if (approver?.userId) {
    await notify({
      userId: approver.userId,
      title: "Attendance regularization request",
      message: `${session.name} requested a correction for ${date.toDateString()}.`,
      link: "/dashboard/attendance/regularizations",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "AttendanceRegularization",
    entityId: reg.id,
  });

  revalidatePath("/dashboard/attendance/regularizations");
}

export async function decideRegularization(id: string, approve: boolean) {
  const session = await requireSession();

  const existing = await prisma.attendanceRegularization.findUnique({ where: { id }, select: { employeeId: true, status: true, approverId: true } });
  if (!existing) throw new Error("Regularization request not found.");
  await assertCanActOn(session, existing.employeeId, existing.approverId);
  if (existing.status !== "PENDING") throw new Error("This request has already been actioned.");

  const reg = await prisma.attendanceRegularization.update({
    where: { id },
    data: { status: approve ? "APPROVED" : "REJECTED" },
    include: { employee: { include: { user: true } } },
  });

  if (approve) {
    await prisma.attendance.upsert({
      where: { employeeId_date: { employeeId: reg.employeeId, date: reg.date } },
      create: {
        employeeId: reg.employeeId,
        date: reg.date,
        checkIn: reg.requestedCheckIn,
        checkOut: reg.requestedCheckOut,
        status: "PRESENT",
      },
      update: {
        checkIn: reg.requestedCheckIn ?? undefined,
        checkOut: reg.requestedCheckOut ?? undefined,
        status: "PRESENT",
      },
    });
  }

  if (reg.employee.user) {
    await notify({
      userId: reg.employee.user.id,
      title: `Regularization ${approve ? "approved" : "rejected"}`,
      message: `Your attendance correction for ${reg.date.toDateString()} was ${approve ? "approved" : "rejected"}.`,
      link: "/dashboard/attendance",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: approve ? "APPROVE" : "REJECT",
    entityType: "AttendanceRegularization",
    entityId: id,
  });

  revalidatePath("/dashboard/attendance/regularizations");
}

