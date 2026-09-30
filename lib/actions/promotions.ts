"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireRole, requireSession } from "@/lib/session";
import { assertCanActOn } from "@/lib/scope";
import { recordAudit, notify, resolveApprover } from "@/lib/workflow";
import { applyOrgFieldChanges, wouldCreateManagerCycle } from "@/lib/actions/employees";
import type { PromotionTransferType } from "@prisma/client";

export async function requestPromotionTransfer(formData: FormData) {
  const session = await requireRole(["COMPANY_ADMIN", "HR"]);
  if (!session.companyId) throw new Error("No company context");

  const employeeId = String(formData.get("employeeId") || "");
  const type = String(formData.get("type") || "TRANSFER") as PromotionTransferType;
  const newDesignation = String(formData.get("newDesignation") || "").trim() || null;
  const newDepartmentId = String(formData.get("newDepartmentId") || "") || null;
  const newBranchId = String(formData.get("newBranchId") || "") || null;
  const newManagerId = String(formData.get("newManagerId") || "") || null;
  const newBasicRaw = String(formData.get("newBasic") || "").trim();
  const newBasic = newBasicRaw ? Number(newBasicRaw) : null;
  const effectiveDate = String(formData.get("effectiveDate") || "");
  const reason = String(formData.get("reason") || "").trim();

  const employee = await prisma.employee.findFirst({ where: { id: employeeId, companyId: session.companyId } });
  if (!employee) throw new Error("Employee not found.");

  if (!reason || !effectiveDate) throw new Error("Reason and effective date are required.");
  if (!newDesignation && !newDepartmentId && !newBranchId && !newManagerId && newBasic == null) {
    throw new Error("Provide at least one change — designation, department, branch, manager, or salary.");
  }

  if (newManagerId) {
    const manager = await prisma.employee.findFirst({ where: { id: newManagerId, companyId: session.companyId } });
    if (!manager) throw new Error("Manager not found.");
    if (await wouldCreateManagerCycle(employeeId, newManagerId)) {
      throw new Error("That would create a reporting cycle.");
    }
  }
  if (newDepartmentId) {
    const dept = await prisma.department.findFirst({ where: { id: newDepartmentId, companyId: session.companyId } });
    if (!dept) throw new Error("Department not found.");
  }
  if (newBranchId) {
    const branch = await prisma.branch.findFirst({ where: { id: newBranchId, companyId: session.companyId } });
    if (!branch) throw new Error("Branch not found.");
  }

  const approver = await resolveApprover(employeeId);

  const record = await prisma.promotionTransfer.create({
    data: {
      employeeId,
      requestedById: session.employeeId ?? employeeId,
      type,
      newDesignation,
      newDepartmentId,
      newBranchId,
      newManagerId,
      newBasic,
      effectiveDate: new Date(effectiveDate),
      reason,
      approverId: approver?.id,
    },
  });

  if (approver?.userId) {
    await notify({
      userId: approver.userId,
      title: "Promotion/transfer awaiting your approval",
      message: `${employee.firstName} ${employee.lastName} has a proposed ${type.toLowerCase()} to review.`,
      link: "/dashboard/employees/promotions",
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: "CREATE",
    entityType: "PromotionTransfer",
    entityId: record.id,
    meta: { employeeId, type },
  });

  revalidatePath("/dashboard/employees/promotions");
  redirect(`/dashboard/employees/${employeeId}`);
}

export async function decidePromotionTransfer(id: string, approve: boolean, comments?: string) {
  const session = await requireSession();

  const existing = await prisma.promotionTransfer.findUnique({ where: { id } });
  if (!existing) throw new Error("Request not found.");
  await assertCanActOn(session, existing.employeeId, existing.approverId);
  if (existing.status !== "PENDING") throw new Error("This request has already been actioned.");

  const record = await prisma.promotionTransfer.update({
    where: { id },
    data: { status: approve ? "APPROVED" : "REJECTED", actedAt: new Date(), comments },
    include: { employee: { include: { user: true } } },
  });

  if (approve) {
    const employee = await prisma.employee.findUnique({ where: { id: record.employeeId } });
    if (!employee) throw new Error("Employee not found.");

    const patch: Record<string, string | null> = {};
    if (record.newDesignation) patch.designation = record.newDesignation;
    if (record.newDepartmentId) patch.departmentId = record.newDepartmentId;
    if (record.newBranchId) patch.branchId = record.newBranchId;
    if (record.newManagerId) patch.managerId = record.newManagerId;

    if (Object.keys(patch).length > 0) {
      await applyOrgFieldChanges({
        companyId: employee.companyId,
        actorId: session.sub,
        employeeId: employee.id,
        existing: {
          designation: employee.designation,
          departmentId: employee.departmentId,
          branchId: employee.branchId,
          managerId: employee.managerId,
        },
        patch,
        auditAction: `${record.type}_APPROVED`,
        auditMeta: { promotionTransferId: id, effectiveDate: record.effectiveDate },
      });
    }

    if (record.newBasic != null) {
      const before = await prisma.salaryStructure.findUnique({ where: { employeeId: employee.id } });
      await prisma.salaryStructure.upsert({
        where: { employeeId: employee.id },
        create: { employeeId: employee.id, basic: record.newBasic, hra: Math.round(record.newBasic * 0.4) },
        update: { basic: record.newBasic },
      });
      await recordAudit({
        companyId: employee.companyId,
        actorId: session.sub,
        action: "SALARY_REVISION",
        entityType: "SalaryStructure",
        entityId: employee.id,
        meta: { promotionTransferId: id, from: before?.basic ?? null, to: record.newBasic },
      });
    }
  }

  if (record.employee.user) {
    await notify({
      userId: record.employee.user.id,
      title: `${record.type === "PROMOTION" ? "Promotion" : "Transfer"} ${approve ? "approved" : "rejected"}`,
      message: approve
        ? `Your ${record.type.toLowerCase()}, effective ${record.effectiveDate.toDateString()}, has been approved.`
        : `Your proposed ${record.type.toLowerCase()} was not approved.`,
      link: `/dashboard/employees/${record.employeeId}`,
    });
  }

  await recordAudit({
    companyId: session.companyId,
    actorId: session.sub,
    action: approve ? "APPROVE" : "REJECT",
    entityType: "PromotionTransfer",
    entityId: id,
  });

  revalidatePath("/dashboard/employees/promotions");
  revalidatePath(`/dashboard/employees/${record.employeeId}`);
}

