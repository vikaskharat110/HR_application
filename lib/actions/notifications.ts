"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireSession } from "@/lib/session";

export async function markNotificationRead(id: string) {
  const session = await requireSession();
  await prisma.notification.updateMany({
    where: { id, userId: session.sub },
    data: { isRead: true },
  });
  revalidatePath("/dashboard", "layout");
}

export async function markAllNotificationsRead() {
  const session = await requireSession();
  await prisma.notification.updateMany({
    where: { userId: session.sub, isRead: false },
    data: { isRead: true },
  });
  revalidatePath("/dashboard", "layout");
}

