#!/usr/bin/env bash
# Phase 5 — shared layout/UI: chrome per role, notifications, profile /
# change password, assets, CSP compliance. Restores every row it changes.
set -u
export MSYS_NO_PATHCONV=1
BASE="${BASE:-http://localhost:8082}"
DBC="${DBC:-hrms-mariadb11}"
DBNAME="$(sed -n 's/^DB_NAME=//p' "$(cd "$(dirname "$0")/../.." && pwd)/.env")"
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "  PASS $1"; }
bad() { fail=$((fail+1)); echo "  FAIL $1  [$2]"; }
sql() { docker exec "$DBC" mariadb -uroot -prootpw -N -B "$DBNAME" -e "$1"; }
newjar() { cygpath -m "$(mktemp)"; }
token() { curl -s -c "$1" -b "$1" "$BASE${2:-/login}" | sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' | head -1; }
login() { local T; T=$(token "$1"); curl -s -o /dev/null -c "$1" -b "$1" --data-urlencode "email=$2" --data-urlencode "password=Passw0rd!" -d "_action=login&_form=login&_csrf=$T&next=/dashboard" "$BASE/login"; }
page() { curl -s -b "$1" -c "$1" "$BASE$2"; }

echo "== Assets"
for a in css/app.css:text/css js/app.js:javascript js/charts.js:javascript vendor/chart.umd.min.js:javascript icons/lucide.svg:image/svg+xml fonts/manrope-latin-wght-normal.woff2:font/woff2 img/favicon.ico:icon img/favicon-32.png:image/png img/worknest-logo.png:image/png img/worknest-mark.png:image/png; do
  p="${a%%:*}"; t="${a#*:}"
  r=$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$BASE/assets/$p"); [[ "$r" == 200*"$t"* ]] && ok "asset $p ($r)" || bad "asset $p" "$r"
done
curl -s "$BASE/assets/css/app.css" | grep -q 'font-family:Fraunces\|--font-fraunces:"Fraunces"\|--font-fraunces:Fraunces' && ok "app.css carries self-hosted font faces" || bad "fonts in css" "missing"
curl -s "$BASE/assets/css/app.css" | grep -q '\.btn-primary' && ok "app.css contains original component classes" || bad "component classes" "missing"

echo "== Layout per role"
JA=$(newjar); login "$JA" admin@meridianworks.com; H=$(page "$JA" /dashboard)
for label in 'Audit Logs' 'Employees' 'Payroll' 'Settings' 'Exit / Offboarding'; do echo "$H" | grep -q ">$label</a>" && ok "admin nav has $label" || bad "admin nav $label" "missing"; done
for label in 'Companies' 'Roles &amp; Permissions'; do echo "$H" | grep -q ">$label</a>" && bad "admin nav hides $label" "shown" || ok "admin nav hides $label"; done
echo "$H" | grep -q 'aria-current="page"[^>]*>.*Dashboard</a>' && ok "active nav item marked" || bad "active nav" "missing"
echo "$H" | grep -q 'Meridian Works' && ok "company name in sidebar" || bad "company name" "missing"
echo "$H" | grep -q 'id="mobile-menu"' && ok "mobile menu rendered" || bad "mobile menu" "missing"
echo "$H" | grep -q 'Ananya Rao' && echo "$H" | grep -q 'Company Admin' && ok "user menu shows name + role" || bad "user menu" "?"
echo "$H" | grep -q 'data-chart=' && ok "dashboard charts rendered" || bad "charts" "missing"
JE=$(newjar); login "$JE" employee@meridianworks.com; H=$(page "$JE" /dashboard)
echo "$H" | grep -q '>Employees</a>' && bad "employee nav hides Employees" "shown" || ok "employee nav hides Employees"
echo "$H" | grep -q 'My leave balance' && ok "employee sees own leave balance card" || bad "leave balance card" "missing"
echo "$H" | grep -q 'Awaiting your approval' && bad "employee has no approvals card" "shown" || ok "employee has no approvals card"
JS=$(newjar); login "$JS" business@webpayx.com; H=$(page "$JS" /dashboard)
echo "$H" | grep -q 'Platform overview' && ok "super admin sees platform overview" || bad "super admin dash" "?"
echo "$H" | grep -q 'Platform-wide' && ok "super admin sidebar says Platform-wide" || bad "platform-wide" "?"
CO=$(sql "SELECT id FROM Company WHERE name='Meridian Works'")
sql "INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES ('ctestlayout00000000000001','EMPLOYEE','/dashboard/expenses','VIEW',0,'$CO')"
page "$JE" /dashboard | grep -q '>Expenses</a>' && bad "disabled module removed from sidebar" "shown" || ok "disabled module removed from sidebar"
sql "DELETE FROM RolePermission WHERE id='ctestlayout00000000000001'"

echo "== CSP compliance (no inline executable scripts)"
for p in /dashboard /dashboard/profile; do
  n=$(page "$JA" "$p" | grep -o '<script[^>]*>' | grep -v ' src=' | grep -vc 'application/json')
  [ "$n" = "0" ] && ok "$p has no inline <script>" || bad "$p inline scripts" "$n"
done

echo "== Notifications"
UID_E=$(sql "SELECT id FROM User WHERE email='employee@meridianworks.com'")
UID_A=$(sql "SELECT id FROM User WHERE email='admin@meridianworks.com'")
sql "INSERT INTO Notification (id,userId,title,message,link,isRead) VALUES
 ('ctestnotif0000000000000001','$UID_E','<script>alert(1)</script>','<img src=x onerror=alert(2)>','https://evil.com/phish',0),
 ('ctestnotif0000000000000002','$UID_E','Leave approved','ok','/dashboard/leave',0),
 ('ctestnotif0000000000000003','$UID_A','Admin only','secret','/dashboard',0)"
H=$(page "$JE" /dashboard)
echo "$H" | grep -q '&lt;script&gt;alert(1)&lt;/script&gt;' && ok "notification title HTML-escaped" || bad "title escape" "?"
echo "$H" | grep -q '<img src=x' && bad "notification message escaped" "raw img" || ok "notification message escaped"
echo "$H" | grep -q 'href="https://evil.com' && bad "external notification link neutralised" "raw link" || ok "external notification link neutralised"
echo "$H" | grep -q 'Admin only' && bad "other user's notifications hidden" "leaked" || ok "other user's notifications hidden"
T=$(token "$JE" /dashboard)
r=$(curl -s -b "$JE" -H 'Content-Type: application/json' -H 'Accept: application/json' -H "X-CSRF-Token: $T" -d '{"id":"ctestnotif0000000000000002"}' "$BASE/api/notifications/read")
[ "$r" = '{"ok":true}' ] && [ "$(sql "SELECT isRead FROM Notification WHERE id='ctestnotif0000000000000002'")" = "1" ] && ok "mark one read (JSON + CSRF header)" || bad "mark read" "$r"
curl -s -o /dev/null -b "$JE" -H 'Content-Type: application/json' -H 'Accept: application/json' -H "X-CSRF-Token: $T" -d '{"id":"ctestnotif0000000000000003"}' "$BASE/api/notifications/read"
[ "$(sql "SELECT isRead FROM Notification WHERE id='ctestnotif0000000000000003'")" = "0" ] && ok "cannot mark another user's notification" || bad "cross-user mark read" "changed"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JE" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"id":"ctestnotif0000000000000001"}' "$BASE/api/notifications/read")
[ "$code" = "403" ] && ok "mark read without CSRF → 403" || bad "notif csrf" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JE" -d "_csrf=$T&_back=/dashboard/profile" "$BASE/api/notifications/read-all")
[[ "$code" == 303:*"/dashboard/profile" ]] && ok "mark all read (no-JS form fallback redirects back)" || bad "read-all fallback" "$code"
[ "$(sql "SELECT COUNT(*) FROM Notification WHERE userId='$UID_E' AND isRead=0")" = "0" ] && ok "all own notifications read" || bad "read-all" "unread left"
[ "$(sql "SELECT isRead FROM Notification WHERE id='ctestnotif0000000000000003'")" = "0" ] && ok "read-all doesn't touch other users" || bad "read-all scope" "changed"
code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST "$BASE/api/notifications/read-all")
[ "$code" = "403" ] || [ "$code" = "401" ] && ok "anonymous notification API refused ($code)" || bad "anon notif api" "$code"
sql "DELETE FROM Notification WHERE id LIKE 'ctestnotif%'"

echo "== Profile / change password"
OLD=$(sql "SELECT passwordHash FROM User WHERE email='manager@meridianworks.com'")
JM=$(newjar); login "$JM" manager@meridianworks.com; JM2=$(newjar); login "$JM2" manager@meridianworks.com
H=$(page "$JM" /dashboard/profile)
echo "$H" | grep -q 'Engineering Manager' && echo "$H" | grep -q 'Change password' && ok "profile shows employment + password form" || bad "profile" "?"
post_pw() { local T; T=$(token "$JM" /dashboard/profile); curl -s -o /dev/null -b "$JM" -c "$JM" --data-urlencode "currentPassword=$1" --data-urlencode "newPassword=$2" --data-urlencode "confirmPassword=$3" -d "_action=change_password&_form=password&_csrf=$T" "$BASE/dashboard/profile"; page "$JM" /dashboard/profile; }
post_pw 'wrong' 'NewSecret#1' 'NewSecret#1' | grep -q 'Current password is incorrect.' && ok "wrong current password rejected" || bad "wrong current" "?"
post_pw 'Passw0rd!' 'NewSecret#1' 'Different#1' | grep -q 'New passwords don’t match.' && ok "mismatch rejected" || bad "mismatch" "?"
post_pw 'Passw0rd!' 'short' 'short' | grep -q 'at least 8 characters' && ok "short password rejected" || bad "short" "?"
post_pw 'Passw0rd!' 'NewSecret#1' 'NewSecret#1' | grep -q 'Password updated.' && ok "password changed" || bad "change pw" "?"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JM" "$BASE/dashboard/profile")" = "200" ] && ok "current session kept after own change" || bad "own session" "lost"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JM2" "$BASE/dashboard/profile")" = "303" ] && ok "other sessions signed out" || bad "other session" "kept"
[ "$(sql "SELECT COUNT(*) FROM AuditLog WHERE action='CHANGE_PASSWORD'")" -ge 1 ] && ok "CHANGE_PASSWORD audited" || bad "audit" "missing"
sql "UPDATE User SET passwordHash='$OLD' WHERE email='manager@meridianworks.com'"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
