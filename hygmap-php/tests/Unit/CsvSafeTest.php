<?php
declare(strict_types=1);

namespace HYGMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for csv_safe(), the CSV formula-injection guard used by export.php.
 *
 * This had zero tests across two audits, not through neglect but because it was declared
 * inside export.php — a file that performs the export on include, so the only way to reach
 * the function was to run the whole endpoint. Moving it to src/CsvSafe.php made it
 * testable; that is the entire reason for the move.
 *
 * What it defends against: star names come from third-party catalogs and the fictional
 * names table. Excel, LibreOffice and Google Sheets evaluate any cell beginning `=`, `+`,
 * `-` or `@` as a formula on open, so a catalog entry named `=cmd|'/c calc'!A1` becomes
 * code execution on the reader's machine. The guard prefixes a single quote, which all
 * three render as literal text.
 *
 * Note this is a real-data risk, not a theoretical one: the project already imports
 * spectral types like `sdF8:` and names with punctuation, and `athyg_supplement.csv` and
 * `fic` are hand-editable.
 */
class CsvSafeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once HYGMAP_SRC_DIR . '/CsvSafe.php';
    }

    /** @return array<string, array{0: string}> */
    public static function formulaPrefixes(): array
    {
        return [
            'equals'      => ['=1+1'],
            'plus'        => ['+1+1'],
            'minus'       => ['-1+1'],
            'at'          => ['@SUM(A1)'],
        ];
    }

    /** @dataProvider formulaPrefixes */
    public function testAFormulaCellIsPrefixedWithAQuote(string $value): void
    {
        $this->assertSame("'" . $value, csv_safe($value));
    }

    public function testTheClassicExcelPayloadIsNeutralised(): void
    {
        // The canonical DDE payload; if this one is not quoted, nothing else matters.
        $payload = '=cmd|\'/c calc\'!A1';
        $this->assertSame("'" . $payload, csv_safe($payload));
    }

    /** @return array<string, array{0: string}> */
    public static function leadingWhitespaceCases(): array
    {
        return [
            'space'           => [' =1+1'],
            'tab'             => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
            'newline'         => ["\n=1+1"],
            'several'         => ["  \t =1+1"],
        ];
    }

    /**
     * @dataProvider leadingWhitespaceCases
     *
     * Excel strips leading whitespace before deciding whether a cell is a formula, so
     * " =cmd" is still executed. The guard has to look past it — and must prefix the
     * ORIGINAL value, not the trimmed one, or the exported data is silently altered.
     */
    public function testWhitespaceDoesNotHideAFormula(string $value): void
    {
        $result = csv_safe($value);

        $this->assertSame("'" . $value, $result, 'the original spacing must be preserved');
        $this->assertStringStartsWith("'", (string)$result);
    }

    /** @return array<string, array{0: string}> */
    public static function ordinaryValues(): array
    {
        return [
            'proper name'          => ['Sirius'],
            'bayer designation'    => ['Alp CMa'],
            'spectral type'        => ['A1V'],
            'subdwarf spectrum'    => ['sdF8:'],
            'fictional name'       => ['Vulcan'],
            'name with an apostrophe' => ["Barnard's Star"],
            'negative-looking text'   => ['Alpha-Centauri'],
            'internal equals'      => ['HD=12345'],
            'numeric string'       => ['12345'],
            'empty string'         => [''],
        ];
    }

    /**
     * @dataProvider ordinaryValues
     *
     * The guard must not touch anything else. A false positive corrupts real star names in
     * every exported file, which is a data-integrity bug affecting every user rather than
     * a security one affecting a targeted reader.
     */
    public function testOrdinaryValuesArePassedThroughUnchanged(string $value): void
    {
        $this->assertSame($value, csv_safe($value));
    }

    public function testAHyphenatedNameIsNotQuotedButALeadingHyphenIs(): void
    {
        // The distinction that matters most in practice: '-' is both a formula prefix and
        // an ordinary character in star designations.
        $this->assertSame('Alpha-Centauri', csv_safe('Alpha-Centauri'));
        $this->assertSame("'-Centauri", csv_safe('-Centauri'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonStrings(): array
    {
        return [
            'int'    => [42],
            'float'  => [4.83],
            'null'   => [null],
            'bool'   => [true],
            'zero'   => [0],
            'negative float' => [-1.46],
        ];
    }

    /**
     * @dataProvider nonStrings
     *
     * export.php maps this over a row containing floats (absmag, distance, coordinates)
     * and nulls. A negative magnitude like -1.46 arrives as a float, not a string, so it
     * must pass through untouched — quoting it would make every bright star's magnitude
     * text in the spreadsheet.
     */
    public function testNonStringsArePassedThroughUnchanged(mixed $value): void
    {
        $this->assertSame($value, csv_safe($value));
    }

    public function testANegativeNumberFormattedAsAStringIsQuoted(): void
    {
        // The trade-off, asserted so it is a decision rather than a surprise: once a
        // negative number has been cast to a string it is indistinguishable from a
        // formula, so it gets quoted. export.php passes floats, not strings, which is
        // what keeps real magnitudes intact — see the test above.
        $this->assertSame("'-1.46", csv_safe('-1.46'));
    }
}
