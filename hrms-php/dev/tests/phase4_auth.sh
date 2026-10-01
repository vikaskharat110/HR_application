#!/usr/bin/env bash
# Phase 4 — authentication, sessions and RBAC. Runs against the demo DB
# (hrms_demo) and restores every row it changes. Dev stack only.
set -u
export MSYS_NO_PATHCONV=1   # stop Git Bash rewriting '/path' curl args into Windows paths
BASE="${BASE:-http://localhost:8082}"
DBC="${DBC:-hrms-mariadb11}"
DBNAME="$(sed -n 's/^DB_NAME=//p' "$(cd "$(dirname "$0")/../.." && pwd)/.env")"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "  PASS $1"; }
bad() { fail=$((fail+1)); echo "  FAIL $1  [$2]"; }
sql() { MSYS_NO_PATHCONV=1 docker exec "$DBC" mariadb -uroot -prootpw -N -B "${2:-$DBNAME}" -e "$1"; }
newjar() { cygpath -m "$(mktemp)"; }   # Windows-native curl needs a Windows path
token() { curl -s -c "$1" -b "$1" "$BASE${2:-/login}" | sed -n 's/.*name="_csrf" value="\([a-f0-9]*\)".*/\1/p' | head -1; }
login() { # jar email password [next] → prints redirect_url
  local T; T=$(token "$1")
  curl -s -o /dev/null -w '%{redirect_url}' -c "$1" -b "$1" --data-urlencode "email=$2" --data-urlencode "password=$3" \
    --data-urlencode "next=${4:-/dashboard}" -d "_action=login&_form=login&_csrf=$T" "$BASE/login"
}
whoami() { curl -s -b "$1" -c "$1" -H 'Accept: application/json' "$BASE/__whoami"; }
jget() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){$v=$v[$k]??null;} echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v,JSON_UNESCAPED_SLASHES):$v);' "$1"; }
PW='Passw0rd!'

echo "== Login"
J=$(newjar)
curl -s -c "$J" "$BASE/login" | grep -q 'name="_csrf"' && ok "login page renders with CSRF token" || bad "login page" "no token"
loc=$(login "$J" admin@meridianworks.com wrong)
curl -s -b "$J" -c "$J" "$BASE/login" | grep -q 'Incorrect email or password.' && ok "wrong password → generic error" || bad "wrong password msg" "$loc"
login "$J" nobody@nowhere.test whatever >/dev/null
curl -s -b "$J" -c "$J" "$BASE/login" | grep -q 'Incorrect email or password.' && ok "unknown email → same generic error (no enumeration)" || bad "unknown email msg" "?"
before=$(grep hrms_sid "$J" | awk '{print $7}')
loc=$(login "$J" admin@meridianworks.com "$PW" /__whoami)
[[ "$loc" == *"/__whoami" ]] && ok "valid login redirects to next" || bad "login redirect" "$loc"
after=$(grep hrms_sid "$J" | awk '{print $7}')
[ -n "$after" ] && [ "$before" != "$after" ] && ok "session id regenerated at login (fixation)" || bad "session regeneration" "$before/$after"
hdr=$(curl -s -D - -o /dev/null -b "$J" "$BASE/login")
T=$(token "$(newjar)"); setc=$(curl -s -D - -o /dev/null -c /dev/null --data-urlencode "email=admin@meridianworks.com" --data-urlencode "password=$PW" -d "_action=login&_csrf=x" "$BASE/login" | grep -i '^set-cookie' | head -1)
J2=$(newjar); T=$(token "$J2")
setc=$(curl -s -D - -o /dev/null -c "$J2" -b "$J2" --data-urlencode "email=admin@meridianworks.com" --data-urlencode "password=$PW" -d "_action=login&_csrf=$T" "$BASE/login" | grep -i '^set-cookie: hrms_sid' | tail -1)
echo "$setc" | grep -qi 'HttpOnly' && ok "cookie HttpOnly" || bad "HttpOnly" "$setc"
echo "$setc" | grep -qi 'SameSite=Lax' && ok "cookie SameSite=Lax" || bad "SameSite" "$setc"
echo "$setc" | grep -qi 'Max-Age=604800' && ok "cookie lifetime 7 days" || bad "7-day lifetime" "$setc"
echo "$setc" | grep -qi 'secure' && bad "no Secure flag on plain http" "set" || ok "Secure flag only on HTTPS (plain http dev)"
W=$(whoami "$J")
[ "$(echo "$W" | jget auth.role)" = "COMPANY_ADMIN" ] && ok "auth context loaded from DB (COMPANY_ADMIN)" || bad "auth role" "$W"
[ "$(echo "$W" | jget auth.name)" = "Ananya Rao" ] && ok "name resolved from employee record" || bad "name" "$(echo "$W" | jget auth.name)"

echo "== Open redirect (A5)"
for evil in '//evil.com' 'https://evil.com' '/\evil.com' 'javascript:alert(1)'; do
  J3=$(newjar); loc=$(login "$J3" admin@meridianworks.com "$PW" "$evil")
  [[ "$loc" == "$BASE/dashboard" ]] && ok "next=$evil → /dashboard" || bad "next=$evil" "$loc"
done

echo "== Role matrix (nav ceiling + payroll defaults + scope)"
check_role() { # email expectations...
  local j; j=$(newjar); login "$j" "$1" "$PW" >/dev/null; local w; w=$(whoami "$j"); shift
  for exp in "$@"; do local k="${exp%%=*}" v="${exp#*=}"; local got; got=$(echo "$w" | jget "$k")
    [ "$got" = "$v" ] && ok "$k=$v" || bad "$k" "got $got want $v"; done
}
echo " - employee"; check_role employee@meridianworks.com auth.role=EMPLOYEE 'modules./dashboard/employees=false' 'modules./dashboard/attendance=true' 'modules./dashboard/companies=false' payroll.PROCESS=false visibleCount=1 pendingLeaveInQueue=0
echo " - manager";  check_role manager@meridianworks.com 'modules./dashboard/employees=true' 'modules./dashboard/settings=false' payroll.APPROVE=false
echo " - finance";  check_role finance@meridianworks.com payroll.APPROVE=true payroll.PROCESS=false payroll.LOCK=false 'modules./dashboard/expenses=true' 'modules./dashboard/leave=false'
echo " - payroll";  check_role payroll@meridianworks.com payroll.PROCESS=true payroll.EDIT_SALARY=true 'modules./dashboard/settings=false'
echo " - superadmin"; check_role superadmin@worknest.io auth.role=SUPER_ADMIN 'modules./dashboard/companies=true' 'modules./dashboard/employees=false' visibleCount=0
mgrVis=$( j=$(newjar); login "$j" manager@meridianworks.com "$PW" >/dev/null; whoami "$j" | jget visibleCount )
[ "$mgrVis" -gt 1 ] && ok "manager sees reporting line ($mgrVis)" || bad "manager scope" "$mgrVis"
hrVis=$( j=$(newjar); login "$j" hr@meridianworks.com "$PW" >/dev/null; whoami "$j" | jget visibleCount )
total=$(sql "SELECT COUNT(*) FROM Employee e JOIN Company c ON c.id=e.companyId WHERE c.name='Meridian Works'")
[ "$hrVis" = "$total" ] && ok "HR sees whole company ($hrVis) and not the other tenant" || bad "HR scope" "$hrVis vs $total"

echo "== RolePermission overrides"
CO=$(sql "SELECT id FROM Company WHERE name='Meridian Works'")
sql "INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES ('ctestoverride000000000001','EMPLOYEE','/dashboard/leave','VIEW',0,'$CO')"
JE=$(newjar); login "$JE" employee@meridianworks.com "$PW" >/dev/null; W=$(whoami "$JE")
[ "$(echo "$W" | jget 'modules./dashboard/leave')" = "false" ] && ok "tenant override hides module" || bad "override" "$W"
echo "$W" | jget hidden | grep -q '/dashboard/leave' && ok "hidden_hrefs lists it for the nav" || bad "hidden_hrefs" "$(echo "$W" | jget hidden)"
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JE" "$BASE/dashboard/leave"); [[ "$code" == 303:*"/dashboard" ]] && ok "route guard redirects hidden module to /dashboard" || bad "route guard" "$code"
sql "INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES ('ctestoverride000000000002','EMPLOYEE','/dashboard/employees','VIEW',1,'$CO')"
[ "$(whoami "$JE" | jget 'modules./dashboard/employees')" = "false" ] && ok "override can never grant beyond role ceiling" || bad "ceiling" "granted"
sql "INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES ('ctestoverride000000000003','FINANCE','/dashboard/payroll','APPROVE',0,NULL)"
JF=$(newjar); login "$JF" finance@meridianworks.com "$PW" >/dev/null
[ "$(whoami "$JF" | jget payroll.APPROVE)" = "false" ] && ok "platform payroll-action override applies" || bad "payroll override" "?"
sql "DELETE FROM RolePermission WHERE id LIKE 'ctestoverride%'"

echo "== Session re-validation on every request (A7)"
JU=$(newjar); login "$JU" employee@meridianworks.com "$PW" >/dev/null
sql "UPDATE User SET isActive=0 WHERE email='employee@meridianworks.com'"
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JU" "$BASE/__whoami"); [[ "$code" == 303:*"/login"* ]] && ok "deactivated user logged out on next request" || bad "deactivate" "$code"
sql "UPDATE User SET isActive=1 WHERE email='employee@meridianworks.com'"
JU=$(newjar); login "$JU" employee@meridianworks.com "$PW" >/dev/null
OLD=$(sql "SELECT passwordHash FROM User WHERE email='employee@meridianworks.com'")
sql "UPDATE User SET passwordHash=CONCAT(passwordHash,'') , passwordHash='\$2y\$12\$abcdefghijklmnopqrstuuJ7g4bF1Yb8QpQeD1l9u5q7x6Kc0yZ2W' WHERE email='employee@meridianworks.com'"
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JU" "$BASE/__whoami"); [[ "$code" == 303:*"/login"* ]] && ok "password changed elsewhere → other sessions invalid" || bad "password fingerprint" "$code"
sql "UPDATE User SET passwordHash='$OLD' WHERE email='employee@meridianworks.com'"
JU=$(newjar); login "$JU" employee@meridianworks.com "$PW" >/dev/null
sql "UPDATE Company SET status='SUSPENDED' WHERE id='$CO'"
body=$(curl -s -w '\n%{http_code}' -b "$JU" "$BASE/__whoami" -H 'Accept: text/html')
[ "$(echo "$body" | tail -1)" = "403" ] && echo "$body" | grep -q 'Access suspended' && ok "suspended company blocked on every protected route" || bad "suspended" "$(echo "$body" | tail -1)"
J4=$(newjar); login "$J4" employee@meridianworks.com "$PW" >/dev/null
curl -s -b "$J4" "$BASE/login" | grep -q 'access has been suspended' && ok "suspended company: login refused after correct password" || bad "suspended login" "?"
sql "UPDATE Company SET status='ACTIVE' WHERE id='$CO'"
SID=$(grep hrms_sid "$JU" | awk '{print $7}')
SF="$(cygpath -m "$ROOT/storage/sessions/sess_$SID")"
if [ -f "$SF" ]; then
  php -r '$f=$argv[1]; $s=file_get_contents($f); $s=preg_replace("/login_at\|i:\d+;/", "login_at|i:".(time()-8*86400).";", $s); file_put_contents($f,$s);' "$SF"
  code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JU" "$BASE/__whoami"); [[ "$code" == 303:*"/login"* ]] && ok "login older than 7 days expires" || bad "7-day expiry" "$code"
else bad "7-day expiry" "session file not found"; fi

echo "== Logout"
JL=$(newjar); login "$JL" hr@meridianworks.com "$PW" >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JL" -d "_action=x" "$BASE/logout"); [ "$code" = "403" ] && ok "logout without CSRF refused" || bad "logout csrf" "$code"
T=$(token "$JL" /__selftest)
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JL" -c "$JL" -d "_csrf=$T" "$BASE/logout"); [[ "$code" == 303:*"/login" ]] && ok "logout redirects to /login" || bad "logout" "$code"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JL" "$BASE/__whoami"); [ "$code" = "303" ] && ok "session destroyed after logout" || bad "post-logout" "$code"

echo "== Rate limiting"
rm -f "$ROOT"/storage/cache/ratelimit-login-*.json
JR=$(newjar); for i in $(seq 1 8); do login "$JR" recruiter@meridianworks.com wrong >/dev/null; done
login "$JR" recruiter@meridianworks.com "$PW" >/dev/null
curl -s -b "$JR" "$BASE/login" | grep -q 'Too many sign-in attempts' && ok "9th attempt blocked even with correct password" || bad "rate limit" "not blocked"
rm -f "$ROOT"/storage/cache/ratelimit-login-*.json

echo "== Password reset"
rm -f "$ROOT"/storage/logs/mail-*.log "$ROOT"/storage/cache/ratelimit-reset-*.json
OLD=$(sql "SELECT passwordHash FROM User WHERE email='auditor@meridianworks.com'")
JP=$(newjar); T=$(token "$JP" /forgot-password)
curl -s -o /dev/null -b "$JP" -c "$JP" -d "_action=request&_csrf=$T&email=nobody@nowhere.test" "$BASE/forgot-password"
curl -s -b "$JP" -c "$JP" "$BASE/forgot-password" | grep -q 'Check your email' && ok "unknown email → same 'check your email'" || bad "reset unknown" "?"
[ ! -s "$(ls "$ROOT"/storage/logs/mail-*.log 2>/dev/null | head -1)" ] && ok "no mail sent for unknown email" || bad "unknown email mail" "sent"
T=$(token "$JP" /forgot-password)
curl -s -o /dev/null -b "$JP" -c "$JP" -d "_action=request&_csrf=$T&email=AUDITOR@meridianworks.com" "$BASE/forgot-password"
LINK=$(grep -ho 'http[^ ]*reset-password?token=[a-f0-9]*' "$ROOT"/storage/logs/mail-*.log | tail -1); TOK="${LINK##*token=}"
[ ${#TOK} = 64 ] && ok "reset email generated (local mail log)" || bad "reset email" "$LINK"
stored=$(sql "SELECT token FROM PasswordResetToken ORDER BY createdAt DESC LIMIT 1")
[ "$stored" != "$TOK" ] && [ "$stored" = "$(printf '%s' "$TOK" | sha256sum | cut -d' ' -f1)" ] && ok "only SHA-256 of token stored" || bad "token hashing" "$stored"
curl -s "$BASE/reset-password?token=$TOK" | grep -q 'Choose a new password' && ok "valid link shows form" || bad "reset form" "?"
curl -s "$BASE/reset-password?token=$(printf 'a%.0s' $(seq 1 64))" | grep -q 'invalid or has expired' && ok "bogus token rejected" || bad "bogus token" "?"
JQ=$(newjar); T=$(token "$JQ" "/reset-password?token=$TOK")
curl -s -o /dev/null -b "$JQ" -c "$JQ" -d "_action=reset&_form=reset&_csrf=$T&token=$TOK&password=short&confirmPassword=short" "$BASE/reset-password?token=$TOK"
curl -s -b "$JQ" -c "$JQ" "$BASE/reset-password?token=$TOK" | grep -q 'at least 8 characters' && ok "short password rejected" || bad "short pw" "?"
T=$(token "$JQ" "/reset-password?token=$TOK")
curl -s -o /dev/null -b "$JQ" -c "$JQ" -d "_action=reset&_csrf=$T&token=$TOK&password=NewPass#2026&confirmPassword=NewPass#2026" "$BASE/reset-password?token=$TOK"
curl -s -b "$JQ" -c "$JQ" "$BASE/reset-password" | grep -q 'Password updated' && ok "reset succeeds" || bad "reset" "?"
curl -s "$BASE/reset-password?token=$TOK" | grep -q 'invalid or has expired' && ok "token is single-use" || bad "single use" "reusable"
JN=$(newjar); loc=$(login "$JN" auditor@meridianworks.com 'NewPass#2026' /__whoami); [[ "$loc" == *"/__whoami" ]] && ok "login with new password" || bad "new pw login" "$loc"
sql "UPDATE PasswordResetToken SET expiresAt = NOW() - INTERVAL 1 MINUTE, usedAt = NULL WHERE token='$stored'"
curl -s "$BASE/reset-password?token=$TOK" | grep -q 'invalid or has expired' && ok "expired token rejected" || bad "expiry" "accepted"
sql "UPDATE User SET passwordHash='$OLD' WHERE email='auditor@meridianworks.com'"; sql "DELETE FROM PasswordResetToken"
[ "$(sql "SELECT COUNT(*) FROM AuditLog WHERE action='PASSWORD_RESET'")" -ge 1 ] && ok "PASSWORD_RESET audited" || bad "reset audit" "missing"

echo "== Installer (empty schema DB)"
sed -i "s/^DB_NAME=.*/DB_NAME=hrms/; /^SETUP_KEY=/d" "$ROOT/.env"; rm -f "$ROOT/storage/install.lock" "$ROOT"/storage/cache/ratelimit-setup-*
JI=$(newjar); curl -s -c "$JI" "$BASE/install" | grep -q 'Setup key required' && ok "installer refuses to show the form without SETUP_KEY" || bad "setup key gate" "?"
T=$(token "$JI" /login)
curl -s -o /dev/null -b "$JI" -c "$JI" --data-urlencode "password=Very-Strong#2026" --data-urlencode "confirmPassword=Very-Strong#2026" -d "_action=install&_form=install&_csrf=$T&email=owner@example.com&setupKey=" "$BASE/install"
[ "$(sql "SELECT COUNT(*) FROM User" hrms)" = "0" ] && ok "no admin can be created without SETUP_KEY" || bad "setup key gate" "created"
echo "SETUP_KEY=test-setup-key-0123456789" >> "$ROOT/.env"
curl -s -c "$JI" -b "$JI" "$BASE/install" | grep -q 'Create the Super Admin' && ok "installer available on empty DB with SETUP_KEY" || bad "installer" "?"
T=$(token "$JI" /install)
curl -s -o /dev/null -b "$JI" -c "$JI" --data-urlencode "password=Very-Strong#2026" --data-urlencode "confirmPassword=Very-Strong#2026" -d "_action=install&_form=install&_csrf=$T&email=owner@example.com&setupKey=wrong-key-0123456789" "$BASE/install"
curl -s -b "$JI" -c "$JI" "$BASE/install" | grep -q 'setup key is incorrect' && [ "$(sql "SELECT COUNT(*) FROM User" hrms)" = "0" ] && ok "wrong setup key rejected" || bad "setup key" "?"
T=$(token "$JI" /install)
curl -s -o /dev/null -b "$JI" -c "$JI" -d "_action=install&_form=install&_csrf=$T&email=owner@example.com&password=short&confirmPassword=short&setupKey=test-setup-key-0123456789" "$BASE/install"
curl -s -b "$JI" -c "$JI" "$BASE/install" | grep -q 'at least 12 characters' && ok "weak installer password rejected" || bad "installer pw" "?"
T=$(token "$JI" /install)
code=$(curl -s -o /dev/null -w '%{http_code}:%{redirect_url}' -b "$JI" -c "$JI" --data-urlencode "password=Very-Strong#2026" --data-urlencode "confirmPassword=Very-Strong#2026" -d "_action=install&_csrf=$T&email=owner@example.com&setupKey=test-setup-key-0123456789" "$BASE/install")
[[ "$code" == 303:*"/login" ]] && ok "super admin created → /login" || bad "install" "$code"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install")" = "404" ] && ok "installer locked afterwards (404)" || bad "install lock" "open"
[ -f "$ROOT/storage/install.lock" ] && ok "install.lock written" || bad "lock file" "missing"
[ "$(sql "SELECT role FROM User WHERE email='owner@example.com'" hrms)" = "SUPER_ADMIN" ] && ok "row is SUPER_ADMIN with bcrypt hash" || bad "installer row" "?"
sql "SELECT passwordHash FROM User WHERE email='owner@example.com'" hrms | grep -q '^\$2y\$12\$' && ok "installer password hashed with bcrypt cost 12" || bad "hash" "?"
sql "DELETE FROM AuditLog; DELETE FROM User" hrms; rm -f "$ROOT/storage/install.lock"; sed -i "/^SETUP_KEY=/d" "$ROOT/.env"
sed -i "s/^DB_NAME=.*/DB_NAME=$DBNAME/" "$ROOT/.env"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
