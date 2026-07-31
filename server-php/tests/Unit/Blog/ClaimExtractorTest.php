<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Libraries\Blog\Verification\ClaimExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Libraries\Blog\Verification\ClaimExtractor
 */
final class ClaimExtractorTest extends TestCase
{
    public function testHeuristicFindsRateClaim(): void
    {
        $extractor = new ClaimExtractor();
        $claims    = $extractor->extractHeuristic(
            'The GST rate is 18% on most services. This applies nationwide.',
        );

        $this->assertNotEmpty($claims);
        $this->assertStringContainsString('18%', $claims[0]['text']);
        $this->assertSame('percentage', $claims[0]['claim_type']);
    }

    public function testHeuristicFlagsHighRiskForDueDate(): void
    {
        $extractor = new ClaimExtractor();
        $claims    = $extractor->extractHeuristic(
            'The filing due date for GSTR-3B is the 20th of each month.',
        );

        $this->assertNotEmpty($claims);
        $this->assertSame('HIGH', $claims[0]['risk']);
    }

    public function testHeuristicFlagsHighRiskForNotification(): void
    {
        $extractor = new ClaimExtractor();
        $claims    = $extractor->extractHeuristic(
            'As per notification no. 13/2024, the penalty provisions were updated.',
        );

        $this->assertNotEmpty($claims);
        $this->assertSame('HIGH', $claims[0]['risk']);
        $this->assertSame('statutory', $claims[0]['claim_type']);
    }
}
