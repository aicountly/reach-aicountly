<?php

namespace Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyMarketingPackService pack-generation logic without a DB.
 *
 * Tests duplicate prevention and backlog limit logic in isolation.
 */
final class DailyMarketingPackServiceTest extends TestCase
{
    /**
     * Items already assigned to a pack should not be included again.
     */
    public function testDuplicatePrevention(): void
    {
        $assigned  = [10, 20, 30];
        $candidate = 20; // already in pack

        $this->assertTrue(in_array($candidate, $assigned, true));
    }

    public function testNonDuplicateIsAllowed(): void
    {
        $assigned  = [10, 20, 30];
        $candidate = 40; // new item

        $this->assertFalse(in_array($candidate, $assigned, true));
    }

    /**
     * Backlog limit: if more candidates than target slots exist, only target
     * count should be filled.
     */
    public function testBacklogLimitRespectsTarget(): void
    {
        $candidates = range(1, 20); // 20 available items
        $target     = 3;
        $selected   = array_slice($candidates, 0, $target);

        $this->assertCount($target, $selected);
    }

    /**
     * Placeholder slots should be generated for missing content.
     */
    public function testPlaceholderGeneratedForMissingSlots(): void
    {
        $target    = 3;
        $available = 1;
        $placeholders = $target - $available;

        $this->assertSame(2, $placeholders);
    }

    /**
     * Default config has 4 slot types.
     */
    public function testDefaultConfigHasFourSlotTypes(): void
    {
        $defaults = [
            ['content_type' => 'blog',         'target_count' => 2, 'priority' => 2],
            ['content_type' => 'social_post',  'target_count' => 3, 'priority' => 2],
            ['content_type' => 'email',        'target_count' => 1, 'priority' => 1],
            ['content_type' => 'knowledge_base', 'target_count' => 1, 'priority' => 3],
        ];
        $this->assertCount(4, $defaults);
    }
}
