<?php
/**
 * Application configuration. Every secret comes from .env — nothing
 * sensitive is hard-coded here. See .env.example for the full list.
 */
declare(strict_types=1);

return [
    'app' => [
        'name' => 'WorkNest',
        'env' => env('APP_ENV', 'production'),
        // Never true in production: shows exception details in the browser.
        'debug' => env('APP_ENV', 'production') !== 'production' && env('APP_DEBUG', false) === true,
        'url' => rtrim((string)env('APP_URL', ''), '/'),
        'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata'),
        // Shown on the sign-in pages ("Contact …") and prefilled as the first Super Admin on /install.
        'support_email' => (string)env('SUPPORT_EMAIL', 'business@webpayx.com'),
    ],

    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => (int)env('DB_PORT', 3306),
        'name' => env('DB_NAME', ''),
        'user' => env('DB_USER', ''),
        'password' => env('DB_PASSWORD', ''),
    ],

    'session' => [
        'name' => 'hrms_sid',
        // Matches the original app: a login stays valid for up to 7 days.
        'lifetime' => 60 * 60 * 24 * 7,
    ],

    'mail' => [
        'host' => env('MAIL_HOST'),
        'port' => (int)env('MAIL_PORT', 465),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        // "ssl" (port 465, Hostinger default) or "tls" (STARTTLS, port 587).
        'encryption' => env('MAIL_ENCRYPTION', ''),
        'from_address' => env('MAIL_FROM_ADDRESS', env('MAIL_USERNAME', 'business@webpayx.com')),
        'from_name' => env('MAIL_FROM_NAME', 'WorkNest'),
    ],

    'uploads' => [
        'max_bytes' => 10 * 1024 * 1024, // 10MB — same limit as the original
        'path' => STORAGE_PATH . '/uploads',
    ],

    'security' => [
        // Login attempts per (IP + email) inside the window before a temporary block.
        'login_max_attempts' => 8,
        'login_window_seconds' => 15 * 60,
        // Required by the one-time /install page so nobody else can claim a fresh
        // deployment. Set it in .env before visiting /install; remove it afterwards.
        'setup_key' => (string)env('SETUP_KEY', ''),
    ],
];
