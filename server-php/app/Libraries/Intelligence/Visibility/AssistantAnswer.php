<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

/**
 * One assistant's answer to one visibility prompt, as the provider's API
 * returned it. `citations` are the sources the provider itself attached
 * (Perplexity citations, Gemini grounding chunks, OpenAI url annotations) —
 * URLs written inside the answer text are extracted later by MentionParser.
 */
final class AssistantAnswer
{
    /**
     * @param list<array{url: string, title?: string}> $citations
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $text,
        public readonly array $citations = [],
        public readonly ?int $tokensUsed = null,
    ) {
    }
}
