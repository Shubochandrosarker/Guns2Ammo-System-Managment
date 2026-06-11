<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\State\CcwExemption;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the NICS-bypass evaluator (18 USC 922(t)(3)).
 *
 * Critical: every "qualifies" path must still leave the 4473 form
 * requirement intact — the CCW exemption only skips the NICS check.
 */
final class CcwExemptionTest extends TestCase
{
    private function az_rules(): array
    {
        return [
            'state_code' => 'AZ',
            'ccw_bypasses_nics' => 1,
            'ccw_max_age_years' => 5,
            'ccw_permit_label' => 'AZ CCW',
            'ccw_qualifying_citation' => '18 USC 922(t)(3)',
            'ccw_handgun_only' => 0,
        ];
    }

    private function nc_rules(): array
    {
        return array_merge($this->az_rules(), [
            'state_code' => 'NC',
            'ccw_permit_label' => 'NC Concealed Handgun Permit',
            'ccw_handgun_only' => 1,
        ]);
    }

    private function ny_rules(): array
    {
        return [
            'state_code' => 'NY',
            'ccw_bypasses_nics' => 0,
            'ccw_max_age_years' => 5,
        ];
    }

    public function test_qualifying_az_ccw_with_in_window_issue_qualifies(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345', 'issued_on' => '2024-01-15', 'expires_on' => '2030-01-15'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertTrue($r['qualifies']);
        $this->assertSame('AZ', $r['permit_state']);
        $this->assertSame('A12345', $r['permit_number']);
        $this->assertSame('18 USC 922(t)(3)', $r['citation']);
        $this->assertStringContainsString('4473 is still required', $r['message']);
    }

    public function test_permit_state_must_equal_resident_state(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345', 'issued_on' => '2024-01-15'],
            'CA',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('permit_state_mismatch', $r['reason']);
    }

    public function test_non_qualifying_state_is_rejected(): void
    {
        $r = CcwExemption::evaluate(
            $this->ny_rules(),
            ['state' => 'NY', 'number' => 'NY-001', 'issued_on' => '2024-01-15'],
            'NY',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('state_not_qualifying', $r['reason']);
    }

    public function test_expired_permit_is_rejected(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345', 'issued_on' => '2020-01-15', 'expires_on' => '2025-01-15'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('permit_expired', $r['reason']);
    }

    public function test_permit_outside_max_age_window_is_rejected(): void
    {
        // Issued 2019, evaluated 2026 — older than 5 years.
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345', 'issued_on' => '2019-01-15'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('permit_too_old', $r['reason']);
    }

    public function test_missing_permit_number_is_rejected(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => '', 'issued_on' => '2024-01-15'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('missing_permit_number', $r['reason']);
    }

    public function test_nc_handgun_only_blocks_long_gun_purchase(): void
    {
        $r = CcwExemption::evaluate(
            $this->nc_rules(),
            ['state' => 'NC', 'number' => 'NC-9', 'issued_on' => '2024-01-15'],
            'NC',
            [['firearm_type' => 'long_gun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('long_gun_not_eligible', $r['reason']);
    }

    public function test_nc_handgun_only_allows_handgun_purchase(): void
    {
        $r = CcwExemption::evaluate(
            $this->nc_rules(),
            ['state' => 'NC', 'number' => 'NC-9', 'issued_on' => '2024-01-15'],
            'NC',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertTrue($r['qualifies']);
    }

    public function test_casing_is_normalized(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'az', 'number' => 'A12345', 'issued_on' => '2024-01-15'],
            'az',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertTrue($r['qualifies']);
        $this->assertSame('AZ', $r['permit_state']);
    }

    public function test_invalid_dates_are_rejected_cleanly(): void
    {
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345', 'issued_on' => '2024-01-15', 'expires_on' => 'not-a-date'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertFalse($r['qualifies']);
        $this->assertSame('invalid_expires_on', $r['reason']);
    }

    public function test_no_dates_supplied_still_qualifies(): void
    {
        // Permit dates are optional for the evaluator; the 4473 will record
        // whatever the cashier captured. Field-level validation lives in
        // the controller / form layer.
        $r = CcwExemption::evaluate(
            $this->az_rules(),
            ['state' => 'AZ', 'number' => 'A12345'],
            'AZ',
            [['firearm_type' => 'handgun']],
            '2026-06-04'
        );
        $this->assertTrue($r['qualifies']);
        $this->assertNull($r['issued_on']);
        $this->assertNull($r['expires_on']);
    }
}
