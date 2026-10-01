<?php
/**
 * Statutory deductions — port of lib/statutory.ts. Company-configurable
 * PF/ESI/PT/TDS (BRD FR-061), created with Indian defaults on first use.
 */
declare(strict_types=1);

const DEFAULT_PT_SLABS = [['upto' => 15000, 'amount' => 0], ['upto' => 25000, 'amount' => 150], ['upto' => null, 'amount' => 200]];
const DEFAULT_TDS_SLABS = [['upto' => 600000, 'monthlyDeduction' => 0], ['upto' => 1200000, 'monthlyDeduction' => 2500], ['upto' => null, 'monthlyDeduction' => 8000]];

function get_statutory_config(string $companyId): array
{
    $row = db_one('SELECT * FROM StatutoryConfig WHERE companyId = ?', [$companyId]);
    if ($row !== null) {
        return $row;
    }
    db_exec(
        'INSERT IGNORE INTO StatutoryConfig (id, companyId, pfEmployeeRate, pfEmployerRate, pfWageCeiling, esiEmployeeRate, esiEmployerRate, esiWageCeiling, ptSlabs, tdsSlabs)
         VALUES (?, ?, 0.12, 0.12, 15000, 0.0075, 0.0325, 21000, ?, ?)',
        [new_id(), $companyId, json_encode(DEFAULT_PT_SLABS), json_encode(DEFAULT_TDS_SLABS)]
    );
    return db_one('SELECT * FROM StatutoryConfig WHERE companyId = ?', [$companyId]);
}

/** First slab whose "upto" covers the amount (null = no upper bound). */
function slab_lookup(float $amount, mixed $slabsJson): float
{
    $rows = is_string($slabsJson) ? json_decode($slabsJson, true) : $slabsJson;
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['upto'] ?? null) === null || $amount <= (float)$row['upto']) {
            return (float)($row['amount'] ?? $row['monthlyDeduction'] ?? 0);
        }
    }
    return 0.0;
}

/**
 * PF/ESI/PT/TDS from monthly basic using the company's rates: PF on basic
 * capped at the wage ceiling; ESI only at or below its ceiling; PT by monthly
 * basic slab; TDS by annualised basic slab. Math.round semantics preserved.
 */
function compute_statutory_deductions(string $companyId, float $basic): array
{
    $cfg = get_statutory_config($companyId);
    $pfWage = min($basic, (float)$cfg['pfWageCeiling']);
    return [
        'pfEmployee' => js_round($pfWage * (float)$cfg['pfEmployeeRate']),
        'esi' => $basic <= (float)$cfg['esiWageCeiling'] ? js_round($basic * (float)$cfg['esiEmployeeRate']) : 0.0,
        'professionalTax' => slab_lookup($basic, $cfg['ptSlabs']),
        'tds' => slab_lookup($basic * 12, $cfg['tdsSlabs']),
    ];
}
