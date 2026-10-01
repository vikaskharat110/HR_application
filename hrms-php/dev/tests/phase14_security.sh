#!/usr/bin/env bash
# DEV ONLY — Phase 14 security checks against a *fallback layout* deployment
# (everything inside public_html, APP_ENV=production) running in a second
# throwaway container on :8083. Needs the hrms-php82 image and hrms_test DB.
#   bash hrms-php/dev/tests/phase14_security.sh
set -u
export MSYS_NO_PATHCONV=1
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
FB="$WORK/public_html"
B=http://localhost:8083
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  PASS $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1 ($2)"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
expect() { local c; c=$(code "$B$2"); case " $1 " in *" $c "*) ok "$2 → $c";; *) bad "$2" "got $c, want $1";; esac; }

echo "== Build fallback layout"
mkdir -p "$FB"
cp -r "$ROOT/public_html/." "$FB/"
cp -r "$ROOT/app" "$ROOT/database" "$ROOT/dev" "$FB/"
mkdir -p "$FB/storage"/{cache,logs,sessions,uploads}
cp "$ROOT/storage/.htaccess" "$FB/storage/" && cp "$ROOT/storage/uploads/.htaccess" "$FB/storage/uploads/"
sed -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's#^APP_URL=.*#APP_URL=http://localhost:8083#' "$ROOT/.env" > "$FB/.env"
echo "secret" > "$FB/storage/uploads/probe.pdf"
printf '<?php echo "EXECUTED";' > "$FB/assets/probe.php"
printf '<?php echo "EXECUTED";' > "$FB/storage/uploads/probe.php"
docker rm -f hrms-fallback >/dev/null 2>&1
docker run -d --name hrms-fallback -p 8083:80 -v "$(cygpath -w "$FB"):/home/app/public_html" hrms-php82 >/dev/null
for _ in $(seq 1 30); do [ "$(code $B/login)" = 200 ] && break; sleep 1; done
[ "$(code $B/login)" = 200 ] && ok "fallback layout boots (index.php finds ./app)" || bad "boot" "$(code $B/login)"

echo "== Private files are unreachable"
for p in /.env /.htaccess /app/bootstrap.php /app/config/config.php /app/routes.php /app/ /storage/ /storage/uploads/probe.pdf \
         /storage/uploads/probe.php /storage/sessions/ /database/hrms.sql /dev/demo/hrms_demo_data.sql /app/cli/create_admin.php \
         /dev/ /dev/routes.php /dev/pages/__devlogin.php /dev/tests/lib.php /app/vendor/phpmailer/PHPMailer.php \
         /assets/probe.php /index.php.bak /.env.example; do
  expect "403 404" "$p"
done
body=$(curl -s "$B/assets/probe.php"); [[ "$body" != *EXECUTED* ]] && ok "stray .php under assets never executes" || bad "assets php" "executed"
body=$(curl -s "$B/storage/uploads/probe.php"); [[ "$body" != *EXECUTED* ]] && ok "uploaded .php never executes" || bad "uploads php" "executed"
expect "200" /assets/css/app.css
expect "200" /assets/js/app.js
expect "200" /assets/vendor/chart.umd.min.js
expect "200" /login
expect "200 303 404" /install

echo "== Dev/diagnostic routes absent in production"
expect "404" /__selftest
expect "404" "/__devlogin?email=admin@meridianworks.com"
expect "404 303" /__whoami
expect "404" /login/demo
body=$(curl -s "$B/login"); [[ "$body" != *"login/demo"* && "$body" != *"Passw0rd"* ]] && ok "login page has no demo link or password" || bad "login demo" "present"

echo "== Headers and cookies"
H=$(curl -s -D - -o /dev/null "$B/login")
for h in "Content-Security-Policy: default-src 'self'; script-src 'self'" "X-Content-Type-Options: nosniff" "X-Frame-Options: SAMEORIGIN" "Referrer-Policy: strict-origin-when-cross-origin" "Permissions-Policy:"; do
  shopt -s nocasematch; [[ "$H" == *"$h"* ]] && ok "header ${h%%:*}" || bad "header ${h%%:*}" missing; shopt -u nocasematch
done
grep -qi "^X-Powered-By" <<<"$H" && bad "X-Powered-By" present || ok "no X-Powered-By"
grep -qi "^Server:.*PHP" <<<"$H" && bad "Server header leaks PHP" yes || ok "Server header does not leak PHP"
C=$(grep -i "^Set-Cookie: hrms_sid" <<<"$H")
[[ "$C" == *HttpOnly* && "$C" == *SameSite=Lax* ]] && ok "session cookie HttpOnly + SameSite=Lax" || bad "cookie flags" "$C"
H=$(curl -s -D - -o /dev/null -H 'X-Forwarded-Proto: https' "$B/login")
grep -qi "^Strict-Transport-Security" <<<"$H" && grep -i "^Set-Cookie: hrms_sid" <<<"$H" | grep -qi secure && ok "HTTPS (proxy): HSTS + Secure cookie" || bad "https" "no HSTS/secure"

echo "== Errors never leak internals"
R404=$(curl -s "$B/definitely/not/here")
[[ "$R404" == *"Page not found"* || "$R404" == *"404"* ]] && ok "friendly 404 page" || bad "404 page" "?"
for leak in SQLSTATE PDOException "Stack trace" "/home/app" "app/core" ".php on line" DB_PASSWORD; do
  [[ "$R404" != *"$leak"* ]] && ok "404 page hides '$leak'" || bad "404 leak" "$leak"
done
R=$(curl -s -X POST "$B/login" -d 'email=a@b.c&password=x')
[[ "$R" != *SQLSTATE* && "$R" != *"Stack trace"* ]] && ok "POST without CSRF token → no internals" || bad "csrf page" leak
# Break the database password: every DB-backed page must fail safely.
sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=wrong-password/' "$FB/.env"
# A login attempt is the first request that needs the database.
JAR="$(cygpath -m "$WORK")/jar"
TOKEN=$(curl -s -c "$JAR" "$B/login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | cut -d'"' -f4)
R=$(curl -s -b "$JAR" -w '\n%{http_code}' -X POST "$B/login" -d "_csrf=$TOKEN&_action=login&_form=login&email=admin@meridianworks.com&password=x")
st=${R##*$'\n'}
[ "$st" = 500 ] || [ "$st" = 503 ] && ok "DB down → $st" || bad "DB down status" "$st"
for leak in SQLSTATE PDO "Access denied" "Stack trace" "/home/app" "hrms_test" "root@" "wrong-password" "host.docker.internal"; do
  [[ "$R" != *"$leak"* ]] && ok "DB-error page hides '$leak'" || bad "DB error leak" "$leak"
done
L=$(cat "$FB"/storage/logs/*.log 2>/dev/null)
[[ "$L" == *SQLSTATE* || "$L" == *"Access denied"* ]] && ok "error recorded in storage/logs instead" || bad "error log" "missing"
[[ "$L" != *"wrong-password"* ]] && ok "log does not contain the DB password" || bad "log" "password logged"

docker rm -f hrms-fallback >/dev/null 2>&1
rm -rf "$WORK"
echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
