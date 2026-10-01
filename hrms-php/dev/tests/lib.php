<?php
/**
 * DEV ONLY — tiny HTTP + DB test harness for the functional test suites
 * (phase6_*.php onward). Runs with the local PHP CLI against the dev stack.
 *
 *   $admin = Client::as('admin@meridianworks.com');   // logs in via the real form
 *   $page  = $admin->get('/dashboard/employees');
 *   $res   = $admin->post('/dashboard/employees/new', ['_action' => 'create', ...]);
 *   check('label', $condition, 'detail');
 */
declare(strict_types=1);

const BASE = 'http://localhost:8082';
const PW = 'Passw0rd!';
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . (!$ok && $detail !== '' ? "  [$detail]" : '') . PHP_EOL;
}

function section(string $title): void
{
    echo "== $title\n";
}

function finish(): never
{
    echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
    exit($GLOBALS['fail'] === 0 ? 0 : 1);
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $env = parse_ini_file(__DIR__ . '/../../.env', false, INI_SCANNER_RAW) ?: [];
        $pdo = new PDO('mysql:host=127.0.0.1;port=33061;dbname=' . ($env['DB_NAME'] ?? 'hrms_test') . ';charset=utf8mb4', 'root', 'rootpw', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

function q(string $sql, array $p = []): array
{
    $s = pdo()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

function q1(string $sql, array $p = []): ?array
{
    return q($sql, $p)[0] ?? null;
}

function qv(string $sql, array $p = []): mixed
{
    $r = q1($sql, $p);
    return $r === null ? null : array_values($r)[0];
}

final class Response
{
    public function __construct(public int $status, public string $body, public string $location, public array $headers)
    {
    }

    public function has(string $needle): bool
    {
        return str_contains($this->body, $needle);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

final class Client
{
    private string $jar;
    public ?Response $last = null;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public static function as(string $email, string $password = PW): self
    {
        $c = new self();
        $r = $c->post('/login', ['_action' => 'login', '_form' => 'login', 'email' => $email, 'password' => $password, 'next' => '/dashboard']);
        if ($r->status !== 303 || !str_ends_with($r->location, '/dashboard')) {
            throw new RuntimeException("login failed for $email ({$r->status} {$r->location})");
        }
        return $c;
    }

    public function get(string $path): Response
    {
        return $this->last = $this->request('GET', $path);
    }

    /** POST with the CSRF token taken from $from (default: the same path). $files: field => local path. */
    public function post(string $path, array $data, ?string $from = null, array $files = [], bool $csrf = true): Response
    {
        if ($csrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->token($from ?? $path);
        }
        foreach ($files as $field => $file) {
            $data[$field] = new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream', basename($file));
        }
        return $this->last = $this->request('POST', $path, $data, $files !== []);
    }

    public function postJson(string $path, array $data): Response
    {
        return $this->last = $this->request('POST', $path, json_encode($data), false, [
            'Content-Type: application/json', 'Accept: application/json',
            'X-CSRF-Token: ' . ($this->request('GET', '/dashboard')->status === 200 ? $this->token('/dashboard') : $this->token('/login')),
        ]);
    }

    public function token(string $from): string
    {
        $page = $this->request('GET', $from);
        if (!preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page->body, $m)
            && !preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page->body, $m)) {
            throw new RuntimeException("no CSRF token on $from ({$page->status})");
        }
        return $m[1];
    }

    /** POST then follow the redirect; returns the landing page. */
    public function submit(string $path, array $data, ?string $from = null, array $files = []): Response
    {
        $r = $this->post($path, $data, $from, $files);
        if ($r->status >= 300 && $r->status < 400 && $r->location !== '') {
            return $this->get(substr($r->location, strlen(BASE)) ?: '/');
        }
        return $r;
    }

    private function request(string $method, string $path, array|string|null $data = null, bool $multipart = false, array $headers = []): Response
    {
        $ch = curl_init(BASE . $path);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, $h) use (&$respHeaders) {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($h);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) && !$multipart ? http_build_query($data) : $data);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string)(curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        return new Response($status, $body, $loc, $respHeaders);
    }
}

/** Minimal valid PNG (1x1) written to a temp file. */
function tmp_png(): string
{
    $raw = "\x00\xff\x00\x00";
    $chunk = static fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
    return tmp_file('logo.png', $png);
}

function tmp_file(string $name, string $content): string
{
    $dir = sys_get_temp_dir() . '/hrms-tests-' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/$name", $content);
    return "$dir/$name";
}

function emp_id(string $email): string
{
    return (string)qv('SELECT id FROM Employee WHERE email = ?', [$email]);
}

function company_id(string $name): string
{
    return (string)qv('SELECT id FROM Company WHERE name = ?', [$name]);
}
