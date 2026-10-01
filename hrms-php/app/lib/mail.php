<?php
/**
 * Outgoing email via PHPMailer + SMTP (Hostinger: smtp.hostinger.com:465 SSL).
 * Credentials come only from .env. With no MAIL_HOST configured the message is
 * written to storage/logs/mail-*.log when APP_ENV=local (mirrors the original
 * "log to console instead of failing" dev behaviour); in production a warning
 * without the message body is logged instead.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function send_mail(string $to, string $subject, string $html): bool
{
    $cfg = config('mail');
    if (empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
        if (config('app.env') === 'local') {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '</p>'], "\n", $html))));
            preg_match_all('/href="([^"]+)"/', $html, $m);
            $links = $m[1] ? "\nLinks: " . implode(' ', array_map('html_entity_decode', $m[1])) : '';
            log_write('mail', sprintf("[%s] To: %s | Subject: %s\n%s%s\n\n", date('Y-m-d H:i:s'), $to, $subject, $text, $links));
        } else {
            log_write('mail', sprintf("[%s] NOT SENT (SMTP not configured) to=%s subject=%s\n", date('Y-m-d H:i:s'), $to, $subject));
        }
        return false;
    }

    require_once APP_PATH . '/vendor/phpmailer/Exception.php';
    require_once APP_PATH . '/vendor/phpmailer/PHPMailer.php';
    require_once APP_PATH . '/vendor/phpmailer/SMTP.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = $cfg['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $enc = strtolower((string)$cfg['encryption']);
        $mail->SMTPSecure = $enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS
            : ($enc === 'ssl' || $cfg['port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : '');
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;
        $mail->setFrom($cfg['from_address'] ?: $cfg['username'], $cfg['from_name'] ?: 'WorkNest');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
        $mail->send();
        return true;
    } catch (Throwable $e) {
        // Never surface SMTP details to the user; keep the flow non-enumerating.
        log_error(new RuntimeException('Mail send failed: ' . $mail->ErrorInfo), ['to' => $to]);
        return false;
    }
}
