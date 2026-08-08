<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\BrainService;
use PHPUnit\Framework\TestCase;

final class BrainServiceTest extends TestCase
{
    public function test_normalize_collapses_whitespace_and_newlines(): void
    {
        $in = "Line one\r\n\r\n\r\n\r\nLine two\t\t with   spaces";
        $out = BrainService::normalize($in);
        $this->assertSame("Line one\n\nLine two with spaces", $out);
    }

    public function test_chunk_respects_target_size_and_overlaps(): void
    {
        $words = [];
        for ($i = 0; $i < 1000; $i++) $words[] = "w{$i}";
        $text = implode(' ', $words);
        $chunks = BrainService::chunk($text, 400); // ~280 words per chunk
        $this->assertGreaterThan(2, count($chunks));
        // Each chunk has words.
        foreach ($chunks as $c) {
            $this->assertNotEmpty(trim($c));
            $this->assertLessThanOrEqual(300, str_word_count($c));
        }
        // Adjacent chunks should share a non-trivial overlap window.
        // Overlap is configured at ~15% of chunk size, so the last ~40 words
        // of chunk N should also appear inside the first ~80 words of N+1.
        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $a = explode(' ', $chunks[$i]);
            $b = explode(' ', $chunks[$i + 1]);
            $tail_of_a = array_slice($a, -60);
            $head_of_b = array_slice($b, 0, 80);
            $this->assertNotEmpty(array_intersect($tail_of_a, $head_of_b),
                "Chunks $i and " . ($i + 1) . " should overlap");
        }
    }

    public function test_chunk_with_short_text_returns_single_chunk(): void
    {
        $chunks = BrainService::chunk('Hello world', 400);
        $this->assertCount(1, $chunks);
        $this->assertSame('Hello world', $chunks[0]);
    }
}
