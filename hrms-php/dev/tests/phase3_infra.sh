#!/usr/bin/env bash
# Phase 3 — core infrastructure tests against the running dev stack.
# Usage: bash hrms-php/dev/tests/phase3_infra.sh [base_url]
set -u
BASE="${1:-http://localhost:8082}"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
pass=0; fail=0
ok()   { pass=$((pass+1)); echo "  PASS $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL $1  [$2]"; }
expect_code() { # label expected url [curl args...]
  local label="$1" want="$2" url="$3"; shift 3
  local got; got=$(curl -s -o /dev/null -w '%{http_code}' "$@" "$BASE$url")
  [ "$got" = "$want" ] && ok "$label" || bad "$label" "got $got want $want"
}
csrf() { curl -s -c "$JAR" -b "$JAR" "$BASE/__selftest" | sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' | head -1; }

echo "== Self-test values"
page=$(curl -s -c "$JAR" -b "$JAR" "$BASE/__selftest")
for k in '"db_sql_mode_strict": true' '"db_time_zone": "+05:30"' '"tables": 43' '"new_id_valid": true' '"bad_id_rejected": true' \
         '"date_input": "2026-10-05 00:00:00.000"' '"date_input_invalid": true' '"add_months_js(2026-01-31,+1)": "2026-03-03"' \
         '"//evil.com": "/dashboard"' '"https://evil.com": "/dashboard"' '"javascript:alert(1)": "/dashboard"' \
         '"/javascript:alert(1)": "/dashboard"' '"/dashboard/leave": "/dashboard/leave"' '&amp;lt;script&amp;gt;'; do
  echo "$page" | grep -qF "$(echo "$k" | sed 's/"/\&quot;/g')" && ok "selftest $k" || bad "selftest $k" "missing"
done
php_now=$(echo "$page" | sed -n 's/.*&quot;php_now&quot;: &quot;\([^&]*\)&quot;.*/\1/p')
db_now=$(echo "$page" | sed -n 's/.*&quot;db_now&quot;: &quot;\([^&]*\)&quot;.*/\1/p')
[ "${php_now:0:16}" = "${db_now:0:16}" ] && ok "PHP and MySQL clocks agree in IST ($php_now)" || bad "clock agreement" "$php_now vs $db_now"

echo "== CSRF"
expect_code "POST without token → 403 (session expired)" 403 /__selftest -b "$JAR" -d "_action=ok"
expect_code "POST with wrong token → 403" 403 /__selftest -b "$JAR" -d "_action=ok&_csrf=deadbeef"
T=$(csrf)
expect_code "POST with valid token → 303 redirect" 303 /__selftest -b "$JAR" -c "$JAR" -d "_action=ok&_csrf=$T&note=hi"
curl -s -b "$JAR" -c "$JAR" "$BASE/__selftest" | grep -q 'POST accepted, CSRF verified: hi' && ok "flash shown after redirect (PRG)" || bad "flash after redirect" "missing"
curl -s -b "$JAR" -c "$JAR" "$BASE/__selftest" | grep -q 'POST accepted' && bad "flash consumed once" "shown twice" || ok "flash consumed once"

echo "== Error handling"
T=$(csrf)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -d "_action=user_error&_csrf=$T" "$BASE/__selftest"
curl -s -b "$JAR" -c "$JAR" "$BASE/__selftest" | grep -q 'Validation message shown to the user &lt;b&gt;escaped&lt;/b&gt;' && ok "UserError flashed and HTML-escaped" || bad "UserError flash" "missing/unescaped"
T=$(csrf)
curl -s -o /dev/null -b "$JAR" -c "$JAR" -d "_action=duplicate&_csrf=$T" "$BASE/__selftest"
p=$(curl -s -b "$JAR" -c "$JAR" "$BASE/__selftest")
echo "$p" | grep -q 'That record already exists' && ok "duplicate key → safe message" || bad "duplicate message" "missing"
echo "$p" | grep -q '&quot;dup_company_rows_after_rollback&quot;: 0' && ok "transaction rolled back (0 rows)" || bad "rollback" "rows left"
T=$(csrf)
body=$(curl -s -w '\n%{http_code}' -b "$JAR" -d "_action=crash&_csrf=$T" "$BASE/__selftest")
code=$(echo "$body" | tail -1)
[ "$code" = "500" ] && ok "unexpected exception → 500" || bad "crash status" "$code"
echo "$body" | grep -q 'Reference:' && ok "500 page shows reference id" || bad "reference id" "missing"
echo "$body" | grep -qiE 'NoSuchTable|SQLSTATE|/home/app|\.php|Stack trace' && bad "500 page leaks internals" "found" || ok "500 page leaks no SQL/paths/trace"
ls storage/logs/app-*.log >/dev/null 2>&1 && grep -q 'NoSuchTable' storage/logs/app-*.log && ok "technical detail written to storage/logs" || bad "error log" "missing"

echo "== Routing"
expect_code "unknown route → 404" 404 /nope
expect_code "invalid id shape → 404" 404 "/dashboard/employees/'%20OR%201=1"
expect_code "protected route redirects to login (303)" 303 /dashboard/employees
loc=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/dashboard/leave?x=1")
[[ "$loc" == *"/login?next=%2Fdashboard%2Fleave%3Fx%3D1" ]] && ok "login redirect carries next=" || bad "next param" "$loc"
expect_code "GET on POST-only /logout → 405" 405 /logout
expect_code "index.php/path style works" 200 /index.php/__selftest

echo "== File protection (.htaccess)"
expect_code "static asset served" 200 /assets/vendor/chart.umd.min.js
expect_code "/.env blocked" 403 /.env
expect_code "/.htaccess blocked" 403 /.htaccess
expect_code "direct .php other than index.php blocked" 403 /assets/x.php
for p in /app/bootstrap.php /storage/logs/ /database/hrms.sql /app/cli/create_admin.php; do
  got=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$p"); [ "$got" = "403" ] || [ "$got" = "404" ] && ok "private path $p not served ($got)" || bad "private path $p" "$got"
done
hdr=$(curl -s -D - -o /dev/null "$BASE/login")
for h in 'X-Content-Type-Options: nosniff' 'X-Frame-Options: SAMEORIGIN' 'Content-Security-Policy:' 'Referrer-Policy:'; do
  echo "$hdr" | grep -qi "$h" && ok "header $h" || bad "header $h" "missing"
done
echo "$hdr" | grep -qi 'X-Powered-By: PHP' && bad "X-Powered-By hidden" "exposed" || ok "X-Powered-By hidden"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
