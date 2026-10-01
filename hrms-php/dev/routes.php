<?php
/**
 * DEV ONLY — diagnostic routes used by dev/tests and dev/shot.sh. Loaded by
 * app/routes.php only when APP_ENV=local and this file exists; the dev/
 * folder is excluded from the deployment package. Each page also refuses
 * to run unless APP_ENV=local.
 */
declare(strict_types=1);

$devPages = ['dir' => BASE_PATH . '/dev/pages'];
route('/__selftest', '__selftest.php', ['auth' => false] + $devPages);
route('/__whoami', '__whoami.php', ['methods' => ['GET']] + $devPages);
route('/__devlogin', '__devlogin.php', ['auth' => false, 'methods' => ['GET']] + $devPages);
