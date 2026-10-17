<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

/**
 * An AI assistant the visibility checks put buyer questions to.
 */
interface AssistantClient
{
    /** Stable key stored on runs: gemini | openai | perplexity. */
    public function key(): string;

    /** Human label for the UI ("Gemini", "ChatGPT (OpenAI API)", …). */
    public function label(): string;

    public function model(): string;

    /** Null when ready; otherwise why it cannot run (shown as "skipped"). */
    public function unavailableReason(): ?string;

    /** @throws AssistantException */
    public function ask(string $question): AssistantAnswer;
}
