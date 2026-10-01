#!/usr/bin/env python3
"""
Development-time tool (never needed on the server).

Generates, from the Next.js app's Prisma schema (the source of truth):

  database/hrms.sql            MySQL/MariaDB schema: 43 tables, PKs, FKs,
                               unique constraints, indexes, ENUMs, defaults.
  database/hrms_demo_data.sql  Optional demo data, converted from the
                               existing SQLite database (opened READ-ONLY).

Usage (from the repository root):
    python hrms-php/database/tools/build_sql.py

Type mapping (mirrors what Prisma itself generates for provider = "mysql",
with the documented deviations marked *):
    String @id / FK / short text   VARCHAR(191)
    String long free text        * TEXT / MEDIUMTEXT  (Prisma default VARCHAR(191) would truncate)
    Int                            INT
    Float                          DOUBLE   (kept: money behaviour identical to the original)
    Boolean                        TINYINT(1)
    DateTime                       DATETIME(3)  (wall-clock Asia/Kolkata)
    Json                           JSON
    enum                           ENUM(...)
    @updatedAt                   * DEFAULT/ON UPDATE CURRENT_TIMESTAMP(3) (app also sets it)
"""
from __future__ import annotations

import datetime as dt
import json
import re
import sqlite3
import sys
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[3]          # repository root
SCHEMA = ROOT / "prisma" / "schema.prisma"
SQLITE = ROOT / "prisma" / "dev.db"
OUT_DIR = ROOT / "hrms-php" / "database"
TZ = ZoneInfo("Asia/Kolkata")

# Free-text columns that must not be limited to 191 characters.
TEXT_FIELDS = {
    ("Company", "address"), ("Company", "logoUrl"),
    ("PromotionTransfer", "reason"), ("PromotionTransfer", "comments"),
    ("Employee", "salaryHoldReason"),
    ("AttendanceRegularization", "reason"),
    ("LeaveRequest", "reason"), ("LeaveRequest", "comments"),
    ("PayrollRun", "reopenReason"), ("Payslip", "holdReason"),
    ("JobPosting", "description"),
    ("Candidate", "notes"), ("Candidate", "resumeUrl"),
    ("Interview", "feedback"),
    ("Goal", "description"),
    ("PerformanceReview", "comments"), ("PerformanceReview", "selfComments"),
    ("ExpenseClaim", "description"), ("ExpenseClaim", "comments"), ("ExpenseClaim", "receiptUrl"),
    ("Document", "fileUrl"),
    ("Ticket", "description"), ("TicketComment", "message"),
    ("Notification", "message"), ("Notification", "link"),
    ("LetterTemplate", "subject"), ("LetterTemplate", "templateFileUrl"), ("LetterTemplate", "templateFileName"),
    ("EmployeeExit", "reason"), ("EmployeeExit", "exitInterviewNotes"),
    ("ExitClearance", "remarks"),
    ("Confirmation", "managerRecommendation"), ("Confirmation", "comments"),
}
MEDIUMTEXT_FIELDS = {("LetterTemplate", "bodyHtml")}
# Non-indexed display strings that can reasonably exceed 191 chars.
VARCHAR255_FIELDS = {("Document", "name"), ("Ticket", "subject"), ("Notification", "title")}

# Demo-data only: columns that hold a calendar date. The seed stored them with
# the seed run's clock time; the app keys them by local midnight.
NORMALIZE_TO_MIDNIGHT = {("Attendance", "date"), ("AttendanceRegularization", "date")}

SCALARS = {"String", "Int", "Float", "Boolean", "DateTime", "Json"}


# ───────────────────────────────────────────── Prisma schema parser ───

def strip_comments(src: str) -> str:
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    return "\n".join(line.split("//")[0] for line in src.splitlines())


def parse_schema(src: str):
    src = strip_comments(src)
    enums = {m.group(1): m.group(2).split()
             for m in re.finditer(r"enum\s+(\w+)\s*\{([^}]*)\}", src)}
    models = {}
    for m in re.finditer(r"model\s+(\w+)\s*\{([^}]*)\}", src):
        name, body = m.group(1), m.group(2)
        fields, relations, uniques, indexes = [], [], [], []
        for raw in body.splitlines():
            line = raw.strip()
            if not line:
                continue
            if line.startswith("@@unique"):
                uniques.append(re.findall(r"\w+", line.split("[", 1)[1].split("]")[0]))
                continue
            if line.startswith("@@index"):
                indexes.append(re.findall(r"\w+", line.split("[", 1)[1].split("]")[0]))
                continue
            parts = line.split(None, 2)
            fname, ftype = parts[0], parts[1]
            attrs = parts[2] if len(parts) > 2 else ""
            is_list = ftype.endswith("[]")
            optional = ftype.endswith("?")
            base = ftype.rstrip("?").rstrip("[]")
            if is_list:
                continue  # back-relation list
            if base not in SCALARS and base not in enums:
                rel = re.search(r"@relation\(([^)]*)\)", attrs)
                if rel and "fields:" in rel.group(1):
                    body_r = rel.group(1)
                    cols = re.findall(r"\w+", body_r.split("fields:")[1].split("]")[0])
                    refs = re.findall(r"\w+", body_r.split("references:")[1].split("]")[0])
                    od = re.search(r"onDelete:\s*(\w+)", body_r)
                    relations.append({"cols": cols, "target": base, "refs": refs,
                                      "onDelete": od.group(1) if od else None})
                continue  # other side of a 1:1 / back-relation
            fields.append({"name": fname, "type": base, "optional": optional, "attrs": attrs})
        models[name] = {"fields": fields, "relations": relations, "uniques": uniques, "indexes": indexes}
    return enums, models


# ───────────────────────────────────────────── DDL generation ───

def sql_type(model: str, f: dict, enums: dict) -> str:
    t = f["type"]
    if t in enums:
        return "ENUM(" + ",".join(f"'{v}'" for v in enums[t]) + ")"
    if t == "String":
        key = (model, f["name"])
        if key in MEDIUMTEXT_FIELDS:
            return "MEDIUMTEXT"
        if key in TEXT_FIELDS:
            return "TEXT"
        if key in VARCHAR255_FIELDS:
            return "VARCHAR(255)"
        return "VARCHAR(191)"
    return {"Int": "INT", "Float": "DOUBLE", "Boolean": "TINYINT(1)",
            "DateTime": "DATETIME(3)", "Json": "JSON"}[t]


def sql_default(f: dict, enums: dict) -> str | None:
    attrs = f["attrs"]
    if "@updatedAt" in attrs:
        return "CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)"
    m = re.search(r"@default\((.*)\)", attrs)
    if not m:
        return None
    val = m.group(1).strip()
    # trim anything after the matching paren of @default(...)
    depth, out = 0, ""
    for ch in val:
        if ch == "(":
            depth += 1
        elif ch == ")":
            if depth == 0:
                break
            depth -= 1
        out += ch
    val = out.strip()
    if val in ("cuid()", "uuid()"):
        return None  # generated by the application
    if val == "now()":
        return "CURRENT_TIMESTAMP(3)"
    if f["type"] == "Json":
        return None  # JSON columns cannot portably carry a literal default; the app always supplies one
    if f["type"] == "Boolean":
        return "1" if val == "true" else "0"
    if f["type"] in enums:
        return f"'{val}'"
    if val.startswith('"'):
        return "'" + json.loads(val).replace("'", "''") + "'"
    return val


def fk_actions(model: dict, rel: dict) -> str:
    on_delete = rel["onDelete"]
    if on_delete is None:
        col = next(f for f in model["fields"] if f["name"] == rel["cols"][0])
        on_delete = "SetNull" if col["optional"] else "Restrict"
    action = {"Cascade": "CASCADE", "SetNull": "SET NULL", "Restrict": "RESTRICT",
              "NoAction": "NO ACTION"}[on_delete]
    return f"ON DELETE {action} ON UPDATE CASCADE"


def build_schema(enums: dict, models: dict) -> str:
    out = [
        "-- ============================================================================",
        "-- WorkNest — MySQL / MariaDB schema",
        "-- Generated by database/tools/build_sql.py from prisma/schema.prisma.",
        "--",
        "-- Import into an EMPTY database created in Hostinger hPanel (phpMyAdmin →",
        "-- Import). This file never drops or overwrites anything: if a table already",
        "-- exists the import stops with an error instead of destroying data.",
        "--",
        "-- Requirements: MySQL 8.0+ or MariaDB 10.6+ (InnoDB, utf8mb4).",
        "-- Dates are stored as Asia/Kolkata wall-clock time (the app sets the",
        "-- connection time zone to +05:30).",
        "-- ============================================================================",
        "",
        "SET NAMES utf8mb4;",
        "SET time_zone = '+05:30';",
        "SET FOREIGN_KEY_CHECKS = 0;",
        "",
    ]
    fks = []
    for name, model in models.items():
        cols, keys = [], []
        for f in model["fields"]:
            col = f"  `{f['name']}` {sql_type(name, f, enums)}"
            col += " NULL" if f["optional"] else " NOT NULL"
            d = sql_default(f, enums)
            if d is not None:
                col += f" DEFAULT {d}"
            cols.append(col)
            if "@unique" in f["attrs"]:
                keys.append(f"  UNIQUE KEY `{name}_{f['name']}_key` (`{f['name']}`)")
        pk = next(f["name"] for f in model["fields"] if "@id" in f["attrs"])
        keys.insert(0, f"  PRIMARY KEY (`{pk}`)")
        for u in model["uniques"]:
            keys.append(f"  UNIQUE KEY `{name}_{'_'.join(u)}_key` (" + ", ".join(f"`{c}`" for c in u) + ")")
        for ix in model["indexes"]:
            keys.append(f"  KEY `{name}_{'_'.join(ix)}_idx` (" + ", ".join(f"`{c}`" for c in ix) + ")")
        # Every FK column gets its own index, exactly as Prisma does for MySQL.
        indexed_first_cols = {k for k in [pk] + [u[0] for u in model["uniques"]] + [ix[0] for ix in model["indexes"]]}
        indexed_first_cols |= {f["name"] for f in model["fields"] if "@unique" in f["attrs"]}
        for rel in model["relations"]:
            c = rel["cols"][0]
            if c not in indexed_first_cols:
                keys.append(f"  KEY `{name}_{c}_fkey` (`{c}`)")
                indexed_first_cols.add(c)
            fks.append(
                f"ALTER TABLE `{name}` ADD CONSTRAINT `{name}_{c}_fkey` FOREIGN KEY (`{c}`) "
                f"REFERENCES `{rel['target']}` (`{rel['refs'][0]}`) {fk_actions(model, rel)};"
            )
        out.append(f"CREATE TABLE `{name}` (")
        out.append(",\n".join(cols + keys))
        out.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;")
        out.append("")
    out.append("-- ─────────────────────────────────────────── Foreign keys ───")
    out.extend(fks)
    out += ["", "SET FOREIGN_KEY_CHECKS = 1;", ""]
    return "\n".join(out)


# ───────────────────────────────────────────── Demo data ───

REBRAND = [("Verdant HR", "WorkNest"), ("verdanthr.io", "worknest.io")]


def sql_literal(value, f: dict, table: str) -> str:
    if value is None:
        return "NULL"
    t = f["type"]
    if t == "DateTime":
        if isinstance(value, (int, float)):
            # timedelta arithmetic instead of fromtimestamp(): Windows rejects pre-1970 values (birth dates).
            d = (dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc) + dt.timedelta(milliseconds=value)).astimezone(TZ)
        else:  # ISO string fallback
            d = dt.datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone(TZ)
        if (table, f["name"]) in NORMALIZE_TO_MIDNIGHT:
            d = d.replace(hour=0, minute=0, second=0, microsecond=0)
        return "'" + d.strftime("%Y-%m-%d %H:%M:%S.") + f"{d.microsecond // 1000:03d}'"
    if t == "Boolean":
        return "1" if value else "0"
    if t in ("Int", "Float"):
        return repr(value) if isinstance(value, float) else str(value)
    s = str(value)
    for old, new in REBRAND:  # the source data predates the WorkNest rename
        s = s.replace(old, new)
    if t == "Json":
        json.loads(s)  # must be valid JSON
    return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"


def build_demo(models: dict) -> tuple[str, list[str]]:
    con = sqlite3.connect(f"file:{SQLITE.as_posix()}?mode=ro", uri=True)
    problems = []
    out = [
        "-- ============================================================================",
        "-- WorkNest — OPTIONAL demo data (development/testing ONLY).",
        "-- Converted READ-ONLY from prisma/dev.db by database/tools/build_sql.py.",
        "--",
        "-- NEVER import this into production: every demo account uses the public",
        "-- password  Passw0rd!  (e.g. superadmin@worknest.io, admin@meridianworks.com).",
        "--",
        "-- Import AFTER hrms.sql, into an otherwise empty database.",
        "-- Conversions: timestamps → Asia/Kolkata DATETIME(3); Attendance.date and",
        "-- AttendanceRegularization.date normalised to local midnight (the seed had",
        "-- stored the seed run's clock time on these date-only columns).",
        "-- ============================================================================",
        "",
        "SET NAMES utf8mb4;",
        "SET time_zone = '+05:30';",
        "SET FOREIGN_KEY_CHECKS = 0;",
        "START TRANSACTION;",
        "",
    ]
    total = 0
    for name, model in models.items():
        sqlite_cols = [r[1] for r in con.execute(f'PRAGMA table_info("{name}")')]
        model_cols = [f["name"] for f in model["fields"]]
        if set(sqlite_cols) != set(model_cols):
            problems.append(f"{name}: sqlite={sorted(set(sqlite_cols) ^ set(model_cols))}")
        rows = con.execute(
            f'SELECT {", ".join(chr(34) + c + chr(34) for c in model_cols)} FROM "{name}"'
        ).fetchall()
        if not rows:
            continue
        total += len(rows)
        out.append(f"-- {name}: {len(rows)} row(s)")
        col_list = ", ".join(f"`{c}`" for c in model_cols)
        for i in range(0, len(rows), 200):
            values = [
                "(" + ", ".join(sql_literal(v, f, name) for v, f in zip(r, model["fields"])) + ")"
                for r in rows[i:i + 200]
            ]
            out.append(f"INSERT INTO `{name}` ({col_list}) VALUES\n" + ",\n".join(values) + ";")
        out.append("")
    con.close()
    out += ["COMMIT;", "SET FOREIGN_KEY_CHECKS = 1;", f"-- {total} rows total", ""]
    return "\n".join(out), problems


def main() -> int:
    enums, models = parse_schema(SCHEMA.read_text(encoding="utf-8"))
    schema_sql = build_schema(enums, models)
    demo_sql, problems = build_demo(models)
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUT_DIR / "hrms.sql").write_text(schema_sql, encoding="utf-8", newline="\n")
    (OUT_DIR / "hrms_demo_data.sql").write_text(demo_sql, encoding="utf-8", newline="\n")
    fk_count = schema_sql.count("FOREIGN KEY")
    print(f"tables={len(models)} enums={len(enums)} foreign_keys={fk_count}")
    if problems:
        print("COLUMN MISMATCH between schema.prisma and dev.db:")
        for p in problems:
            print("  ", p)
        return 1
    print("schema.prisma and dev.db columns match for every table")
    return 0


if __name__ == "__main__":
    sys.exit(main())
