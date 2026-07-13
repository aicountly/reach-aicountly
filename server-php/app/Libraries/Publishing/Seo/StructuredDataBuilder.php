<?php

namespace App\Libraries\Publishing\Seo;

/**
 * Phase 4 — Generates approved JSON-LD structured data from approved content.
 *
 * Only approved content may be used as input.
 * Fake ratings, prices, or reviews are prohibited.
 */
class StructuredDataBuilder
{
    /**
     * Build a HowTo schema from knowledge-base steps.
     */
    public function buildHowTo(string $name, array $steps, ?string $description = null): array
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'HowTo',
            'name'        => $name,
        ];

        if ($description) {
            $schema['description'] = $description;
        }

        $schema['step'] = array_map(fn($s) => [
            '@type' => 'HowToStep',
            'name'  => $s['title'] ?? '',
            'text'  => $s['description'] ?? '',
        ], $steps);

        return $schema;
    }

    /**
     * Build a FAQPage schema from FAQ candidates.
     */
    public function buildFAQPage(array $faqItems): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(fn($f) => [
                '@type'          => 'Question',
                'name'           => $f['question'] ?? '',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $f['answer'] ?? '',
                ],
            ], $faqItems),
        ];
    }

    /**
     * Build a BreadcrumbList schema.
     */
    public function buildBreadcrumbs(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $item = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'] ?? '',
            ];
            if (!empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Build a WebPage schema.
     */
    public function buildWebPage(string $name, string $url, ?string $description = null): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebPage',
            'name'     => $name,
            'url'      => $url,
        ];

        if ($description) {
            $schema['description'] = $description;
        }

        return $schema;
    }
}
