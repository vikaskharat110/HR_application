import "server-only";
import nodemailer from "nodemailer";

/**
 * No SMTP account is configured for local dev, so with no SMTP_* env vars set
 * this just logs the email to the server console instead of failing — the
 * reset link is still fully usable, just copy it from the terminal. Set
 * SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS (and optionally MAIL_FROM) in .env
 * to actually send mail.
 */
export async function sendMail({ to, subject, html }: { to: string; subject: string; html: string }) {
  const { SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_FROM } = process.env;

  if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
    console.log(
      `\n[mail] No SMTP_HOST/SMTP_USER/SMTP_PASS configured — logging instead of sending.\nTo: ${to}\nSubject: ${subject}\n${html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim()}\n`
    );
    return;
  }

  const transporter = nodemailer.createTransport({
    host: SMTP_HOST,
    port: Number(SMTP_PORT || 587),
    secure: Number(SMTP_PORT) === 465,
    auth: { user: SMTP_USER, pass: SMTP_PASS },
  });

  await transporter.sendMail({ from: MAIL_FROM || SMTP_USER, to, subject, html });
}
