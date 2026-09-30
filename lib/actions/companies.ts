"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/lib/session";
import { hashPassword } from "@/lib/auth";
import { recordAudit, notify } from "@/lib/workflow";
import type { CompanyStatus } from "@prisma/client";

function slugify(name: string) {
  return name.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
}

export type CreateCompanyState = { error?: string };

export async function createCompany(_prev: CreateCompanyState, formData: FormData): Promise<CreateCompanyState> {
  const session = await requireRole(["SUPER_ADMIN"]);

  const name = String(formData.get("name") || "").trim();
  const industry = String(formData.get("industry") || "").trim();
  const adminEmail = String(formData.get("adminEmail") || "").trim().toLowerCase();

  if (!name || !adminEmail) {
    return { error: "Company name and admin email are required." };
  }

  const existingUser = await prisma.user.findUnique({ where: { email: adminEmail } });
  if (existingUser) {
    return { error: "A user with this email already exists on the platform. Choose a different admin email." };
  }

  const baseSlug = slugify(name);
  let slug = baseSlug;
  let i = 1;
  while (await prisma.company.findUnique({ where: { slug } })) {
    slug = `${baseSlug}-${i++}`;
  }

  const company = await prisma.company.create({
    data: { name, industry, slug, policy: { create: {} } },
  });

  // Without at least one template per category, the very first hire's
  // "Onboard as Employee" silently creates no checklist at all (the onboarding
  // action skips creating an empty one) — so every new tenant starts with a
  // sane default set rather than an empty, surprising gap.
  await prisma.onboardingTaskTemplate.createMany({
    data: [
      { companyId: company.id, category: "HR", title: "Collect ID proof & signed offer acceptance", sortOrder: 1 },
      { companyId: company.id, category: "HR", title: "Initiate background verification (BGV)", sortOrder: 2 },
      { companyId: company.id, category: "HR", title: "Share employee handbook & policy acknowledgement", sortOrder: 3 },
      { companyId: company.id, category: "IT", title: "Create company email account", sortOrder: 1 },
      { companyId: company.id, category: "IT", title: "Allocate laptop and other assets", sortOrder: 2 },
      { companyId: company.id, category: "IT", title: "Provision system/application access", sortOrder: 3 },
      { companyId: company.id, category: "MANAGER", title: "Prepare workstation and welcome kit", sortOrder: 1 },
      { companyId: company.id, category: "MANAGER", title: "Schedule day-one team introduction", sortOrder: 2 },
    ],
  });

  const passwordHash = await hashPassword("Welcome@123");
  const admin = await prisma.user.create({
    data: { email: adminEmail, passwordHash, role: "COMPANY_ADMIN", companyId: company.id },
  });

  await notify({ userId: admin.id, title: "Welcome to Verdant HR", message: "Your company workspace is ready. Temporary password: Welcome@123", link: "/dashboard" });
  await recordAudit({ actorId: session.sub, action: "CREATE", entityType: "Company", entityId: company.id, meta: { name } });

  revalidatePath("/dashboard/companies");
  redirect("/dashboard/companies");
}

export async function updateCompanyStatus(companyId: string, status: CompanyStatus) {
  const session = await requireRole(["SUPER_ADMIN"]);

  const company = await prisma.company.findUnique({ where: { id: companyId }, select: { status: true, name: true } });
  if (!company) throw new Error("Company not found.");
  if (company.status === status) return;

  await prisma.company.update({ where: { id: companyId }, data: { status } });

  await recordAudit({
    companyId,
    actorId: session.sub,
    action: "STATUS_CHANGE",
    entityType: "Company",
    entityId: companyId,
    meta: { from: company.status, to: status },
  });

  if (status !== "ACTIVE") {
    const admins = await prisma.user.findMany({ where: { companyId, role: "COMPANY_ADMIN" } });
    await Promise.all(
      admins.map((a) =>
        notify({
          userId: a.id,
          title: status === "SUSPENDED" ? "Your workspace was suspended" : "Your workspace was archived",
          message: `${company.name} was ${status.toLowerCase()} by the platform administrator.`,
        })
      )
    );
  }

  revalidatePath("/dashboard/companies");
  revalidatePath(`/dashboard/companies/${companyId}`);
}

export async function toggleCompanyUser(userId: string, isActive: boolean) {
  const session = await requireRole(["SUPER_ADMIN"]);
  if (userId === session.sub) {
    throw new Error("You cannot change your own account status.");
  }
  await prisma.user.update({ where: { id: userId }, data: { isActive } });
  await recordAudit({ actorId: session.sub, action: isActive ? "ACTIVATE" : "DEACTIVATE", entityType: "User", entityId: userId });
  revalidatePath("/dashboard/companies");
  revalidatePath("/dashboard/users");
}
