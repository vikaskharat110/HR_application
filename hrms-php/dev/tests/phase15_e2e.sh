#!/usr/bin/env bash
# DEV ONLY — Phase 15 end-to-end journey on a FRESH install: a throwaway
# `hrms_e2e` database built from database/hrms.sql only (no demo data),
# exactly what a new Hostinger deployment starts from. Restores .env after.
#   bash hrms-php/dev/tests/phase15_e2e.sh
set -u
export MSYS_NO_PATHCONV=1
DBC="${DBC:-hrms-mariadb11}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cp "$ROOT/.env" "$ROOT/.env.e2e-backup"
restore() { mv -f "$ROOT/.env.e2e-backup" "$ROOT/.env"; rm -f "$ROOT/storage/install.lock"; }
trap restore EXIT

docker exec "$DBC" mariadb -uroot -prootpw -e "DROP DATABASE IF EXISTS hrms_e2e; CREATE DATABASE hrms_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null
docker exec -i "$DBC" mariadb -uroot -prootpw hrms_e2e < "$ROOT/database/hrms.sql" 2>/dev/null
sed -i 's/^DB_NAME=.*/DB_NAME=hrms_e2e/; /^SETUP_KEY=/d' "$ROOT/.env"
echo "SETUP_KEY=e2e-setup-key-0123456789abcdef" >> "$ROOT/.env"
rm -f "$ROOT/storage/install.lock" "$ROOT"/storage/cache/ratelimit-*
php "$(cygpath -m "$ROOT")/dev/tests/phase15_e2e.php"
