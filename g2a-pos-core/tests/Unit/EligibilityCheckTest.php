<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\State\EligibilityCheck;
use G2A\POS\Compliance\State\StateRules;
use PHPUnit\Framework\TestCase;

final class EligibilityCheckTest extends TestCase
{
    protected function setUp(): void
    {
        StateRules::invalidate();
        // Inject rules via reflection to bypass DB
        $rules = [
            'AZ' => [
                'handgun_min_age' => 21, 'long_gun_min_age' => 18,
                'ammo_handgun_min_age' => 21, 'ammo_long_gun_min_age' => 18,
                'waiting_period_hours_handgun' => 0, 'waiting_period_hours_long_gun' => 0,
                'requires_permit_to_purchase_handgun' => 0, 'requires_permit_to_purchase_long_gun' => 0,
                'permit_label' => null, 'requires_foid' => 0, 'requires_dros' => 0,
                'requires_state_nics_check' => 0, 'state_nics_label' => null,
                'requires_safety_certificate' => 0, 'safety_certificate_label' => null,
                'roster_required' => 0, 'one_handgun_per_30_days' => 0,
                'assault_weapon_restricted' => 0, 'magazine_capacity_limit' => null,
                'requires_long_gun_multi_sale_report' => 1,
                'ffl_to_ffl_required_for_nonresident' => 1,
                'state_code' => 'AZ',
            ],
            'CA' => [
                'handgun_min_age' => 21, 'long_gun_min_age' => 21,
                'ammo_handgun_min_age' => 21, 'ammo_long_gun_min_age' => 21,
                'waiting_period_hours_handgun' => 240, 'waiting_period_hours_long_gun' => 240,
                'requires_permit_to_purchase_handgun' => 0, 'requires_permit_to_purchase_long_gun' => 0,
                'permit_label' => null, 'requires_foid' => 0, 'requires_dros' => 1,
                'requires_state_nics_check' => 1, 'state_nics_label' => 'CA DROS / DOJ',
                'requires_safety_certificate' => 1, 'safety_certificate_label' => 'FSC',
                'roster_required' => 1, 'one_handgun_per_30_days' => 1,
                'assault_weapon_restricted' => 1, 'magazine_capacity_limit' => 10,
                'requires_long_gun_multi_sale_report' => 1,
                'ffl_to_ffl_required_for_nonresident' => 1,
                'state_code' => 'CA',
            ],
            'IL' => [
                'handgun_min_age' => 21, 'long_gun_min_age' => 21,
                'ammo_handgun_min_age' => 21, 'ammo_long_gun_min_age' => 21,
                'waiting_period_hours_handgun' => 72, 'waiting_period_hours_long_gun' => 72,
                'requires_permit_to_purchase_handgun' => 1, 'requires_permit_to_purchase_long_gun' => 1,
                'permit_label' => 'FOID', 'requires_foid' => 1, 'requires_dros' => 0,
                'requires_state_nics_check' => 0, 'state_nics_label' => null,
                'requires_safety_certificate' => 0, 'safety_certificate_label' => null,
                'roster_required' => 0, 'one_handgun_per_30_days' => 0,
                'assault_weapon_restricted' => 1, 'magazine_capacity_limit' => 10,
                'requires_long_gun_multi_sale_report' => 0,
                'ffl_to_ffl_required_for_nonresident' => 1,
                'state_code' => 'IL',
            ],
        ];
        $ref = new \ReflectionClass(StateRules::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(null, $rules);
    }

    public function test_underage_handgun_blocked(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'AZ', 'dob' => '2010-01-01', 'us_citizen' => true],
            [['firearm_type' => 'handgun']]
        );
        $this->assertFalse($r['allowed']);
        $this->assertNotEmpty(array_filter($r['violations'], fn($v) => $v['code'] === 'age_handgun'));
    }

    public function test_ca_long_gun_18yo_blocked(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'CA', 'dob' => '2007-01-01', 'us_citizen' => true, 'resident_state' => 'CA'],
            [['firearm_type' => 'rifle']]
        );
        $this->assertFalse($r['allowed']);
        $this->assertNotEmpty(array_filter($r['violations'], fn($v) => $v['code'] === 'age_long_gun'));
    }

    public function test_il_handgun_without_foid_blocked(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'IL', 'dob' => '1980-01-01', 'us_citizen' => true, 'resident_state' => 'IL'],
            [['firearm_type' => 'handgun']]
        );
        $this->assertFalse($r['allowed']);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('foid_required', $codes);
    }

    public function test_nonresident_handgun_requires_ffl_to_ffl(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'AZ', 'dob' => '1980-01-01', 'us_citizen' => true, 'resident_state' => 'NV'],
            [['firearm_type' => 'handgun']],
            ['location_state' => 'AZ']
        );
        $this->assertFalse($r['allowed']);
        $this->assertContains('nonresident_handgun', array_column($r['violations'], 'code'));
    }

    public function test_az_handgun_21yo_resident_passes(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'AZ', 'dob' => '1990-01-01', 'us_citizen' => true, 'resident_state' => 'AZ'],
            [['firearm_type' => 'handgun']],
            ['location_state' => 'AZ']
        );
        $this->assertTrue($r['allowed'], json_encode($r['violations']));
    }

    public function test_ca_handgun_over_mag_cap_blocked(): void
    {
        $r = EligibilityCheck::evaluate(
            ['state' => 'CA', 'dob' => '1980-01-01', 'us_citizen' => true, 'resident_state' => 'CA',
             'safety_certificate_number' => 'FSC123'],
            [['firearm_type' => 'handgun', 'magazine_capacity' => 17, 'on_state_roster' => true]],
            ['location_state' => 'CA', 'dros_reference' => 'D1', 'nics_method' => 'state']
        );
        $this->assertFalse($r['allowed']);
        $this->assertContains('magazine_capacity_exceeded', array_column($r['violations'], 'code'));
    }

    public function test_missing_state_rejected(): void
    {
        $r = EligibilityCheck::evaluate(
            ['dob' => '1990-01-01', 'us_citizen' => true],
            [['firearm_type' => 'handgun']]
        );
        $this->assertFalse($r['allowed']);
        $this->assertContains('missing_state', array_column($r['violations'], 'code'));
    }
}
