<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityPublicSiteSyncService;
use App\Libraries\Community\MockCommunityPublisher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The envelopes must satisfy aicountly.com's CommunityReceiverController
 * validation, which is a different vocabulary from Reach's: the receiver
 * accepts only ['answering','facilitation','moderation','curation','editorial']
 * as operational roles and only its own seeded community category slugs, and it
 * rejects official-bot content that does not declare ai_assisted + disclosure.
 */
final class CommunityPublicSiteSyncServiceTest extends TestCase
{
    /** Mirrors CommunityReceiverController::OPERATIONAL_ROLES on the site. */
    private const SITE_ROLES = ['answering', 'facilitation', 'moderation', 'curation', 'editorial'];

    /** Mirrors CommunityService::seedCategories() on the site. */
    private const SITE_CATEGORIES = [
        'gst', 'income-tax', 'tds-tcs', 'mca-company-law', 'audit-accounting',
        'payroll', 'banking-brs', 'saas-product-help', 'technical-api',
    ];

    private function service(): CommunityPublicSiteSyncService
    {
        // Construct without touching the database: only the pure envelope
        // builders are under test here.
        $reflection = new ReflectionClass(CommunityPublicSiteSyncService::class);
        /** @var CommunityPublicSiteSyncService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        $publisher = $reflection->getProperty('publisher');
        $publisher->setValue($service, new MockCommunityPublisher());

        return $service;
    }

    /** @return array<string,mixed> */
    private function identity(string $role = 'expert_answer_assistant'): array
    {
        return [
            'uuid'                 => '11111111-1111-1111-1111-111111111111',
            'slug'                 => 'aicountly-gst-guide',
            'public_slug'          => 'aicountly-gst-guide',
            'display_name'         => 'AICOUNTLY GST Guide',
            'short_description'    => 'GST registration, returns and compliance',
            'topic_specialisation' => 'GST',
            'operational_role'     => $role,
            'ai_disclosure'        => 'AI-assisted, reviewed by AICOUNTLY.',
            'is_active'            => true,
        ];
    }

    public function testIdentityEnvelopeMapsReachRolesOntoTheReceiverEnum(): void
    {
        $service = $this->service();

        $map = [
            'expert_answer_assistant' => 'answering',
            'thread_facilitator'      => 'facilitation',
            'question_curator'        => 'curation',
            'community_steward'       => 'moderation',
            'review_objection_desk'   => 'editorial',
        ];

        foreach ($map as $reachRole => $expected) {
            $envelope = $service->identityEnvelope($this->identity($reachRole));
            $role     = $envelope['payload']['operational_role'];

            $this->assertSame($expected, $role, "Reach role {$reachRole} must map to {$expected}");
            $this->assertContains($role, self::SITE_ROLES);
        }
    }

    public function testIdentityEnvelopeAlwaysCarriesADisclosure(): void
    {
        $identity = $this->identity();
        $identity['ai_disclosure'] = '';
        $identity['disclosure_template'] = '';

        $envelope = $this->service()->identityEnvelope($identity);

        $this->assertNotSame('', trim($envelope['payload']['ai_disclosure']));
    }

    public function testQuestionEnvelopeMapsCategoriesOntoSeededSiteSlugs(): void
    {
        $service = $this->service();

        $map = [
            'gst'            => 'gst',
            'tds-tcs'        => 'tds-tcs',
            'payroll-hr'     => 'payroll',
            'accounting'     => 'audit-accounting',
            'product-guides' => 'saas-product-help',
            'company-law'    => 'mca-company-law',
        ];

        foreach ($map as $reachCategory => $expected) {
            $envelope = $service->questionEnvelope(
                ['uuid' => 'q-uuid', 'title' => 'Is an e-way bill needed?', 'body' => 'Details', 'category' => $reachCategory],
                $this->identity(),
                'aicountly-gst-guide',
            );

            $slug = $envelope['payload']['category_slug'];
            $this->assertSame($expected, $slug, "Reach category {$reachCategory} must map to {$expected}");
            $this->assertContains($slug, self::SITE_CATEGORIES);
        }
    }

    public function testQuestionWithoutACategoryFallsBackToTheAnsweringDeskSubject(): void
    {
        $envelope = $this->service()->questionEnvelope(
            ['uuid' => 'q-uuid', 'title' => 'What is the tax audit turnover limit?', 'body' => '', 'category' => null],
            $this->identity(),
            'aicountly-income-tax-desk',
        );

        $this->assertSame('income-tax', $envelope['payload']['category_slug']);
    }

    public function testQuestionWithoutABodyStillSendsOneBecauseTheReceiverRequiresIt(): void
    {
        $envelope = $this->service()->questionEnvelope(
            ['uuid' => 'q-uuid', 'title' => 'Is an e-way bill needed for intra-state movement?', 'body' => '', 'category' => 'gst'],
            $this->identity(),
            'aicountly-gst-guide',
        );

        $this->assertNotSame('', trim($envelope['payload']['body']));
        $this->assertStringContainsString('e-way bill', $envelope['payload']['body']);
    }

    public function testAnswerEnvelopeDeclaresAiAssistanceAndDisclosure(): void
    {
        $envelope = $this->service()->answerEnvelope(
            ['uuid' => 'a-uuid', 'approved_version' => 3],
            ['content' => '<p>Answer body</p>', 'excerpt' => 'Short answer', 'version_number' => 3],
            ['uuid' => 'q-uuid'],
            'aicountly-gst-guide',
            $this->identity(),
        );

        // guardDisclosure() on the site rejects official-bot content without both.
        $this->assertTrue($envelope['payload']['ai_assisted']);
        $this->assertNotSame('', trim($envelope['payload']['ai_disclosure']));
        $this->assertSame('official_bot', $envelope['payload']['author_type']);
        $this->assertSame('q-uuid', $envelope['reach_question_uuid']);
        $this->assertSame(3, $envelope['reach_content_version_number']);
    }

    public function testEnvelopesCarryNoEngagementFieldsTheReceiverForbids(): void
    {
        $service = $this->service();

        $envelopes = [
            $service->identityEnvelope($this->identity()),
            $service->questionEnvelope(
                ['uuid' => 'q-uuid', 'title' => 'A question title', 'body' => 'Body', 'category' => 'gst'],
                $this->identity(),
                'aicountly-gst-guide',
            ),
            $service->answerEnvelope(
                ['uuid' => 'a-uuid', 'approved_version' => 1],
                ['content' => '<p>Body</p>', 'excerpt' => 'Excerpt'],
                ['uuid' => 'q-uuid'],
                'aicountly-gst-guide',
                $this->identity(),
            ),
        ];

        // A subset of CommunityReceiverController::ENGAGEMENT_KEYS — any of
        // these at any depth makes the whole request a 403.
        $forbidden = ['vote', 'votes', 'like', 'likes', 'helpful', 'follow', 'views', 'view_count', 'reputation', 'endorse'];

        foreach ($envelopes as $envelope) {
            foreach ($this->keysAtAnyDepth($envelope) as $key) {
                $this->assertNotContains(strtolower($key), $forbidden, "Envelope must not carry engagement key '{$key}'");
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function keysAtAnyDepth(array $node): array
    {
        $keys = [];
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysAtAnyDepth($value));
            }
        }

        return $keys;
    }
}
