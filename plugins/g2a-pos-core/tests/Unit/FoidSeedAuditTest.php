<?php

namespace G2A\POS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Client comment #4a — verify only Illinois is seeded with FOID = 1.
 * Walks the StateSeeder file directly so the test cannot be invalidated
 * by a future change accidentally adding FOID to another state.
 */
final class FoidSeedAuditTest extends TestCase
{
    public function test_only_illinois_has_requires_foid_in_seed(): void
    {
        $path = dirname(__DIR__, 2) . '/includes/Compliance/State/StateSeeder.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        // Find every block that has 'requires_foid' => 1 and capture the
        // most recent comment / array key before it so we can attribute it.
        preg_match_all("/'requires_foid'\s*=>\s*1/", $source, $matches);
        $this->assertCount(1, $matches[0],
            'Exactly one state should have requires_foid = 1 (Illinois).');

        // Confirm the one row is the Illinois block specifically.
        $this->assertMatchesRegularExpression(
            "/Illinois.{0,400}'requires_foid'\s*=>\s*1/s",
            $source,
            'The single requires_foid = 1 must appear inside the Illinois block.'
        );

        // And the IL row in the rows[] map must be the one that gets the flag.
        $this->assertMatchesRegularExpression(
            "/\\\$rows\['IL'\][^;]+'requires_foid'\s*=>\s*1/s",
            $source,
            'The IL key in the rows map must explicitly set requires_foid = 1.'
        );
    }
}
