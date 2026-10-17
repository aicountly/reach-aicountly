<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Automation\Structured\ProductCatalog;
use App\Libraries\Automation\Structured\ReleaseVersion;
use App\Libraries\Automation\Structured\StructuredPayloadValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Automation\Structured\ReleaseVersion
 * @covers \App\Libraries\Automation\Structured\StructuredPayloadValidator
 * @covers \App\Libraries\Automation\Structured\ProductCatalog
 */
final class StructuredContentRulesTest extends CIUnitTestCase
{
    public function testVersionsParseCompareAndCanonicalise(): void
    {
        $this->assertSame('3.5', (string) ReleaseVersion::parse('3.5'));
        $this->assertSame('3.5', (string) ReleaseVersion::parse('3.5.0'), 'a zero patch is dropped');
        $this->assertSame('3.4.1', (string) ReleaseVersion::parse('3.4.1'));
        foreach (['', '3', 'v3.5', '3.5.1.2', '03.5', '3.x', '3.5-beta'] as $bad) {
            $this->assertNull(ReleaseVersion::parse($bad), $bad);
        }
        $this->assertSame(1, ReleaseVersion::parse('3.10')->compare(ReleaseVersion::parse('3.9')), 'numeric, not string, order');
        $this->assertSame(0, ReleaseVersion::parse('2.0')->compare(ReleaseVersion::parse('2.0.0')));
        $this->assertSame(-1, ReleaseVersion::parse('3.4.9')->compare(ReleaseVersion::parse('3.5')));
    }

    public function testOnlyTheNextFixFeatureOrMajorStepIsAllowed(): void
    {
        $from = ReleaseVersion::parse('3.4');
        $this->assertSame(['fix' => '3.4.1', 'feature' => '3.5', 'major' => '4.0'], $from->nextSteps());
        $this->assertTrue(ReleaseVersion::parse('3.4.1')->isNextStepFrom($from));
        $this->assertTrue(ReleaseVersion::parse('3.5')->isNextStepFrom($from));
        $this->assertTrue(ReleaseVersion::parse('4.0')->isNextStepFrom($from));
        $this->assertFalse(ReleaseVersion::parse('3.6')->isNextStepFrom($from), 'skipping a minor');
        $this->assertFalse(ReleaseVersion::parse('3.4.2')->isNextStepFrom($from));
        $this->assertFalse(ReleaseVersion::parse('3.4')->isNextStepFrom($from), 'repeat');
        $this->assertFalse(ReleaseVersion::parse('3.3')->isNextStepFrom($from), 'backwards');
        $this->assertSame(['fix' => '3.4.2', 'feature' => '3.5', 'major' => '4.0'], ReleaseVersion::parse('3.4.1')->nextSteps());
    }

    public function testReleaseSlugsFollowTheHandWrittenStyle(): void
    {
        $this->assertSame('smart-books-v2-1', ReleaseVersion::parse('2.1')->slug(ProductCatalog::releaseSlugPrefix('books')));
        $this->assertSame('billing-v1-0-1', ReleaseVersion::parse('1.0.1')->slug(ProductCatalog::releaseSlugPrefix('billing')));
    }

    public function testCatalogueListsLaunchAppsFirstWithTheirRepositories(): void
    {
        $all = ProductCatalog::all();
        $this->assertSame(['books', 'notes', 'billing', 'inventory', 'docs', 'manage'], array_column(array_slice($all, 0, 6), 'slug'));
        $this->assertTrue($all[0]['launch']);
        $this->assertFalse($all[6]['launch']);
        $repos = array_column($all, 'repo', 'slug');
        $this->assertSame('books-react-app', $repos['books']);
        $this->assertSame('Inventory-aicountly', $repos['inventory']);
        $this->assertSame('drive-react-app', $repos['docs']);
        $this->assertSame('manage-aicountly', $repos['manage']);
        $this->assertSame('hrms-react-app', $repos['hrms']);
        $this->assertSame('2.0', ProductCatalog::get('books')['hand_release']['version']);
        $this->assertNull(ProductCatalog::get('billing')['hand_release']);
        $this->assertStringEndsWith('/guides/smart-books/record-a-sales-invoice', ProductCatalog::guideUrl('books', 'record-a-sales-invoice'));
        $this->assertFalse(ProductCatalog::has('not-a-product'));
    }

    public function testReleaseNotePayloadIsCheckedLikeTheReceiver(): void
    {
        [$clean, $errors] = StructuredPayloadValidator::validate('release_note', [
            'slug' => 'billing-v1-1', 'product_slug' => 'billing', 'version' => '1.1',
            'title' => '<b>Billing 1.1</b>', 'date_iso' => '2026-10-17', 'intro' => "Line one\r\nLine two",
            'sections' => [['heading' => 'New', 'bullets' => ['Day-close dashboard.']], ['heading' => 'Fixed', 'paragraphs' => ['Rounding.']]],
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('Billing 1.1', $clean['title'], 'markup stripped');
        $this->assertSame("Line one\nLine two", $clean['intro']);
        $this->assertSame(['heading' => 'Fixed', 'paragraphs' => ['Rounding.']], $clean['sections'][1]);

        [$clean, $errors] = StructuredPayloadValidator::validate('release_note', [
            'slug' => 'Bad Slug', 'product_slug' => 'nope', 'version' => '1.1', 'title' => str_repeat('x', 201),
            'date_iso' => '2026-02-30', 'intro' => 'x', 'sections' => [['heading' => 'Empty']],
        ]);
        $this->assertNull($clean);
        $joined = implode("\n", $errors);
        foreach (['slug:', 'product_slug:', 'title: at most 200', 'date_iso:', 'sections[0]: needs at least one entry'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
    }

    public function testWorkflowGuidePayloadIsCheckedLikeTheReceiver(): void
    {
        $guide = [
            'product_slug' => 'books', 'slug' => 'record-a-sales-invoice', 'title' => 'Record a sales invoice',
            'blurb' => 'Raise a GST invoice.', 'audience' => 'Business owners', 'outcome' => 'A saved invoice.',
            'steps' => [['title' => 'Open Sales', 'text' => 'Go to Vouchers → Sales.', 'tip' => '', 'image_url' => 'https://cdn.example.com/a.png']],
            'faqs' => [['q' => 'Can I edit it?', 'a' => 'Yes.']],
            'diagram_mermaid' => "%%{init: {}}%%\nflowchart LR\n  A[<b>Open</b>] --> B\n  click A callback",
        ];
        [$clean, $errors] = StructuredPayloadValidator::validate('workflow_guide', $guide);
        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('tip', $clean['steps'][0], 'an empty tip is dropped');
        $this->assertSame([], $clean['prerequisites']);
        $this->assertStringStartsWith('flowchart LR', $clean['diagram_mermaid']);
        $this->assertStringNotContainsString('click', $clean['diagram_mermaid']);
        $this->assertArrayNotHasKey('image_url', $clean, 'optional fields are left out when absent');

        [, $errors] = StructuredPayloadValidator::validate('workflow_guide', ['steps' => [], 'image_url' => 'http://insecure.example.com/x.png', 'diagram_mermaid' => 'pie title x'] + $guide);
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('steps: required', $joined);
        $this->assertStringContainsString('image_url: must be an absolute https://', $joined);
        $this->assertStringContainsString('diagram_mermaid: must start with', $joined);
    }
}
