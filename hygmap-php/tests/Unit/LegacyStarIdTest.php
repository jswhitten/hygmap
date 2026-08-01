<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/IndexHelpers.php';

/**
 * Deciding whether a star link predates the AT-HYG 4 renumbering.
 *
 * The behaviour under test is mostly about what this must NOT do. A bare star id cannot be
 * attributed to a catalog version by inspection — 99.99% of v3 ids are also a valid,
 * different v4 id — so the only safe answers are "show both readings" or "say nothing".
 *
 * These cover the two pure halves of `resolveLegacyAlternative()`. The fetch itself is a
 * one-line `ApiClient` call; the decisions are here, deliberately separated so they can be
 * tested at all (`ApiClient` is final and reached via a singleton).
 */
final class LegacyStarIdTest extends TestCase
{
    // -- shouldCheckLegacyId: is a lookup worth doing? ------------------------------

    public function testAnUnmarkedLinkIsWorthChecking(): void
    {
        $this->assertTrue(\IndexHelpers::shouldCheckLegacyId(7301, 0));
    }

    public function testAMarkedLinkIsNotChecked(): void
    {
        // A link this app generated carries c=4 and is known to be current. Looking it up
        // anyway would put a wasted API call on the common path.
        $this->assertFalse(\IndexHelpers::shouldCheckLegacyId(7301, 4));
    }

    public function testAnUnrecognisedMarkerIsTreatedAsUnmarked(): void
    {
        // Only the current version suppresses the check. A future c=5 must not silently
        // disable disambiguation for a numbering this build cannot vouch for.
        $this->assertTrue(\IndexHelpers::shouldCheckLegacyId(7301, 5));
        $this->assertTrue(\IndexHelpers::shouldCheckLegacyId(7301, 3));
    }

    public function testNoStarSelectedIsNotChecked(): void
    {
        $this->assertFalse(\IndexHelpers::shouldCheckLegacyId(0, 0));
        $this->assertFalse(\IndexHelpers::shouldCheckLegacyId(-3, 0));
    }

    // -- legacyNoticeFor: is there anything to say? ---------------------------------

    public function testOffersTheOldStarWhenTheIdMeantSomethingElse(): void
    {
        $legacy = ['id' => 7323, 'proper' => 'GJ 1'];

        $result = \IndexHelpers::legacyNoticeFor(7301, $legacy);

        $this->assertNotNull($result);
        $this->assertSame(7323, $result['id']);
    }

    public function testSaysNothingWhenTheLegacyIdNamesTheSameStar(): void
    {
        // 636 stars kept their id across the migration. For those an old link is still
        // correct and a notice would be noise.
        $this->assertNull(\IndexHelpers::legacyNoticeFor(7301, ['id' => 7301]));
    }

    public function testSaysNothingWhenNothingMapsToTheId(): void
    {
        $this->assertNull(\IndexHelpers::legacyNoticeFor(999999999, null));
    }

    public function testSaysNothingOnAMalformedApiRow(): void
    {
        // Defensive: a row without an id cannot be linked to, and half a notice would be
        // worse than none.
        $this->assertNull(\IndexHelpers::legacyNoticeFor(7301, ['proper' => 'No id here']));
    }

    public function testCatalogVersionConstantMatchesTheMarkerWeEmit(): void
    {
        // If this and the `c=` value in the templates ever drift, every generated link
        // starts triggering a legacy lookup, and the notice appears on correct links.
        $this->assertSame(4, \IndexHelpers::CATALOG_VERSION);
    }

    public function testTheJavaScriptCatalogVersionAgreesWithPhp(): void
    {
        // The marker is emitted from three languages: PHP templates, map-interactive.js
        // (click-to-select and the pan/zoom URL builders) and the React urlState module.
        // Nothing but agreement makes them one scheme, and the failure is quiet — a link
        // built by the drifted side gets treated as pre-migration and the reader is told
        // their brand-new link might be an old one.
        //
        // Same shape of guard as the display-name fixture, for the same reason: a value
        // duplicated across languages needs something that fails when a copy moves.
        $js = file_get_contents(__DIR__ . '/../../src/js/map-interactive.js');
        $this->assertIsString($js);

        $this->assertSame(
            1,
            preg_match('/const CATALOG_VERSION = (\d+);/', $js, $m),
            'map-interactive.js must declare a CATALOG_VERSION constant'
        );
        $this->assertSame(
            \IndexHelpers::CATALOG_VERSION,
            (int)$m[1],
            'map-interactive.js CATALOG_VERSION has drifted from IndexHelpers::CATALOG_VERSION'
        );
    }

    // The React copy of this constant (urlState.ts's CURRENT_CATALOG_VERSION) is still
    // pinned by nothing — audit-architecture 2026-07-31. A guard was attempted here and
    // removed: the PHP test container mounts only hygmap-php, so reading the TypeScript
    // source from this suite fails outright, and mounting one language's source into
    // another's test container to regex it is the wrong shape anyway. The right answer is
    // the pattern this project already uses for cross-language agreement — a file under
    // tests/fixtures/, which is mounted into all three suites. See the ROADMAP item.

    public function testEveryStarSelectingFormStampsTheCatalogVersion(): void
    {
        // Request.php defaults `c` to 0 when absent, so a form that omits it produces a
        // submission indistinguishable from a pre-migration bookmark: shouldCheckLegacyId()
        // returns true and index.php pays for a blocking /api/stars/legacy/{id} lookup on
        // ordinary in-app navigation. Measured at 21-31ms on the critical path by
        // audit-performance 2026-07-31, on essentially every page render with a star
        // selected.
        //
        // Asserted structurally rather than by counting: any <form> that can select a star
        // must carry the marker, so a fourth form added later is covered by this test
        // without anyone remembering to update a number.
        $html = file_get_contents(__DIR__ . '/../../src/index.php');
        $this->assertIsString($html);

        $this->assertSame(
            1,
            preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s', $html, $forms) > 0 ? 1 : 0,
            'index.php must contain at least one form'
        );

        foreach ($forms[0] as $form) {
            $selectsAStar = str_contains($form, 'name="select_star"');
            if (!$selectsAStar) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/<input[^>]*type="hidden"[^>]*name="c"/',
                $form,
                "A form that submits select_star must also stamp the catalog version:\n" . $form
            );
        }
    }
}
