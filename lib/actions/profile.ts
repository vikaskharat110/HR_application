"use server";

import { prisma } from "@/lib/prisma";
import { requireSession } from "@/lib/session";
import { verifyPassword, hashPassword } from "@/lib/auth";
import { recordAudit } from "@/lib/workflow";

export type ChangePasswordState = { error?: string; success?: boolean };

export async function changeOwnPassword(_prev: ChangePasswordState, formData: FormData): Promise<ChangePasswordState> {
  const session = await requireSession();

  const currentPassword = String(formData.get("currentPassword") || "");
  const newPassword = String(formData.get("newPassword") || "");
  const confirmPassword = String(formData.get("confirmPassword") || "");

  if (!currentPassword || !newPassword) return { error: "Fill in both fields." };
  if (newPassword.length < 8) return { error: "New password must be at least 8 characters." };
  if (newPassword !== confirmPassword) return { error: "New passwords don't match." };

  const user = await prisma.user.findUnique({ where: { id: session.sub } });
  if (!user) throw new Error("User not found.");

  const valid = await verifyPassword(currentPassword, user.passwordHash);
  if (!valid) return { error: "Current password is incorrect." };

  const passwordHash = await hashPassword(newPassword);
  await prisma.user.update({ where: { id: user.id }, data: { passwordHash } });

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CHANGE_PASSWORD",
    entityType: "User",
    entityId: user.id,
  });

  return { success: true };
}
