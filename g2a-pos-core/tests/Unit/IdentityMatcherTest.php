<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Catalog\IdentityMatcher;
use PHPUnit\Framework\TestCase;

final class IdentityMatcherTest extends TestCase
{
    private function glock19_existing(): array
    {
        return [
            'manufacturer' => 'Glock',
            'model' => 'G19 Gen 5',
            'caliber' => '9mm',
            'firearm_type' => 'handgun',
            'upc' => '764503914003',
            'mfg_part' => 'PA195S203',
        ];
    }

    public function test_exact_upc_match_pegs_score_to_one(): void
    {
        $score = IdentityMatcher::score(
            ['upc' => '764503914003', 'manufacturer' => 'Glock'],
            $this->glock19_existing()
        );
        $this->assertSame(1.0, $score);
    }

    public function test_upc_with_punctuation_normalizes(): void
    {
        $score = IdentityMatcher::score(
            ['upc' => '7-64503-91400-3', 'manufacturer' => 'Glock'],
            $this->glock19_existing()
        );
        $this->assertSame(1.0, $score);
    }

    public function test_exact_mfg_part_match_scores_high(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Glock', 'mfg_part' => 'PA195S203'],
            $this->glock19_existing()
        );
        $this->assertSame(0.95, $score);
    }

    public function test_manufacturer_model_caliber_qualifies(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Glock', 'model' => 'G19 Gen 5', 'caliber' => '9mm'],
            $this->glock19_existing()
        );
        // 0.70 (mfg+model) + 0.15 (caliber) = 0.85 → meets THRESHOLD
        $this->assertGreaterThanOrEqual(IdentityMatcher::THRESHOLD, $score);
        $this->assertTrue(IdentityMatcher::is_match(
            ['manufacturer' => 'Glock', 'model' => 'G19 Gen 5', 'caliber' => '9mm'],
            $this->glock19_existing()
        ));
    }

    public function test_caliber_alias_9mm_luger_normalizes_to_9mm(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Glock', 'model' => 'G19 Gen 5', 'caliber' => '9mm Luger'],
            $this->glock19_existing()
        );
        $this->assertGreaterThanOrEqual(IdentityMatcher::THRESHOLD, $score);
    }

    public function test_5_56_alias_collapses_to_canonical(): void
    {
        $ar = [
            'manufacturer' => 'Smith & Wesson', 'model' => 'M&P 15 Sport III',
            'caliber' => '5.56', 'firearm_type' => 'rifle',
        ];
        $candidate = $ar;
        $candidate['caliber'] = '5.56 NATO';
        $this->assertGreaterThanOrEqual(IdentityMatcher::THRESHOLD,
            IdentityMatcher::score($candidate, $ar));
    }

    public function test_manufacturer_only_match_does_not_clear_threshold(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Glock', 'model' => 'G42', 'caliber' => '380'],
            $this->glock19_existing()
        );
        $this->assertLessThan(IdentityMatcher::THRESHOLD, $score);
        $this->assertFalse(IdentityMatcher::is_match(
            ['manufacturer' => 'Glock', 'model' => 'G42', 'caliber' => '380'],
            $this->glock19_existing()
        ));
    }

    public function test_different_manufacturer_returns_zero(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Sig Sauer', 'model' => 'G19 Gen 5', 'caliber' => '9mm'],
            $this->glock19_existing()
        );
        $this->assertSame(0.0, $score);
    }

    public function test_norm_collapses_punctuation_and_case(): void
    {
        $this->assertSame('s w m p 15', IdentityMatcher::norm('S&W M&P-15!!!'));
        $this->assertSame('glock 19', IdentityMatcher::norm('  GLOCK 19  '));
    }

    public function test_clean_upc_strips_separators(): void
    {
        $this->assertSame('764503914003', IdentityMatcher::clean_upc('7-64503 914003'));
        $this->assertSame('', IdentityMatcher::clean_upc('no digits here'));
    }

    public function test_fuzzy_model_alone_does_not_clear_threshold(): void
    {
        $score = IdentityMatcher::score(
            ['manufacturer' => 'Glock', 'model' => 'Glock 19 Gen5 FS'],
            $this->glock19_existing()
        );
        // mfg-only floor (0.30) + small fuzzy bump (≤0.10) = far below 0.85
        $this->assertLessThan(IdentityMatcher::THRESHOLD, $score);
    }

    public function test_sim_returns_one_on_identical_inputs(): void
    {
        $this->assertSame(1.0, IdentityMatcher::sim('Glock 19 Gen 5', 'glock 19 gen 5'));
    }

    public function test_sim_returns_zero_on_blank_input(): void
    {
        $this->assertSame(0.0, IdentityMatcher::sim('', 'anything'));
    }
}
