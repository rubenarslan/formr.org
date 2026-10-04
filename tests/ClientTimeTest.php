<?php

use PHPUnit\Framework\TestCase;

/**
 * client_time(): a timestamp the browser sent (UTC, from Date#toISOString) in
 * the server's time zone, as every other stored timestamp is. Item views
 * (`shown`, `answered`) and the offline queue's `client_ts` used to be stored
 * as sent, two hours apart from `saved` in Central European summer time.
 */
class ClientTimeTest extends TestCase {

    private $zone;

    protected function setUp(): void {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void {
        date_default_timezone_set($this->zone);
    }

    public function testUtcBecomesServerTimeAcrossDaylightSaving() {
        $this->assertSame('2026-07-01 12:00:00', client_time('2026-07-01 10:00:00'), 'summer: UTC+2');
        $this->assertSame('2026-01-15 11:00:00', client_time('2026-01-15 10:00:00'), 'winter: UTC+1');
        $this->assertSame('2026-01-01 00:30:00', client_time('2025-12-31 23:30:00'), 'across midnight and the year');
        // The hour the clocks go back happens twice in local time; UTC tells them apart.
        $this->assertSame('2026-10-25 02:30:00', client_time('2026-10-25 00:30:00'));
        $this->assertSame('2026-10-25 02:30:00', client_time('2026-10-25 01:30:00'));
    }

    public function testFollowsTheServerZone() {
        date_default_timezone_set('UTC');
        $this->assertSame('2026-07-01 10:00:00', client_time('2026-07-01 10:00:00'));
        date_default_timezone_set('America/New_York');
        $this->assertSame('2026-07-01 06:00:00', client_time('2026-07-01 10:00:00'));
    }

    /**
     * Only what a DATETIME column holds, in the server's zone: a page write
     * with a value the column refuses fails as a whole (and the offline
     * queue retries it for good).
     */
    public function testOnlyYearsADatetimeHoldsAfterConversion() {
        $this->assertNull(client_time('9999-12-31 23:30:00'), 'the year 10000 in Berlin');
        $this->assertNull(client_time('0999-06-01 10:00:00'));
        $this->assertSame('9999-12-31 22:00:00', client_time('9999-12-31 21:00:00'));
    }

    public function testAnythingElseIsNotATimestamp() {
        foreach (array(null, '', 12, array('2026-07-01 10:00:00'), '2026-07-01T10:00:00.000Z', '2026-07-01 10:00',
                       '2026-02-30 10:00:00', '2026-07-01 24:00:00', '2026-13-01 10:00:00', ' 2026-07-01 10:00:00') as $value) {
            $this->assertNull(client_time($value), var_export($value, true));
        }
    }
}
