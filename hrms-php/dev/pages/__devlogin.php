<?php
/**
 * DEV ONLY — signs in as a demo user for headless-browser screenshots.
 * Refused unless APP_ENV=local AND the client is on a loopback/private
 * network.
 */
declare(strict_types=1);

$ip = client_ip();
$private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
if (config('app.env') !== 'local' || !$private) {
    throw new HttpError(404);
}
$user = db_one('SELECT u.*, e.id AS employeeId FROM `User` u LEFT JOIN Employee e ON e.userId = u.id WHERE u.email = ?', [strtolower(query('email'))]);
if ($user === null) {
    throw new HttpError(404);
}
auth_login($user, $user['employeeId']);
redirect(safe_next(query('next'), '/dashboard'));
