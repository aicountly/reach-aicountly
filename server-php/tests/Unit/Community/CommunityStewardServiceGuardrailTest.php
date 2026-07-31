<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityStewardService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The plan requires community_steward to never vote/like/follow/inflate
 * views. Rather than trusting a runtime check that could be forgotten on a
 * future edit, this test asserts the *capability itself does not exist* on
 * the class — reflection over every public method name. If a future change
 * ever adds a vote()/like()/follow() method to this class, this test fails
 * the build before it can ship.
 */
final class CommunityStewardServiceGuardrailTest extends TestCase
{
    public function testNoPublicMethodCanVoteLikeFollowOrInflateEngagement(): void
    {
        $forbidden = ['/vote/i', '/like/i', '/follow/i', '/view.*count/i', '/inflate/i', '/upvote/i', '/downvote/i'];

        $methods = (new ReflectionClass(CommunityStewardService::class))->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            foreach ($forbidden as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $method->getName(),
                    "CommunityStewardService::{$method->getName()}() looks like an engagement-inflation capability, which is explicitly forbidden for this role"
                );
            }
        }
    }

    public function testHasExactlyTheThreeSanctionedCapabilities(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(CommunityStewardService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $methods = array_values(array_filter($methods, static fn ($n) => $n !== '__construct'));

        sort($methods);
        $this->assertSame(['categorize', 'flagModerationHint', 'linkRelated'], $methods);
    }
}
