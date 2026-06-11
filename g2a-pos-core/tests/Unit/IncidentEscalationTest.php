<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Range\IncidentService;
use PHPUnit\Framework\TestCase;

final class IncidentEscalationTest extends TestCase
{
    public function test_eject_severity_maps_to_do_not_sell(): void
    {
        $this->assertSame('do_not_sell', IncidentService::flag_kind_for_severity('eject'));
    }

    public function test_banned_severity_maps_to_denied_buyer(): void
    {
        $this->assertSame('denied_buyer', IncidentService::flag_kind_for_severity('banned'));
    }

    public function test_lower_severities_do_not_apply_a_flag(): void
    {
        $this->assertNull(IncidentService::flag_kind_for_severity('info'));
        $this->assertNull(IncidentService::flag_kind_for_severity('warning'));
        $this->assertNull(IncidentService::flag_kind_for_severity('serious'));
    }

    public function test_unknown_severity_returns_null(): void
    {
        $this->assertNull(IncidentService::flag_kind_for_severity('made_up'));
        $this->assertNull(IncidentService::flag_kind_for_severity(''));
    }
}
