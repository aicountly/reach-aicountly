<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vetting;

use App\Libraries\Ai\Vetting\Rubrics;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Community\CommunityPublicRecordProvisioner;
use App\Libraries\Distribution\SocialInbox\SocialInboxClassifier;
use App\Libraries\Settings\BrandFacts;
use App\Libraries\Settings\BrandOffer;
use PHPUnit\Framework\TestCase;

/**
 * The vetting model must know Aicountly runs Aicountly Academy (so course
 * posts are not refused as unsupported) — and must still refuse the claims a
 * course post may never make: fee amounts, placement / job promises, a
 * degree. The brand is written "Aicountly".
 *
 * @covers \App\Libraries\Ai\Vetting\Rubrics
 * @covers \App\Libraries\Settings\BrandFacts
 * @covers \App\Libraries\Settings\BrandOffer
 *
 * @internal
 */
final class AcademyVettingContextTest extends TestCase
{
    public function testTheCompanyFactsCoverSoftwareAndTheAcademy(): void
    {
        $this->assertStringContainsString('accounting, compliance & business software', BrandFacts::COMPANY);
        $this->assertStringContainsString('runs Aicountly Academy — industry training for college students (Aicountly Edge long-term programme, Aicountly Aspire short-term courses)', BrandFacts::COMPANY);
        $this->assertStringContainsString('admissions, tests, project evaluation and certificates run by Aicountly', BrandFacts::COMPANY);

        $academy = BrandFacts::academy();
        $this->assertSame(['Aicountly Academy', 'Aicountly Edge', 'Aicountly Aspire'], array_column($academy['courses'], 'name'));
        $this->assertSame('https://www.aicountly.com/academy', $academy['courses'][0]['url']);
        foreach (['fee amount', 'placement', 'jobs', 'salaries', 'degree', 'not Beta'] as $rule) {
            $this->assertStringContainsString($rule, $academy['rules']);
        }
    }

    public function testEveryRubricKnowsTheAcademyAndKeepsTheClaimRules(): void
    {
        foreach (Rubrics::TYPES as $type) {
            $r = Rubrics::for($type);
            $this->assertStringContainsString('runs Aicountly Academy', $r, $type);
            $this->assertStringContainsString('no promise or hint of placement, jobs, internships, salaries or exam results', $r, $type);
            $this->assertStringContainsString('no fee amounts, discounts or "free" unless the context states them', $r, $type);
            $this->assertStringContainsString('never "AICOUNTLY"', $r, $type);
            // The general rules are untouched.
            $this->assertStringContainsString('No guarantees or promises of outcomes', $r, $type);
            $this->assertStringContainsString('No fabricated statistics', $r, $type);
        }
        $this->assertStringContainsString("context's `offer`", Rubrics::for('social_post'));
    }

    public function testTheDefaultOfferAddsTheAcademyWithoutAnyFeeClaim(): void
    {
        $this->assertStringContainsString('Signing up at aicountly.com is free', BrandOffer::DEFAULT);
        $this->assertStringContainsString('There is no free trial', BrandOffer::DEFAULT);
        $this->assertStringEndsWith(BrandOffer::ACADEMY, BrandOffer::DEFAULT);
        $this->assertStringContainsString('aicountly.com/academy', BrandOffer::ACADEMY);
        $this->assertStringContainsString('never state a fee amount, a discount, "free" or any placement, job or salary promise', BrandOffer::ACADEMY);
        $this->assertDoesNotMatchRegularExpression('/₹|\bRs\.?\s?\d|\bINR\b/u', BrandOffer::ACADEMY);
    }

    public function testABlogAboutACourseCarriesTheCourseRulesToVetting(): void
    {
        $m = new \ReflectionMethod(RoutineContentPipeline::class, 'blogTaxonomyContext');
        $m->setAccessible(true);

        $edge = $m->invoke(null, ['category' => 'accounting', 'product_slug' => 'edge']);
        $this->assertStringStartsWith('Aicountly Edge — an Aicountly Academy course', $edge['about_app']);
        $this->assertStringContainsString('do not call it Beta', $edge['about_app']);
        $this->assertStringContainsString('placement', $edge['about_app']);

        $hrms = $m->invoke(null, ['category' => 'payroll-hr', 'product_slug' => 'hrms']);
        $this->assertStringContainsString('a Beta app: the article must present it as Beta', $hrms['about_app']);
        $books = $m->invoke(null, ['category' => 'accounting', 'product_slug' => 'books']);
        $this->assertSame('Smart Books — a live app', $books['about_app']);
    }

    public function testSocialInboxTreatsCourseEnquiriesAsLeads(): void
    {
        $rubric = (new \ReflectionClass(SocialInboxClassifier::class))->getConstant('RUBRIC');
        $this->assertStringContainsString('Aicountly Academy', $rubric);
        $this->assertStringContainsString('join an Aicountly Academy course', $rubric);
        $this->assertStringContainsString('End with "— Aicountly team"', $rubric);
    }

    public function testIdentitySyncWritesTheBrandInMixedCase(): void
    {
        $this->assertSame('Aicountly GST Guide', CommunityPublicRecordProvisioner::brandCase('AICOUNTLY GST Guide'));
        $this->assertSame('AI-assisted Aicountly community contributor', CommunityPublicRecordProvisioner::brandCase('AI-assisted AICOUNTLY community contributor'));
        $this->assertSame('Aicountly Interactive Services Private Limited · aicountly.com', CommunityPublicRecordProvisioner::brandCase('Aicountly Interactive Services Private Limited · aicountly.com'));
        $this->assertSame('MyAICOUNTLYClone', CommunityPublicRecordProvisioner::brandCase('MyAICOUNTLYClone'), 'only the bare word');
        $this->assertStringContainsString('by the Aicountly desk', CommunityPublicRecordProvisioner::DESK_DISCLOSURE);
    }
}
