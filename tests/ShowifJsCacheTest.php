<?php
use PHPUnit\Framework\TestCase;

/**
 * survey_items.showif_js (patch 088) caches Item::setMoreOptions()'s
 * regex-based R->JS showif transpile so it isn't redone every render. That
 * cache is written only at import time (SurveyStudy::addItems binds
 * :showif_js to $item->js_showif) and, before this fix, was trusted
 * unconditionally forever — so a fix to the transpile regexes never took
 * effect for an already-imported study until someone re-uploaded it.
 *
 * Item::SHOWIF_JS_VERSION + stampShowifJs()/parseShowifJsCache() close that
 * gap: the cached value carries a leading "sjv<N>" version comment, and
 * setMoreOptions() only trusts a cache whose stamp matches the current
 * version. Anything else (no stamp at all -- a legacy pre-fix row -- or a
 * stale version) is re-transpiled live, and the result is re-stamped with
 * the current version so a future import persists a cache the next
 * request can trust.
 */
class ShowifJsCacheTest extends TestCase
{
    private function stamp($js, $version)
    {
        return '/*sjv' . $version . '*/' . $js;
    }

    private function make(array $options = array())
    {
        return new Item(array_merge(array(
            'type' => 'text', 'name' => 'q1', 'id' => 1, 'label_parsed' => 'Q1',
        ), $options));
    }

    public function testCurrentVersionCacheIsUsedVerbatimAndStripped()
    {
        // The cached JS is deliberately NOT what a live transpile of
        // `showif` would produce, so a passing assertion proves the cache
        // was trusted rather than silently regenerated.
        $cached = 'CACHED_NOT_RETRANSPILED';
        $item = $this->make(array(
            'showif' => 'x == 1',
            'showif_js' => $this->stamp($cached, Item::SHOWIF_JS_VERSION),
        ));

        $this->assertSame($cached, $item->js_showif, 'a current-version cache must be used verbatim, with the stamp stripped');
    }

    public function testStaleVersionCacheIsRetranspiledAndRestamped()
    {
        $stale = $this->stamp('STALE_SHOULD_BE_IGNORED', Item::SHOWIF_JS_VERSION - 1);
        $item = $this->make(array(
            'showif' => 'x == 1',
            'showif_js' => $stale,
        ));

        $expected = $this->stamp('x == 1', Item::SHOWIF_JS_VERSION);
        $this->assertSame($expected, $item->js_showif, 'a stale-version cache must be discarded and replaced with a freshly transpiled + re-stamped value');
        $this->assertStringNotContainsString('STALE_SHOULD_BE_IGNORED', $item->js_showif);
    }

    public function testLegacyUnstampedCacheIsRetranspiledAndRestamped()
    {
        // Simulates a row written by the pre-versioning code (before this
        // fix shipped): showif_js populated, but with no `/*sjv<N>*/`
        // stamp at all.
        $legacy = 'x == 1_LEGACY_UNSTAMPED';
        $item = $this->make(array(
            'showif' => 'x == 1',
            'showif_js' => $legacy,
        ));

        $expected = $this->stamp('x == 1', Item::SHOWIF_JS_VERSION);
        $this->assertSame($expected, $item->js_showif, 'an unstamped legacy cache must never be trusted as-is');
    }

    public function testEmptyCacheFallsBackToLiveTranspileAndStamps()
    {
        $item = $this->make(array(
            'showif' => 'x %contains% "abc"',
            'showif_js' => null,
        ));

        $expected = $this->stamp('x.toString().indexOf("abc") > -1', Item::SHOWIF_JS_VERSION);
        $this->assertSame($expected, $item->js_showif, 'a null cache runs the regex transpile chain and stamps the result');
    }

    public function testJsOnlyMarkerStillHidesItemOnCacheHit()
    {
        $item = $this->make(array(
            'showif' => 'x == 1 //js_only',
            'showif_js' => $this->stamp('x == 1', Item::SHOWIF_JS_VERSION),
        ));

        // setMoreOptions calls setVisibility(array(null)) -> hide() on the
        // //js_only marker; confirm that still happens on the early-return
        // cache-hit path, not just the live-transpile path.
        $this->assertTrue($item->hidden, '//js_only must still take effect when the cache hit branch returns early');
    }
}
