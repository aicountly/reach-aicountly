<?php

namespace App\Libraries\Publishing\Seo;

/**
 * Phase 4 — Validates JSON-LD structured data objects.
 *
 * Only approved schema types are permitted.
 * Fake ratings, reviews, prices, or aggregate-rating schema are prohibited.
 */
class StructuredDataValidator
{
    public const ALLOWED_TYPES = [
        'Article', 'BlogPosting', 'TechArticle', 'HowTo',
        'FAQPage', 'BreadcrumbList', 'Organization', 'Person',
        'WebPage', 'SoftwareApplication',
    ];

    private const PROHIBITED_PROPERTIES = [
        'aggregateRating', 'review', 'offers', 'price', 'priceRange',
        'ratingValue', 'reviewRating', 'bestRating', 'worstRating',
    ];

    private const REQUIRED_FIELDS = [
        'Article'             => ['headline', 'author', 'datePublished'],
        'BlogPosting'         => ['headline', 'datePublished'],
        'TechArticle'         => ['headline', 'author', 'datePublished'],
        'HowTo'               => ['name', 'step'],
        'FAQPage'             => ['mainEntity'],
        'BreadcrumbList'      => ['itemListElement'],
        'Organization'        => ['name'],
        'Person'              => ['name'],
        'WebPage'             => ['name'],
        'SoftwareApplication' => ['name', 'applicationCategory'],
    ];

    /**
     * @return array{valid: bool, errors: array<int,string>}
     */
    public function validate(array $schema): array
    {
        $errors = [];

        // Type check
        $type = $schema['@type'] ?? '';
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $errors[] = "Schema type '{$type}' is not in the approved list";
            return ['valid' => false, 'errors' => $errors];
        }

        // Context check
        if (empty($schema['@context'])) {
            $errors[] = 'Missing @context';
        }

        // Required fields per type
        $required = self::REQUIRED_FIELDS[$type] ?? [];
        foreach ($required as $field) {
            if (empty($schema[$field])) {
                $errors[] = "Required field '{$field}' is missing for {$type}";
            }
        }

        // Prohibited properties
        foreach (self::PROHIBITED_PROPERTIES as $prop) {
            if (array_key_exists($prop, $schema)) {
                $errors[] = "Prohibited property '{$prop}' found — fake ratings/reviews/prices are not allowed";
            }
        }

        // URL consistency
        if (!empty($schema['url']) && !filter_var($schema['url'], FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid URL in 'url' field";
        }

        // Date format
        foreach (['datePublished', 'dateModified'] as $dateField) {
            if (!empty($schema[$dateField])) {
                if (strtotime($schema[$dateField]) === false) {
                    $errors[] = "Invalid date format in '{$dateField}'";
                }
            }
        }

        // FAQPage: each mainEntity must have name + acceptedAnswer
        if ($type === 'FAQPage' && !empty($schema['mainEntity'])) {
            foreach ($schema['mainEntity'] as $qi => $q) {
                if (empty($q['name'])) {
                    $errors[] = "FAQPage question #{$qi} missing 'name'";
                }
                if (empty($q['acceptedAnswer']['text'])) {
                    $errors[] = "FAQPage question #{$qi} missing acceptedAnswer.text";
                }
            }
        }

        // HowTo: steps must have required fields
        if ($type === 'HowTo' && !empty($schema['step'])) {
            foreach ($schema['step'] as $si => $step) {
                if (empty($step['text']) && empty($step['name'])) {
                    $errors[] = "HowTo step #{$si} missing both 'text' and 'name'";
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Validate a collection of schema objects.
     * @return array{valid: bool, errors: array<int,string>}
     */
    public function validateAll(array $schemas): array
    {
        $allErrors = [];
        foreach ($schemas as $i => $schema) {
            $result = $this->validate($schema);
            foreach ($result['errors'] as $err) {
                $allErrors[] = "Schema[{$i}]: {$err}";
            }
        }
        return ['valid' => empty($allErrors), 'errors' => $allErrors];
    }
}
