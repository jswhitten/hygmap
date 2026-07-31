<?php
declare(strict_types=1);

/**
 * CSV formula-injection guard.
 *
 * Extracted from export.php on 2026-07-31 so it can be unit tested. It was previously a
 * top-level function in that file, which also performs the export on include — so the
 * only way to exercise it was to run the whole endpoint, and consequently nothing did.
 * Two consecutive audits reported it as having zero coverage. Same move REPO-HYGIENE made
 * for MapRenderer, and for the same reason.
 */

/**
 * Prefix a cell that a spreadsheet would evaluate as a formula.
 *
 * Star names come from third-party catalogs and the fictional-names table, so they are
 * untrusted input as far as this app is concerned. Excel, LibreOffice, and Google Sheets
 * evaluate any cell whose first character is =, +, -, or @ as a formula when the file is
 * opened, which turns an exported star list into code execution on the reader's machine.
 * Prefixing a single quote makes the cell render as literal text in all three.
 *
 * Leading whitespace is stripped first: " =cmd" is still treated as a formula by Excel.
 *
 * @param mixed $value Cell value
 * @return mixed Original value, or a quote-prefixed string if it could be read as a formula
 */
function csv_safe(mixed $value): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $trimmed = ltrim($value, " \t\r\n");
    if ($trimmed !== '' && str_contains('=+-@', $trimmed[0])) {
        return "'" . $value;
    }

    return $value;
}
