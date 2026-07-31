<?php

namespace App\Libraries\Blog\Roadmap;

/**
 * Configurable factor weights for topic scoring (must sum to 100).
 */
class ScoringWeights
{
    /** @var array<string,int> */
    private const FACTOR_KEYS = [
        'search_opportunity',
        'product_priority',
        'audience_problem',
        'conversion_potential',
        'content_gap',
        'seasonality',
        'internal_link_value',
        'evidence_readiness',
    ];

    public function __construct(
        public int $searchOpportunity = 20,
        public int $productPriority = 20,
        public int $audienceProblem = 15,
        public int $conversionPotential = 15,
        public int $contentGap = 10,
        public int $seasonality = 10,
        public int $internalLinkValue = 5,
        public int $evidenceReadiness = 5,
        /** @var array<string,float|int> */
        public array $deductions = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $deductions = $row['deductions_json'] ?? $row['deductions'] ?? [];
        if (is_string($deductions)) {
            $decoded = json_decode($deductions, true);
            $deductions = is_array($decoded) ? $decoded : [];
        }

        return new self(
            searchOpportunity: (int) ($row['search_opportunity'] ?? 20),
            productPriority: (int) ($row['product_priority'] ?? 20),
            audienceProblem: (int) ($row['audience_problem'] ?? 15),
            conversionPotential: (int) ($row['conversion_potential'] ?? 15),
            contentGap: (int) ($row['content_gap'] ?? 10),
            seasonality: (int) ($row['seasonality'] ?? 10),
            internalLinkValue: (int) ($row['internal_link_value'] ?? 5),
            evidenceReadiness: (int) ($row['evidence_readiness'] ?? 5),
            deductions: array_map('floatval', $deductions),
        );
    }

    public static function defaults(): self
    {
        return new self(
            deductions: [
                'cannibalisation'              => 15,
                'near_duplicate'               => 25,
                'weak_evidence'                => 10,
                'product_feature_unavailable'  => 20,
                'recently_generated'           => 10,
                'portfolio_over_concentration' => 15,
            ],
        );
    }

    /**
     * @return array<string,int|array<string,float|int>>
     */
    public function toArray(): array
    {
        return [
            'search_opportunity'   => $this->searchOpportunity,
            'product_priority'     => $this->productPriority,
            'audience_problem'     => $this->audienceProblem,
            'conversion_potential' => $this->conversionPotential,
            'content_gap'          => $this->contentGap,
            'seasonality'          => $this->seasonality,
            'internal_link_value'  => $this->internalLinkValue,
            'evidence_readiness'   => $this->evidenceReadiness,
            'deductions'           => $this->deductions,
        ];
    }

    public function sum(): int
    {
        return $this->searchOpportunity
            + $this->productPriority
            + $this->audienceProblem
            + $this->conversionPotential
            + $this->contentGap
            + $this->seasonality
            + $this->internalLinkValue
            + $this->evidenceReadiness;
    }

    /**
     * @throws \InvalidArgumentException when any weight is negative
     */
    public function assertValid(): void
    {
        foreach (self::FACTOR_KEYS as $key) {
            $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
            $value = $this->{$camel} ?? null;
            if (! is_int($value) || $value < 0) {
                throw new \InvalidArgumentException("Weight {$key} must be a non-negative integer.");
            }
        }

        foreach ($this->deductions as $name => $penalty) {
            if ((float) $penalty < 0) {
                throw new \InvalidArgumentException("Deduction {$name} must be non-negative.");
            }
        }
    }

    public function weightForFactor(string $factor): int
    {
        return match ($factor) {
            'search_opportunity'   => $this->searchOpportunity,
            'product_priority'     => $this->productPriority,
            'audience_problem'     => $this->audienceProblem,
            'conversion_potential' => $this->conversionPotential,
            'content_gap'          => $this->contentGap,
            'seasonality'          => $this->seasonality,
            'internal_link_value'  => $this->internalLinkValue,
            'evidence_readiness'   => $this->evidenceReadiness,
            default                => 0,
        };
    }
}
