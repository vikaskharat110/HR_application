import "server-only";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";

export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB

/** Saves an uploaded file under public/uploads/<companyId>/ and returns its public URL, or "#" if no file was given. */
export async function saveUploadedFile(companyId: string, file: File | null): Promise<string> {
  if (!file || file.size === 0) return "#";
  const dir = path.join(process.cwd(), "public", "uploads", companyId);
  await mkdir(dir, { recursive: true });
  const safeName = `${Date.now()}-${file.name.replace(/[^a-zA-Z0-9_.-]/g, "_")}`;
  const bytes = Buffer.from(await file.arrayBuffer());
  await writeFile(path.join(dir, safeName), bytes);
  return `/uploads/${companyId}/${safeName}`;
}

