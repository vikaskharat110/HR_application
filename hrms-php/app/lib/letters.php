<?php
/**
 * Letter generation — port of lib/letters.ts.
 *
 * Templates (LetterTemplate) hold a subject + body with {{placeholder}}
 * merge fields. Generation fills the company's uploaded .docx template if
 * there is one, otherwise draws the built-in PDF (letterhead + subject +
 * body, letterhead repeated on every page). A broken .docx never blocks the
 * HR workflow: it falls back to the PDF, as the original did. The result is
 * stored as an employee Document (storage/uploads/<co>/employee-documents).
 *
 * PDF uses tFPDF with embedded DejaVu Sans, so "₹" and non-Latin names
 * render correctly (the original's PDFKit Helvetica could not draw ₹).
 */
declare(strict_types=1);

const LETTER_TYPES = ['OFFER', 'APPOINTMENT', 'CONFIRMATION', 'PROMOTION', 'RELIEVING', 'EXPERIENCE'];
const DOCUMENT_TYPE_FOR_LETTER = [
    'OFFER' => 'OFFER_LETTER', 'APPOINTMENT' => 'APPOINTMENT_LETTER', 'CONFIRMATION' => 'CONFIRMATION_LETTER',
    'PROMOTION' => 'PROMOTION_LETTER', 'RELIEVING' => 'RELIEVING_LETTER', 'EXPERIENCE' => 'EXPERIENCE_LETTER',
];
const LETTER_LABEL = [
    'OFFER' => 'Offer letter', 'APPOINTMENT' => 'Appointment letter', 'CONFIRMATION' => 'Confirmation letter',
    'PROMOTION' => 'Promotion letter', 'RELIEVING' => 'Relieving letter', 'EXPERIENCE' => 'Experience letter',
];

/** The {{merge fields}} every template can use (same names as the original). */
function letter_merge_fields(string $employeeId): array
{
    $e = db_one(
        'SELECT e.*, c.name AS companyName, d.name AS departmentName, b.name AS branchName,
                m.firstName AS mFirst, m.lastName AS mLast,
                s.basic, s.hra, s.conveyance, s.medicalAllowance, s.specialAllowance, s.id AS salaryId
           FROM Employee e
           JOIN Company c ON c.id = e.companyId
           LEFT JOIN Department d ON d.id = e.departmentId
           LEFT JOIN Branch b ON b.id = e.branchId
           LEFT JOIN Employee m ON m.id = e.managerId
           LEFT JOIN SalaryStructure s ON s.employeeId = e.id
          WHERE e.id = ?',
        [$employeeId]
    );
    if ($e === null) {
        throw new RuntimeException('Employee not found for letter');
    }
    $ctc = $e['salaryId'] ? ($e['basic'] + $e['hra'] + $e['conveyance'] + $e['medicalAllowance'] + $e['specialAllowance']) * 12 : null;
    return [
        'employeeName' => $e['firstName'] . ' ' . $e['lastName'],
        'firstName' => $e['firstName'],
        'lastName' => $e['lastName'],
        'employeeCode' => $e['employeeCode'],
        'designation' => $e['designation'],
        'department' => $e['departmentName'] ?? DASH,
        'branch' => $e['branchName'] ?? DASH,
        'manager' => $e['mFirst'] ? $e['mFirst'] . ' ' . $e['mLast'] : DASH,
        'dateOfJoining' => format_date_long($e['dateOfJoining']),
        'dateOfExit' => format_date_long($e['dateOfExit']),
        'companyName' => $e['companyName'],
        'ctc' => $ctc !== null ? '₹' . format_in($ctc) . ' per annum' : DASH,
        'today' => format_date_long(now()),
    ];
}

/** {{placeholder}} substitution (unknown fields → ""). Plain text — callers escape for HTML. */
function letter_substitute(string $template, array $fields): string
{
    return (string)preg_replace_callback('/\{\{(\w+)\}\}/', static fn($m) => (string)($fields[$m[1]] ?? ''), $template);
}

function normalize_line_endings(string $s): string
{
    return str_replace(["\r\n", "\r"], "\n", $s);
}

/** Renders a company letter template for an employee: [subject, body, type] as plain text. */
function render_letter(array $template, string $employeeId): array
{
    $fields = letter_merge_fields($employeeId);
    return [
        'subject' => letter_substitute($template['subject'], $fields),
        'body' => letter_substitute($template['bodyHtml'], $fields),
        'type' => $template['type'],
    ];
}

/**
 * Generates a letter for $employeeId from the company's template of $type,
 * stores it as a Document and returns the new document id — or null when the
 * company has no template for that type (same contract as the original).
 */
function generate_letter_for_employee(string $companyId, string $employeeId, string $type): ?string
{
    $template = db_one('SELECT * FROM LetterTemplate WHERE companyId = ? AND type = ?', [$companyId, $type]);
    if ($template === null) {
        return null;
    }
    $fields = letter_merge_fields($employeeId);
    $subject = letter_substitute($template['subject'], $fields);
    $body = letter_substitute($template['bodyHtml'], $fields);

    $ref = null;
    if ($template['templateFileUrl']) {
        $path = resolve_stored_file($template['templateFileUrl'], $companyId);
        try {
            if ($path === null) {
                throw new RuntimeException('Uploaded Word template file is missing');
            }
            $ref = store_generated(docx_fill_template($path, $fields), $companyId, 'employee-documents', 'docx');
        } catch (Throwable $err) {
            // A broken uploaded template must never block the underlying HR
            // workflow — fall back to the built-in PDF layout.
            log_error($err, ['letterTemplate' => $template['id']]);
        }
    }
    if ($ref === null) {
        $ref = store_generated(render_letter_pdf($companyId, $subject, $body), $companyId, 'employee-documents', 'pdf');
    }

    return db_insert('Document', [
        'companyId' => $companyId,
        'employeeId' => $employeeId,
        'name' => mb_substr($subject !== '' ? $subject : $type . ' letter', 0, 255),
        'type' => DOCUMENT_TYPE_FOR_LETTER[$type],
        'fileUrl' => $ref,
    ]);
}

// ───────────────────────────────────────────── PDF (tFPDF) ───

/**
 * Fonts + tFPDF metric caches live in storage/cache/fonts (writable, built
 * on the server itself). tFPDF bakes the absolute .ttf path into each cache,
 * so caches whose path no longer exists (site moved) are discarded.
 */
function pdf_font_dir(): string
{
    $dir = STORAGE_PATH . '/cache/fonts/';
    $uni = $dir . 'unifont/';
    if (!is_dir($uni)) {
        mkdir($uni, 0750, true);
    }
    $src = APP_PATH . '/vendor/tfpdf/font/unifont/';
    foreach (['ttfonts.php', 'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'] as $f) {
        if (!is_file($uni . $f) || filesize($uni . $f) !== filesize($src . $f)) {
            copy($src . $f, $uni . $f);
        }
    }
    foreach (glob($uni . '*.mtx.php') ?: [] as $mtx) {
        $cached = (static function (string $file): ?string {
            $ttffile = null;
            include $file;
            return $ttffile;
        })($mtx);
        if (!is_string($cached) || !is_file($cached)) {
            $stem = substr($mtx, 0, -8);
            @unlink($mtx);
            @unlink($stem . '.cw.dat');
            @unlink($stem . '.cw127.php');
        }
    }
    return $dir;
}

function pdf_new(): tFPDF
{
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', pdf_font_dir());
    }
    require_once APP_PATH . '/vendor/tfpdf/tfpdf.php';
    $pdf = new tFPDF('P', 'pt', 'A4');
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
    $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
    return $pdf;
}

/** Hex "#1f4d3a" → [r,g,b] */
function pdf_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Built-in letter layout: logo/name + address block + rule, subject, body. */
function render_letter_pdf(string $companyId, string $subject, string $bodyText): string
{
    $company = db_one('SELECT name, logoUrl, address, phone, panNumber FROM Company WHERE id = ?', [$companyId]) ?? ['name' => '', 'logoUrl' => null, 'address' => null, 'phone' => null, 'panNumber' => null];
    $logo = null;
    $logoPath = resolve_stored_file($company['logoUrl'], $companyId);
    if ($logoPath !== null && in_array(strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) {
        $logo = $logoPath; // tFPDF draws PNG/JPEG; other formats fall back to the company name
    }
    $addressLines = array_values(array_filter([
        $company['address'] ? normalize_line_endings($company['address']) : null,
        $company['panNumber'] ? 'PAN No: ' . $company['panNumber'] : null,
        $company['phone'] ? 'Phone No: ' . $company['phone'] : null,
    ]));

    $pdf = pdf_new();
    $pdf->SetMargins(56, 56, 56);
    $pdf->SetAutoPageBreak(true, 56);
    $drawLetterhead = static function () use ($pdf, $company, $logo, $addressLines): void {
        $top = 56;
        $below = $top + 20;
        $drewLogo = false;
        if ($logo !== null) {
            try {
                $pdf->Image($logo, 56, $top, 0, 40);
                $drewLogo = true;
                $below = $top + 40;
            } catch (Throwable) {
                $drewLogo = false;
            }
        }
        if (!$drewLogo) {
            $pdf->SetFont('DejaVu', 'B', 16);
            $pdf->SetTextColor(...pdf_rgb('#1f4d3a'));
            $pdf->SetXY(56, $top);
            $pdf->Cell(240, 20, $company['name']);
        }
        if ($addressLines) {
            $pdf->SetFont('DejaVu', '', 9);
            $pdf->SetTextColor(...pdf_rgb('#444444'));
            $pdf->SetXY(300, $top);
            $pdf->MultiCell(239, 12, implode("\n", $addressLines), 0, 'R');
            $below = max($below, $pdf->GetY());
        }
        $y = $below + 12;
        $pdf->SetDrawColor(...pdf_rgb('#1f4d3a'));
        $pdf->SetLineWidth(2);
        $pdf->Line(56, $y, 539, $y);
        $pdf->SetXY(56, $y + 18);
    };

    $pdf->AddPage();
    $drawLetterhead();
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->SetTextColor(...pdf_rgb('#1a1a1a'));
    $pdf->MultiCell(483, 17, normalize_line_endings($subject), 0, 'L');
    $pdf->Ln(13);
    $pdf->SetFont('DejaVu', '', 11);
    // Body: paginate manually so the letterhead is redrawn on every page.
    $pdf->SetAutoPageBreak(false);
    $lineHeight = 17; // 11pt + ~4pt line gap, as the original
    foreach (explode("\n", normalize_line_endings($bodyText)) as $para) {
        foreach (pdf_wrap($pdf, $para, 483) as $line) {
            if ($pdf->GetY() + $lineHeight > 841.89 - 56) {
                $pdf->AddPage();
                $drawLetterhead();
                $pdf->SetFont('DejaVu', '', 11);
                $pdf->SetTextColor(...pdf_rgb('#1a1a1a'));
            }
            $pdf->SetX(56);
            $pdf->Cell(483, $lineHeight, $line, 0, 1, 'L');
        }
    }
    return $pdf->Output('S');
}

/** Greedy word wrap using the current font's metrics. An empty paragraph yields one blank line. */
function pdf_wrap(tFPDF $pdf, string $text, float $width): array
{
    if (trim($text) === '') {
        return [''];
    }
    $lines = [];
    $line = '';
    foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
        $candidate = $line . $piece;
        if ($line !== '' && $pdf->GetStringWidth(rtrim($candidate)) > $width) {
            $lines[] = rtrim($line);
            $line = ltrim($piece);
            while ($pdf->GetStringWidth($line) > $width) { // one very long word
                $cut = mb_strlen($line);
                while ($cut > 1 && $pdf->GetStringWidth(mb_substr($line, 0, $cut)) > $width) {
                    $cut--;
                }
                $lines[] = mb_substr($line, 0, $cut);
                $line = mb_substr($line, $cut);
            }
        } else {
            $line = $candidate;
        }
    }
    if (trim($line) !== '') {
        $lines[] = rtrim($line);
    }
    return $lines;
}
