"use server";

import { randomBytes } from "node:crypto";
import { cookies, headers } from "next/headers";
import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { verifyPassword, hashPassword, signSession, SESSION_COOKIE } from "@/lib/auth";
import { recordAudit } from "@/lib/workflow";
import { sendMail } from "@/lib/mail";

export type LoginState = { error?: string };

export async function login(_prev: LoginState, formData: FormData): Promise<LoginState> {
  const email = String(formData.get("email") || "").trim().toLowerCase();
  const password = String(formData.get("password") || "");
  const next = String(formData.get("next") || "/dashboard");

  if (!email || !password) {
    return { error: "Enter both email and password." };
  }

  const user = await prisma.user.findUnique({
    where: { email },
    include: { employee: true, company: true, customRole: true },
  });

  if (!user || !user.isActive) {
    return { error: "No active account matches those credentials." };
  }

  const valid = await verifyPassword(password, user.passwordHash);
  if (!valid) {
    return { error: "Incorrect email or password." };
  }

  // Checked only after the password is confirmed correct, so a wrong
  // password never leaks a tenant's suspension status to someone probing
  // without valid credentials.
  if (user.company && user.company.status !== "ACTIVE") {
    return {
      error:
        user.company.status === "SUSPENDED"
          ? "Your company's access has been suspended. Contact your platform administrator."
          : "Your company's workspace has been archived and is no longer accessible.",
    };
  }

  const token = await signSession({
    sub: user.id,
    role: user.role,
    companyId: user.companyId,
    employeeId: user.employee?.id ?? null,
    name: user.employee ? `${user.employee.firstName} ${user.employee.lastName}` : user.email,
    email: user.email,
    customRoleId: user.customRole?.id ?? null,
    customRoleName: user.customRole?.name ?? null,
  });

  const jar = await cookies();
  jar.set(SESSION_COOKIE, token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: 60 * 60 * 24 * 7,
  });

  await prisma.user.update({ where: { id: user.id }, data: { lastLoginAt: new Date() } });
  await recordAudit({
    companyId: user.companyId,
    actorId: user.id,
    action: "LOGIN",
    entityType: "User",
    entityId: user.id,
  });

  redirect(next && next.startsWith("/") ? next : "/dashboard");
}

export async function logout() {
  const jar = await cookies();
  jar.delete(SESSION_COOKIE);
  redirect("/login");
}

async function appOrigin() {
  const h = await headers();
  const host = h.get("host") ?? "localhost:3000";
  const proto = h.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https");
  return `${proto}://${host}`;
}

const RESET_TOKEN_TTL_MS = 30 * 60 * 1000; // 30 minutes

export type ForgotPasswordState = { sent?: boolean; error?: string };

export async function requestPasswordReset(_prev: ForgotPasswordState, formData: FormData): Promise<ForgotPasswordState> {
  const email = String(formData.get("email") || "").trim().toLowerCase();
  if (!email) return { error: "Enter your email address." };

  // Always report success either way — confirming or denying that an email is
  // registered would let someone enumerate every login on the platform.
  const user = await prisma.user.findUnique({ where: { email } });
  if (user && user.isActive) {
    const token = randomBytes(32).toString("hex");
    await prisma.passwordResetToken.create({
      data: { userId: user.id, token, expiresAt: new Date(Date.now() + RESET_TOKEN_TTL_MS) },
    });

    const resetUrl = `${await appOrigin()}/reset-password?token=${token}`;
    await sendMail({
      to: user.email,
      subject: "Reset your Verdant HR password",
      html: `
        <p>We received a request to reset your Verdant HR password.</p>
        <p><a href="${resetUrl}">Reset your password</a> — this link expires in 30 minutes.</p>
        <p>If you didn't request this, you can safely ignore this email.</p>
      `,
    });
  }

  return { sent: true };
}

export type ResetPasswordState = { error?: string; success?: boolean };

export async function resetPassword(_prev: ResetPasswordState, formData: FormData): Promise<ResetPasswordState> {
  const token = String(formData.get("token") || "");
  const password = String(formData.get("password") || "");
  const confirmPassword = String(formData.get("confirmPassword") || "");

  if (!token) return { error: "This reset link is missing its token." };
  if (password.length < 8) return { error: "Password must be at least 8 characters." };
  if (password !== confirmPassword) return { error: "Passwords don't match." };

  const resetToken = await prisma.passwordResetToken.findUnique({ where: { token } });
  if (!resetToken || resetToken.usedAt || resetToken.expiresAt < new Date()) {
    return { error: "This reset link is invalid or has expired. Request a new one." };
  }

  const passwordHash = await hashPassword(password);
  await prisma.$transaction([
    prisma.user.update({ where: { id: resetToken.userId }, data: { passwordHash } }),
    prisma.passwordResetToken.update({ where: { id: resetToken.id }, data: { usedAt: new Date() } }),
  ]);

  await recordAudit({ actorId: resetToken.userId, action: "PASSWORD_RESET", entityType: "User", entityId: resetToken.userId });

  return { success: true };
}
