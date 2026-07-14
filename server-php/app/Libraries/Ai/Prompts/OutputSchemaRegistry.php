<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

/**
 * Phase 3 + Phase 5 — JSON Schema definitions for all 26 content types.
 *
 * Phase 3 added 16 governed schemas (blog_post … generic).
 * Phase 5 added 10 community_answer.* schemas, all satisfying the global
 * registry contract: every schema requires claims_used, citations_used,
 * and risk_notes.
 *
 * These schemas are used to:
 * 1. Validate structured output from AI providers.
 * 2. Populate prompt version output_schema_json fields.
 *
 * All schemas use JSON Schema draft-07 compatible syntax.
 */
class OutputSchemaRegistry
{
    private static array $schemas = [];

    public static function get(string $contentType): array
    {
        if (empty(self::$schemas)) {
            self::$schemas = self::buildAll();
        }

        return self::$schemas[$contentType]
            ?? self::$schemas['generic']
            ?? self::genericSchema();
    }

    public static function allTypes(): array
    {
        return [
            'blog_post', 'landing_page', 'social_post', 'email_campaign',
            'case_study', 'whitepaper', 'product_description', 'faq',
            'press_release', 'newsletter', 'ad_copy', 'video_script',
            'seo_meta', 'knowledge_base', 'testimonial', 'generic',
            // Phase 5 — Community official answer types
            'community_answer.concise',
            'community_answer.detailed',
            'community_answer.troubleshooting',
            'community_answer.product_feature',
            'community_answer.compliance',
            'community_answer.clarification',
            'community_answer.duplicate_response',
            'community_answer.correction',
            'community_answer.summary',
            'community_answer.translation',
        ];
    }

    public static function has(string $contentType): bool
    {
        return in_array($contentType, self::allTypes(), true);
    }

    private static function buildAll(): array
    {
        $base  = self::baseContentFields();
        $seoMeta = self::seoFields();

        return [
            'blog_post' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text', 'slug_suggestion', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'reading_time_minutes' => ['type' => 'integer', 'minimum' => 1],
                    'sections'             => ['type' => 'array', 'items' => ['type' => 'object']],
                ]),
                'additionalProperties' => false,
            ],

            'landing_page' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'hero_headline', 'hero_subheadline', 'body_sections_json', 'primary_cta', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'hero_headline'       => ['type' => 'string', 'maxLength' => 120],
                    'hero_subheadline'    => ['type' => 'string', 'maxLength' => 240],
                    'body_sections_json'  => ['type' => 'array', 'items' => ['type' => 'object']],
                    'secondary_cta'       => ['type' => 'string', 'maxLength' => 80],
                ]),
                'additionalProperties' => false,
            ],

            'social_post' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_plain_text', 'platform', 'hashtags', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'body_plain_text' => ['type' => 'string', 'maxLength' => 3000],
                    'platform'        => ['type' => 'string', 'enum' => ['linkedin', 'twitter', 'facebook', 'instagram', 'generic']],
                    'hashtags'        => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
                    'character_count' => ['type' => 'integer'],
                ]),
                'additionalProperties' => false,
            ],

            'email_campaign' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'subject_line', 'preview_text', 'body_html', 'body_plain_text', 'primary_cta', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'subject_line'    => ['type' => 'string', 'maxLength' => 150],
                    'preview_text'    => ['type' => 'string', 'maxLength' => 200],
                    'body_html'       => ['type' => 'string'],
                    'body_plain_text' => ['type' => 'string'],
                    'primary_cta'     => ['type' => 'string', 'maxLength' => 80],
                ]),
                'additionalProperties' => false,
            ],

            'case_study' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text', 'slug_suggestion', 'meta_title', 'meta_description', 'challenge', 'solution', 'results', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'challenge' => ['type' => 'string'],
                    'solution'  => ['type' => 'string'],
                    'results'   => ['type' => 'array', 'items' => ['type' => 'string']],
                ]),
                'additionalProperties' => false,
            ],

            'whitepaper' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'executive_summary', 'body_html', 'body_markdown', 'body_plain_text', 'slug_suggestion', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'executive_summary' => ['type' => 'string'],
                    'table_of_contents' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]),
                'additionalProperties' => false,
            ],

            'product_description' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_html', 'body_plain_text', 'features_list', 'primary_cta', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'features_list' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'benefits_list' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]),
                'additionalProperties' => false,
            ],

            'faq' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'faq_items', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'faq_items' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'required'   => ['question', 'answer'],
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'answer'   => ['type' => 'string'],
                            ],
                        ],
                    ],
                ]),
                'additionalProperties' => false,
            ],

            'press_release' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_html', 'body_plain_text', 'headline', 'dateline', 'boilerplate', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'body_html'       => ['type' => 'string'],
                    'body_plain_text' => ['type' => 'string'],
                    'headline'        => ['type' => 'string', 'maxLength' => 200],
                    'dateline'        => ['type' => 'string'],
                    'boilerplate'     => ['type' => 'string'],
                ]),
                'additionalProperties' => false,
            ],

            'newsletter' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'subject_line', 'preview_text', 'body_html', 'body_plain_text', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'subject_line'    => ['type' => 'string', 'maxLength' => 150],
                    'preview_text'    => ['type' => 'string', 'maxLength' => 200],
                    'body_html'       => ['type' => 'string'],
                    'body_plain_text' => ['type' => 'string'],
                    'sections'        => ['type' => 'array', 'items' => ['type' => 'object']],
                ]),
                'additionalProperties' => false,
            ],

            'ad_copy' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'headline', 'body_plain_text', 'primary_cta', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'headline'        => ['type' => 'string', 'maxLength' => 80],
                    'body_plain_text' => ['type' => 'string', 'maxLength' => 500],
                    'display_url'     => ['type' => 'string'],
                    'platform'        => ['type' => 'string'],
                ]),
                'additionalProperties' => false,
            ],

            'video_script' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_plain_text', 'scenes', 'target_duration_seconds', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'body_plain_text'         => ['type' => 'string'],
                    'scenes'                  => ['type' => 'array', 'items' => ['type' => 'object']],
                    'target_duration_seconds' => ['type' => 'integer', 'minimum' => 10],
                ]),
                'additionalProperties' => false,
            ],

            'seo_meta' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'meta_title', 'meta_description', 'focus_keyword', 'canonical_url_suggestion', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'focus_keyword'              => ['type' => 'string'],
                    'secondary_keywords'          => ['type' => 'array', 'items' => ['type' => 'string']],
                    'canonical_url_suggestion'   => ['type' => 'string'],
                    'schema_markup_json'         => ['type' => 'object'],
                ]),
                'additionalProperties' => false,
            ],

            'knowledge_base' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text', 'slug_suggestion', 'meta_title', 'meta_description', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, $seoMeta, [
                    'related_articles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'tags'             => ['type' => 'array', 'items' => ['type' => 'string']],
                ]),
                'additionalProperties' => false,
            ],

            'testimonial' => [
                'type'       => 'object',
                'required'   => ['title', 'summary', 'body_plain_text', 'customer_name', 'customer_title', 'claims_used', 'citations_used', 'risk_notes'],
                'properties' => array_merge($base, [
                    'body_plain_text' => ['type' => 'string', 'maxLength' => 2000],
                    'customer_name'   => ['type' => 'string'],
                    'customer_title'  => ['type' => 'string'],
                    'product_id'      => ['type' => ['string', 'null']],
                    'star_rating'     => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 5],
                ]),
                'additionalProperties' => false,
            ],

            'generic' => self::genericSchema(),

            // Phase 5 — Community official answer types
            'community_answer.concise'           => self::communityAnswerSchema('concise'),
            'community_answer.detailed'          => self::communityAnswerSchema('detailed'),
            'community_answer.troubleshooting'   => self::communityAnswerSchema('troubleshooting'),
            'community_answer.product_feature'   => self::communityAnswerSchema('product_feature'),
            'community_answer.compliance'        => self::communityAnswerSchema('compliance'),
            'community_answer.clarification'     => self::communityAnswerSchema('clarification'),
            'community_answer.duplicate_response' => self::communityAnswerSchema('duplicate_response'),
            'community_answer.correction'        => self::communityAnswerSchema('correction'),
            'community_answer.summary'           => self::communityAnswerSchema('summary'),
            'community_answer.translation'       => self::communityAnswerSchema('translation'),
        ];
    }

    private static function baseContentFields(): array
    {
        return [
            'title'           => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
            'summary'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
            'primary_cta'     => ['type' => ['string', 'null'], 'maxLength' => 80],
            'claims_used'     => ['type' => 'array', 'items' => ['type' => 'string']],
            'citations_used'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'risk_notes'      => ['type' => 'array', 'items' => ['type' => 'string']],
        ];
    }

    private static function seoFields(): array
    {
        return [
            'slug_suggestion' => ['type' => ['string', 'null'], 'maxLength' => 256],
            'meta_title'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            'meta_description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 320],
            'body_html'       => ['type' => 'string'],
            'body_markdown'   => ['type' => 'string'],
            'body_plain_text' => ['type' => 'string'],
        ];
    }

    private static function genericSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['title', 'summary', 'claims_used', 'citations_used', 'risk_notes'],
            'properties'           => array_merge(self::baseContentFields(), [
                'body_html'       => ['type' => ['string', 'null']],
                'body_markdown'   => ['type' => ['string', 'null']],
                'body_plain_text' => ['type' => ['string', 'null']],
            ]),
            'additionalProperties' => true,
        ];
    }

    // =========================================================================
    // Phase 5 — Community answer schemas
    // =========================================================================

    private static function communityAnswerSchema(string $type): array
    {
        return [
            'type'     => 'object',
            // Every community schema must satisfy the global registry contract:
            //   claims_used  — the approved knowledge claims the AI relied on
            //   risk_notes   — flags, caveats, or reviewer notes from the AI
            //   citations_used — source IDs cited in the answer body
            // Plus community-specific required fields.
            'required' => [
                // ── Global registry contract (matches Phase 0–4 schemas) ──
                'claims_used',
                'citations_used',
                'risk_notes',
                // ── Community answer specifics ──
                'answer_title', 'answer_body', 'short_answer',
                'source_references', 'risk_classification',
                'limitations', 'recommended_disclosure',
                'requires_professional_review', 'requires_legal_review',
                'requires_product_review',
            ],
            'properties' => [
                // ── Global registry contract fields ──
                'claims_used' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'claim_id'   => ['type' => ['integer', 'string']],
                            'claim_text' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        ],
                    ],
                    'description' => 'Approved AICOUNTLY claims this answer relies on.',
                ],
                'citations_used' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Source IDs cited in the answer body.',
                ],
                'risk_notes' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Reviewer flags, caveats, or compliance notes.',
                ],
                // ── Community answer specifics ──
                'answer_title' => [
                    'type' => 'string', 'minLength' => 5, 'maxLength' => 512,
                ],
                'answer_body' => [
                    'type' => 'string', 'minLength' => 50,
                ],
                'short_answer' => [
                    'type' => 'string', 'minLength' => 10, 'maxLength' => 300,
                ],
                'clarifying_questions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'source_references' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'source_type'     => ['type' => 'string'],
                            'source_id'       => ['type' => ['integer', 'string', 'null']],
                            'source_title'    => ['type' => 'string'],
                            'claim_supported' => ['type' => 'string'],
                        ],
                    ],
                ],
                'product_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'risk_classification' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'critical'],
                ],
                'jurisdiction' => ['type' => ['string', 'null']],
                'limitations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'recommended_disclosure'      => ['type' => 'string'],
                'requires_professional_review' => ['type' => 'boolean'],
                'requires_legal_review'        => ['type' => 'boolean'],
                'requires_product_review'      => ['type' => 'boolean'],
                'answer_type' => ['type' => 'string', 'enum' => array_map(
                    fn($t) => str_replace('community_answer.', '', $t),
                    array_filter(self::allTypes(), fn($t) => str_starts_with($t, 'community_answer.'))
                )],
            ],
            'additionalProperties' => false,
        ];
    }
}
