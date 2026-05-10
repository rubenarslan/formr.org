<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the AdminSurveyController::changeSettings
 * mass-assignment hardening. Before commit <pending>, the function only
 * int-cast every value except google_file_id; numeric identity columns
 * like survey_studies.user_id slipped through unfiltered, so a POST
 * carrying &user_id=999 to admin/survey/<name> would change the survey's
 * owner via $this->study->update($settings).
 *
 * After the fix, an array_intersect_key allowlist drops every key that
 * isn't a legitimate settings column before update() is called.
 */
class AdminSurveyChangeSettingsTest extends TestCase
{
    private function invokeChangeSettings(array $input)
    {
        $study = new class {
            public $lastUpdate = null;
            public $unlinked = 0;
            public $hide_results = 0;
            public $use_paging = 0;
            public function update($data) { $this->lastUpdate = $data; }
        };

        $controller = (new \ReflectionClass(AdminSurveyController::class))
            ->newInstanceWithoutConstructor();
        $controller->study = $study;

        $method = new \ReflectionMethod(AdminSurveyController::class, 'changeSettings');
        $method->setAccessible(true);
        // changeSettings calls alert() / Session::set on success/error; both
        // are global in this app's setup.php bootstrap, so they're harmless
        // — but we use @ to suppress notices about $settings keys the
        // attacker omits (the pre-existing range-check pattern dereferences
        // them with isset-mixed-with-comparison precedence quirks).
        @$method->invoke($controller, $input);

        return $study->lastUpdate;
    }

    public function testIdentityFieldsAreDroppedFromSettings(): void
    {
        // Include all the form's normal numeric fields as a real admin
        // POST would; the function's pre-existing range checks rely on
        // these keys being present (an unset key trips a $value < N
        // comparison via PHP's NULL-to-0 coercion + && / || precedence).
        $applied = $this->invokeChangeSettings([
            'user_id' => 999,
            'name' => 'PWNED',
            'results_table' => 'PWNED_TABLE',
            'id' => 1,
            'valid' => 0,
            'created' => '2026-01-01 00:00:00',
            'modified' => '2026-01-01 00:00:00',
            // Form-shaped legitimate fields:
            'maximum_number_displayed' => 42,
            'displayed_percentage_maximum' => 100,
            'add_percentage_points' => 0,
            'expire_after' => 0,
        ]);

        $this->assertNotNull($applied, 'changeSettings should reach $this->study->update()');
        $this->assertArrayNotHasKey('user_id', $applied);
        $this->assertArrayNotHasKey('name', $applied);
        $this->assertArrayNotHasKey('results_table', $applied);
        $this->assertArrayNotHasKey('id', $applied);
        $this->assertArrayNotHasKey('valid', $applied);
        $this->assertArrayNotHasKey('created', $applied);
        $this->assertArrayNotHasKey('modified', $applied);
        $this->assertSame(42, $applied['maximum_number_displayed']);
    }

    public function testAllowlistedSettingsSurvive(): void
    {
        $applied = $this->invokeChangeSettings([
            'maximum_number_displayed' => 100,
            'displayed_percentage_maximum' => 75,
            'add_percentage_points' => 5,
            'expire_after' => 60,
            'expire_invitation_after' => 30,
            'expire_invitation_grace' => 15,
            'enable_instant_validation' => 1,
            'hide_results' => 0,
            'use_paging' => 0,
            'unlinked' => 0,
            'google_file_id' => 'abc123',
        ]);

        $this->assertNotNull($applied);
        $this->assertSame(100, $applied['maximum_number_displayed']);
        $this->assertSame(75, $applied['displayed_percentage_maximum']);
        $this->assertSame(5, $applied['add_percentage_points']);
        $this->assertSame(60, $applied['expire_after']);
        $this->assertSame('abc123', $applied['google_file_id']);
    }

    public function testEmptyInputIsNoOp(): void
    {
        // No allowlisted fields → update() may run with empty array but
        // must not contain any smuggled keys.
        $applied = $this->invokeChangeSettings([
            'user_id' => 1,
            'admin' => 100,
        ]);

        if ($applied !== null) {
            $this->assertArrayNotHasKey('user_id', $applied);
            $this->assertArrayNotHasKey('admin', $applied);
        }
        // Either update() was called with no keys (allowlist filtered all),
        // or it was skipped entirely (range check or some other early
        // return). Both are acceptable — the contract is "no smuggling".
        $this->assertTrue(true);
    }
}
