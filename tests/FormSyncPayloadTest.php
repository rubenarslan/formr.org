<?php

use PHPUnit\Framework\TestCase;

/**
 * `/{run}/form-sync` envelope validation (FormSyncPayload).
 *
 * Regression guard for the offline-queue double-write: `client_ts` used to be
 * read straight out of the payload and only bound at INSERT time — i.e. AFTER
 * UnitSession::updateSurveyStudyRecord had applied the answers but BEFORE the
 * uuid ledger row existed. A value MariaDB couldn't parse (an ISO-8601
 * `2026-08-31T10:00:00.000Z` is the realistic case: it is exactly what an
 * IndexedDB entry written by a bundle that reached for toISOString() carries)
 * therefore 500'd the request in that gap. The client treats a 5xx as
 * transient and re-drains the entry; with no ledger row the dedupe pre-check
 * misses and the answers are applied a second time.
 *
 * Every field here lands verbatim in a typed `survey_form_submissions` column
 * (patch 081), so the same class of failure exists for `page`
 * (SMALLINT UNSIGNED). Validation must therefore be complete and must run
 * before any state change.
 *
 * RunController itself is not constructible in the SQLite unit lane (Site +
 * session + live run), which is why the rules live in a pure helper.
 */
class FormSyncPayloadTest extends TestCase
{
    const UUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    private function validate($overrides = array())
    {
        $args = array_merge(array(
            'uuid' => self::UUID,
            'page' => 2,
            'unit_session_id' => 77,
            'client_ts' => '2026-08-31 10:00:00',
        ), $overrides);
        return FormSyncPayload::validate($args['uuid'], $args['page'], $args['unit_session_id'], $args['client_ts']);
    }

    public function testWellFormedEnvelopePasses()
    {
        $out = $this->validate();
        $this->assertTrue($out['ok']);
        $this->assertSame(self::UUID, $out['uuid']);
        $this->assertSame(2, $out['page']);
        $this->assertSame(77, $out['unit_session_id']);
        $this->assertSame('2026-08-31 10:00:00', $out['client_ts']);
    }

    /** The multipart branch delivers everything as strings. */
    public function testMultipartStringsAreAccepted()
    {
        $out = $this->validate(array('page' => '2', 'unit_session_id' => '77'));
        $this->assertTrue($out['ok']);
        $this->assertSame(2, $out['page']);
        $this->assertSame(77, $out['unit_session_id']);
    }

    /** THE regression: the ISO-8601 shape that used to 500 after the apply. */
    public function testIsoTimestampIsRefusedBeforeAnyStateChange()
    {
        $out = $this->validate(array('client_ts' => '2026-08-31T10:00:00.000Z'));
        $this->assertFalse($out['ok']);
        $this->assertSame('client_ts', $out['field']);
    }

    /**
     * @dataProvider malformedTimestamps
     */
    public function testMalformedTimestampsAreRefused($ts)
    {
        $out = $this->validate(array('client_ts' => $ts));
        $this->assertFalse($out['ok'], var_export($ts, true) . ' must not reach the DATETIME column');
        $this->assertSame('client_ts', $out['field']);
    }

    public static function malformedTimestamps()
    {
        return array(
            'iso with T' => array('2026-08-31T10:00:00'),
            'fractional seconds' => array('2026-08-31 10:00:00.123'),
            'date only' => array('2026-08-31'),
            'nonsense' => array('yesterday'),
            'sql fragment' => array("2026-08-31 10:00:00'); DROP TABLE x; --"),
            'impossible day' => array('2026-02-30 10:00:00'),
            'hour 24' => array('2026-08-31 24:00:00'),
            'minute 60' => array('2026-08-31 10:60:00'),
            'year 0' => array('0000-01-01 00:00:00'),
            'not a string' => array(array('2026-08-31 10:00:00')),
            'boolean' => array(true),
        );
    }

    /** client_ts is optional paradata: absent stores NULL, it is not an error. */
    public function testAbsentTimestampIsNull()
    {
        foreach (array(null, '') as $absent) {
            $out = $this->validate(array('client_ts' => $absent));
            $this->assertTrue($out['ok']);
            $this->assertNull($out['client_ts']);
        }
    }

    public function testLeapDayIsAccepted()
    {
        $out = $this->validate(array('client_ts' => '2028-02-29 23:59:59'));
        $this->assertTrue($out['ok']);
    }

    public function testMalformedUuidIsRefused()
    {
        foreach (array('', 'not-a-uuid', '3f2504e04f8911d39a0c0305e82c3301', 12345, null,
                       '3f2504e0-4f89-11d3-9a0c-0305e82c3301-extra') as $bad) {
            $out = FormSyncPayload::validate($bad, 1, 0, null);
            $this->assertFalse($out['ok'], var_export($bad, true));
            $this->assertSame('uuid', $out['field']);
        }
    }

    /**
     * `page` is SMALLINT UNSIGNED — out of range is error 1264 at INSERT time,
     * the same post-apply position client_ts occupied.
     */
    public function testPageOutOfColumnRangeIsRefused()
    {
        foreach (array(0, -1, 65536, 1000000) as $bad) {
            $out = $this->validate(array('page' => $bad));
            $this->assertFalse($out['ok'], var_export($bad, true));
            $this->assertSame('page', $out['field']);
        }
        $this->assertTrue($this->validate(array('page' => 65535))['ok'], 'the column maximum is legal');
    }

    /** A cast would silently turn "two" into page 1 and write the wrong page. */
    public function testNonIntegerPageIsRefusedRatherThanCast()
    {
        foreach (array('two', '1.5', '1e3', array(1), true) as $bad) {
            $out = $this->validate(array('page' => $bad));
            $this->assertFalse($out['ok'], var_export($bad, true));
            $this->assertSame('page', $out['field']);
        }
    }

    /** Older clients omit page / unit_session_id; keep their lenient defaults. */
    public function testOmittedOptionalScalarsKeepHistoricDefaults()
    {
        $out = FormSyncPayload::validate(self::UUID, null, null, null);
        $this->assertTrue($out['ok']);
        $this->assertSame(1, $out['page']);
        $this->assertSame(0, $out['unit_session_id'], '0 = "unknown", the endpoint does not gate on it');
    }

    public function testMalformedUnitSessionIdIsRefused()
    {
        foreach (array('abc', -3, '1.5', array(1)) as $bad) {
            $out = $this->validate(array('unit_session_id' => $bad));
            $this->assertFalse($out['ok'], var_export($bad, true));
            $this->assertSame('unit_session_id', $out['field']);
        }
    }
}
