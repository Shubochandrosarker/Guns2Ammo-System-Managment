<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\WebsiteKnowledgeSeeder;
use G2A\POS\API\CrmController;
use PHPUnit\Framework\TestCase;

final class WebsiteKnowledgeSeederTest extends TestCase
{
    public function test_default_pack_contains_core_business_facts(): void
    {
        $pack = WebsiteKnowledgeSeeder::default_pack();

        $this->assertNotEmpty($pack);
        $all = implode("\n", $pack);
        // Falls back to baked-in constants when the theme's g2a_biz() is absent.
        $this->assertStringContainsString('6030 E Main St', $all);
        $this->assertStringContainsString('(602) 715-2677', $all);
        $this->assertStringContainsString('sales@guns2ammo.com', $all);
        $this->assertStringContainsString('$20 per hour', $all);
        $this->assertStringContainsString('Ranger $29.99/month', $all);
        $this->assertStringContainsString('/book-a-lane/', $all);
        $this->assertStringContainsString('eye and ear protection', strtolower($all));
        // POS how-tos for the agent.
        $this->assertStringContainsString('Layaway', $all);
        $this->assertStringContainsString('4473', $all);
    }

    public function test_split_sections_chunks_llms_text_by_heading(): void
    {
        $text = "# Welcome\nIntro line.\n\n## Pricing\nLane \$20/hr.\nExtra shooter \$15.\n\n## Hours\nMon-Sat 10-6.";
        $sections = WebsiteKnowledgeSeeder::split_sections($text);

        $this->assertCount(3, $sections);
        $this->assertSame('Welcome', $sections[0]['title']);
        $this->assertSame('Pricing', $sections[1]['title']);
        $this->assertStringContainsString('Lane $20/hr.', $sections[1]['body']);
        $this->assertSame('Hours', $sections[2]['title']);
    }

    public function test_split_sections_without_headings_returns_single_section(): void
    {
        $sections = WebsiteKnowledgeSeeder::split_sections('plain text only');
        $this->assertCount(1, $sections);
        $this->assertSame('plain text only', $sections[0]['body']);
    }

    public function test_csv_cell_neutralises_formula_injection(): void
    {
        $this->assertSame("'=SUM(A1)", CrmController::csv_cell('=SUM(A1)'));
        $this->assertSame("'+1", CrmController::csv_cell('+1'));
        $this->assertSame("'-2", CrmController::csv_cell('-2'));
        $this->assertSame("'@cmd", CrmController::csv_cell('@cmd'));
        $this->assertSame('safe', CrmController::csv_cell('safe'));
        $this->assertSame('', CrmController::csv_cell(''));
        $this->assertSame('42', CrmController::csv_cell(42));
    }
}
