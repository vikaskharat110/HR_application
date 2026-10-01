<?php
/**
 * Word (.docx) template filling — replaces docxtemplater + pizzip with
 * PHP's ZipArchive + DOM. Fills {{placeholder}} merge fields in the body,
 * headers and footers; unknown placeholders become "" (docxtemplater's
 * nullGetter behaviour).
 *
 * Word often splits "{{employeeName}}" across several <w:t> runs (spell
 * check, formatting). Within each paragraph the run texts are joined, the
 * placeholders are located in the joined text, and the replacement is
 * written into the first run of each match while the rest of the match is
 * removed from the following runs — so formatting of the first run is kept.
 * Values are set through the DOM, so they are XML-escaped automatically.
 */
declare(strict_types=1);

const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/** @return string the filled .docx bytes */
function docx_fill_template(string $templatePath, array $fields): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    if ($tmp === false || !copy($templatePath, $tmp)) {
        throw new RuntimeException('Could not prepare Word template');
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Word template is not a valid .docx');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', (string)$name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false || !str_contains($xml, '{')) {
                continue;
            }
            $zip->addFromString($name, docx_fill_xml($xml, $fields));
        }
        $zip->close();
        return (string)file_get_contents($tmp);
    } finally {
        @unlink($tmp);
    }
}

function docx_fill_xml(string $xml, array $fields): string
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Word template XML could not be parsed');
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W_NS);
    foreach ($xp->query('//w:p') as $p) {
        /** @var list<DOMElement> $nodes */
        $nodes = iterator_to_array($xp->query('.//w:t', $p));
        if (!$nodes) {
            continue;
        }
        $full = '';
        $starts = [];
        foreach ($nodes as $k => $n) {
            $starts[$k] = strlen($full);
            $full .= $n->textContent;
        }
        if (!preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $full, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            continue;
        }
        $texts = array_map(static fn($n) => $n->textContent, $nodes);
        $ends = [];
        foreach ($texts as $k => $t) {
            $ends[$k] = $starts[$k] + strlen($t); // original run bounds (texts change as we replace)
        }
        // Right-to-left so earlier offsets stay valid.
        foreach (array_reverse($m) as $match) {
            [$token, $offset] = $match[0];
            $value = (string)($fields[$match[1][0]] ?? '');
            $end = $offset + strlen($token);
            $first = $last = null;
            foreach ($starts as $k => $s) {
                $e = $ends[$k];
                if ($first === null && $offset >= $s && $offset < $e) {
                    $first = $k;
                }
                if ($end > $s && $end <= $e) {
                    $last = $k;
                }
            }
            if ($first === null || $last === null) {
                continue;
            }
            $prefix = substr($texts[$first], 0, $offset - $starts[$first]);
            $suffix = substr($texts[$last], $end - $starts[$last]);
            if ($first === $last) {
                $texts[$first] = $prefix . $value . $suffix;
            } else {
                $texts[$first] = $prefix . $value;
                for ($k = $first + 1; $k < $last; $k++) {
                    $texts[$k] = '';
                }
                $texts[$last] = $suffix;
            }
        }
        foreach ($nodes as $k => $n) {
            if ($n->textContent !== $texts[$k]) {
                $n->textContent = $texts[$k];
                $n->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
        }
    }
    return $dom->saveXML();
}
