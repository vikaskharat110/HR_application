<?php
/**
 * CLI alternative to the /install page (for SSH users):
 *
 *     php app/cli/create_admin.php admin@example.com
 *
 * Prompts for the password (hidden where the terminal supports it). Refuses
 * to run once a Super Admin exists. Lives in app/ (private in both layouts) — never reachable over the web.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

$email = $argv[1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage: php app/cli/create_admin.php <email>\n");
    exit(2);
}
if (install_is_locked()) {
    fwrite(STDERR, "Setup is already complete (a Super Admin exists). Nothing to do.\n");
    exit(1);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    $hide = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string)fgets(STDIN), "\r\n");
    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}

try {
    $password = prompt_hidden('Password (min 12 characters): ');
    $confirm = prompt_hidden('Confirm password: ');
    install_create_super_admin($email, $password, $confirm);
    fwrite(STDOUT, "Super Admin {$email} created. Setup is now locked.\n");
} catch (UserError $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
