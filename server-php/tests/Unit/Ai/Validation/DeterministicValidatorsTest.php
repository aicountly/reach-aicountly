<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Validation;

use App\Libraries\Ai\Validation\ValidationFinding;
use App\Libraries\Ai\Validation\Validators\TitleLengthValidator;
use App\Libraries\Ai\Validation\Validators\MetaDescriptionLengthValidator;
use App\Libraries\Ai\Validation\Validators\BodyMinimumLengthValidator;
use App\Libraries\Ai\Validation\Validators\SlugFormatValidator;
use App\Libraries\Ai\Validation\Validators\ClaimsReferencedValidator;
use App\Libraries\Ai\Validation\Validators\ProductClaimAccuracyValidator;
use App\Libraries\Ai\Validation\Validators\BrandVoiceValidator;
use App\Libraries\Ai\Validation\Validators\ContentPolicyValidator;
use App\Libraries\Ai\Validation\Validators\RiskNotesValidator;
use App\Libraries\Ai\Validation\Validators\HtmlSanitizationValidator;
use App\Libraries\Ai\Validation\Validators\CallToActionPresenceValidator;
use App\Libraries\Ai\Validation\Validators\HashtagCountValidator;
use App\Libraries\Ai\Validation\Validators\EmailSubjectLineLengthValidator;
use App\Libraries\Ai\Validation\Validators\FeatureAvailabilityValidator;
use App\Libraries\Ai\Validation\Validators\SummaryLengthValidator;
use App\Libraries\Ai\Validation\Validators\ReadabilityScoreValidator;
use App\Libraries\Ai\Validation\Validators\WordCountValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests deterministic validators (no AI or DB calls).
 */
class DeterministicValidatorsTest extends CIUnitTestCase
{
    private function firstFinding(array $findings): ValidationFinding
    {
        return $findings[0];
    }

    public function testTitleLengthPassesForNormalTitle(): void
    {
        $v = new TitleLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['title' => 'A good blog post title'], []))->status);
    }

    public function testTitleLengthFailsForEmpty(): void
    {
        $v = new TitleLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['title' => ''], []))->status);
    }

    public function testTitleLengthWarnsForLongTitle(): void
    {
        $v = new TitleLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(['title' => str_repeat('x', 121)], []))->status);
    }

    public function testMetaDescriptionLengthPassesInRange(): void
    {
        $v = new MetaDescriptionLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['meta_description' => str_repeat('x', 100)], []))->status);
    }

    public function testMetaDescriptionNotApplicableWhenAbsent(): void
    {
        $v = new MetaDescriptionLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_NOT_APPLICABLE, $this->firstFinding($v->validate([], []))->status);
    }

    public function testBodyMinimumLengthFailsForShortBody(): void
    {
        $v = new BodyMinimumLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['body_html' => '<p>Short</p>'], []))->status);
    }

    public function testBodyMinimumLengthPassesForLongBody(): void
    {
        $v = new BodyMinimumLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['body_plain_text' => str_repeat('word ', 60)], []))->status);
    }

    public function testSlugFormatPassesForValidSlug(): void
    {
        $v = new SlugFormatValidator();
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['slug_suggestion' => 'my-blog-post'], []))->status);
    }

    public function testSlugFormatFailsForInvalidSlug(): void
    {
        $v = new SlugFormatValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['slug_suggestion' => 'My Blog Post!!'], []))->status);
    }

    public function testClaimsReferencedWarnsWhenGroundingHasClaimsButNoneUsed(): void
    {
        $v       = new ClaimsReferencedValidator();
        $context = ['grounding' => ['claims' => [['id' => 1, 'text' => 'claim1']]]];
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(['claims_used' => []], $context))->status);
    }

    public function testProductClaimAccuracyFailsForHallucinatedClaimId(): void
    {
        $v       = new ProductClaimAccuracyValidator();
        $context = ['grounding' => ['claims' => [['id' => 1]]]];
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['claims_used' => ['999']], $context))->status);
    }

    public function testBrandVoiceFailsForForbiddenPhrase(): void
    {
        $v       = new BrandVoiceValidator();
        $context = ['grounding' => ['brand_rules' => [['rule_type' => 'forbidden_phrase', 'rule_value' => 'spam']]]];
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['body_plain_text' => 'This is spam content.'], $context))->status);
    }

    public function testBrandVoicePassesWithNoForbiddenPhrases(): void
    {
        $v       = new BrandVoiceValidator();
        $context = ['grounding' => ['brand_rules' => [['rule_type' => 'forbidden_phrase', 'rule_value' => 'spam']]]];
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['body_plain_text' => 'Clean professional content.'], $context))->status);
    }

    public function testContentPolicyFailsForBlockedKeyword(): void
    {
        $v       = new ContentPolicyValidator();
        $context = ['grounding' => ['content_policies' => [['rule_type' => 'blocked_keyword', 'rule_value' => 'illegal', 'slug' => 'no-illegal']]]];
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['body_plain_text' => 'Illegal activity here.'], $context))->status);
    }

    public function testRiskNotesWarnsWhenNotEmpty(): void
    {
        $v = new RiskNotesValidator();
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(['risk_notes' => ['Review pricing claim.']], []))->status);
    }

    public function testHtmlSanitizationFailsForScriptTag(): void
    {
        $v = new HtmlSanitizationValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['body_html' => '<p>Hello<script>alert(1)</script></p>'], []))->status);
    }

    public function testHtmlSanitizationPassesForCleanHtml(): void
    {
        $v = new HtmlSanitizationValidator();
        $this->assertSame(ValidationFinding::STATUS_PASSED, $this->firstFinding($v->validate(['body_html' => '<p>Clean content here.</p>'], []))->status);
    }

    public function testCtaWarnsForLandingPageWithoutCta(): void
    {
        $v = new CallToActionPresenceValidator();
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(['primary_cta' => ''], ['content_type' => 'landing_page']))->status);
    }

    public function testHashtagCountWarnsForTooManyTwitterHashtags(): void
    {
        $v = new HashtagCountValidator();
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(
            ['hashtags' => array_fill(0, 10, '#tag'), 'platform' => 'twitter'],
            ['content_type' => 'social_post']
        ))->status);
    }

    public function testEmailSubjectFailsWhenMissing(): void
    {
        $v = new EmailSubjectLineLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['subject_line' => ''], ['content_type' => 'email_campaign']))->status);
    }

    public function testFeatureAvailabilityFailsForPlannedMention(): void
    {
        $v = new FeatureAvailabilityValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['body_plain_text' => 'This is a coming soon feature.'], []))->status);
    }

    public function testWordCountWarnsForBlogPostBelowMinimum(): void
    {
        $v = new WordCountValidator();
        $this->assertSame(ValidationFinding::STATUS_WARNING, $this->firstFinding($v->validate(['body_plain_text' => 'Short.'], ['content_type' => 'blog_post']))->status);
    }

    public function testSummaryLengthFailsForEmpty(): void
    {
        $v = new SummaryLengthValidator();
        $this->assertSame(ValidationFinding::STATUS_FAILED, $this->firstFinding($v->validate(['summary' => ''], []))->status);
    }
}
