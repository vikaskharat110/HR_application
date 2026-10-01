<?php
/**
 * CSV parsing/writing — port of lib/csv.ts and lib/employeeCsv.ts.
 */
declare(strict_types=1);

/** RFC 4180-style parser: quoted fields, embedded commas/quotes ("") and newlines. */
function parse_csv(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // Excel's UTF-8 BOM
    $src = str_replace(["\r\n", "\r"], "\n", $text);
    $rows = [];
    $row = [];
    $field = '';
    $inQuotes = false;
    $len = strlen($src);
    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        if ($inQuotes) {
            if ($c === '"') {
                if (($src[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuotes = false;
                }
            } else {
                $field .= $c;
            }
        } elseif ($c === '"') {
            $inQuotes = true;
        } elseif ($c === ',') {
            $row[] = $field;
            $field = '';
        } elseif ($c === "\n") {
            $row[] = $field;
            $rows[] = $row;
            $row = [];
            $field = '';
        } else {
            $field .= $c;
        }
    }
    if ($field !== '' || $row !== []) {
        $row[] = $field;
        $rows[] = $row;
    }
    return array_values(array_filter($rows, static fn($r) => !(count($r) === 1 && trim($r[0]) === '')));
}

/**
 * Cell escaping. Also neutralises spreadsheet formula injection (a value
 * starting with = + - @ tab/CR is prefixed with ') — hardening over the
 * original, which exported raw values.
 */
function csv_cell(string|int|float|null $value): string
{
    $v = (string)($value ?? '');
    if ($v !== '' && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
        $v = "'" . $v;
    }
    return preg_match('/[",\n\r]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

function to_csv(array $rows): string
{
    return implode("\r\n", array_map(static fn($r) => implode(',', array_map('csv_cell', $r)), $rows));
}

function send_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: private, no-store');
    echo to_csv($rows);
    exit;
}

// ───────────────────────────────────────── Employee CSV (import/export) ───

/** The single column schema shared by export, template and import. */
const EMPLOYEE_CSV_HEADERS = [
    'Employee Code', 'First Name', 'Last Name', 'Email', 'Phone', 'Gender', 'Date of Birth', 'Designation',
    'Department', 'Branch', 'Manager Employee Code', 'Employment Type', 'Date of Joining',
    'Bank Account Number', 'Bank IFSC', 'Bank Name', 'Status (reference only, not imported)',
];

const EMPLOYEE_CSV_TEMPLATE_EXAMPLE = [
    '', 'Asha', 'Verma', 'asha.verma@example.com', '9876543210', 'FEMALE', '1996-04-12', 'Software Engineer',
    'Engineering', 'Pune HQ', '', 'FULL_TIME', '2026-09-15', '', '', '', '',
];

function iso_date(?string $d): string
{
    return $d ? dt($d)->format('Y-m-d') : '';
}

/** @param array $e employee row joined with department/branch names and manager code */
function employee_to_row(array $e): array
{
    return [
        $e['employeeCode'], $e['firstName'], $e['lastName'], $e['email'], $e['phone'] ?? '', $e['gender'] ?? '',
        iso_date($e['dateOfBirth']), $e['designation'], $e['department'] ?? '', $e['branch'] ?? '',
        $e['managerCode'] ?? '', $e['employmentType'], iso_date($e['dateOfJoining']),
        $e['bankAccountNumber'] ?? '', $e['bankIfsc'] ?? '', $e['bankName'] ?? '', $e['status'],
    ];
}
