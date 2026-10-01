#!/usr/bin/env bash
# DEV ONLY — recreates the throwaway `hrms_test` database (schema + demo data)
# in the MariaDB test container and points hrms-php/.env at it.
# Never touches prisma/dev.db.
set -eu
export MSYS_NO_PATHCONV=1
DBC="${DBC:-hrms-mariadb11}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
docker exec "$DBC" mariadb -uroot -prootpw -e "DROP DATABASE IF EXISTS hrms_test; CREATE DATABASE hrms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null
docker exec -i "$DBC" mariadb -uroot -prootpw hrms_test < "$ROOT/database/hrms.sql" 2>/dev/null
docker exec -i "$DBC" mariadb -uroot -prootpw hrms_test < "$ROOT/database/hrms_demo_data.sql" 2>/dev/null
sed -i 's/^DB_NAME=.*/DB_NAME=hrms_test/' "$ROOT/.env"
rm -rf "$ROOT"/storage/uploads/c* "$ROOT"/storage/cache/ratelimit-* "$ROOT"/storage/logs/mail-*.log
echo "hrms_test ready"
