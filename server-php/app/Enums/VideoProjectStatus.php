<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoProjectStatus: string
{
    case Draft               = 'draft';
    case ScriptGenerating    = 'script_generating';
    case ScriptDraft         = 'script_draft';
    case ScriptInReview      = 'script_in_review';
    case ScriptApproved      = 'script_approved';
    case RenderQueued        = 'render_queued';
    case Rendering           = 'rendering';
    case Rendered            = 'rendered';
    case PublishQueued       = 'publish_queued';
    case Publishing          = 'publishing';
    case Published           = 'published';
    case GenerationFailed    = 'generation_failed';
    case ValidationBlocked   = 'validation_blocked';
    case ChangesRequested    = 'changes_requested';
    case RenderFailed        = 'render_failed';
    case PublishFailed       = 'publish_failed';
    case Cancelled           = 'cancelled';
    case Withdrawn           = 'withdrawn';

    /** @return array<self> */
    public static function validTransitions(self $from): array
    {
        return match ($from) {
            self::Draft            => [self::ScriptGenerating, self::Cancelled],
            self::ScriptGenerating => [self::ScriptDraft, self::GenerationFailed, self::Cancelled],
            self::ScriptDraft      => [self::ScriptInReview, self::ScriptGenerating, self::Cancelled, self::Withdrawn],
            self::ScriptInReview   => [
                self::ScriptApproved, self::ChangesRequested, self::ScriptDraft,
                self::ValidationBlocked, self::Cancelled, self::Withdrawn,
            ],
            self::ScriptApproved   => [self::RenderQueued, self::Cancelled, self::Withdrawn],
            self::RenderQueued     => [self::Rendering, self::Cancelled],
            self::Rendering        => [self::Rendered, self::RenderFailed, self::Cancelled],
            self::Rendered         => [self::PublishQueued, self::Cancelled, self::Withdrawn],
            self::PublishQueued    => [self::Publishing, self::Cancelled],
            self::Publishing       => [self::Published, self::PublishFailed],
            self::Published        => [self::Withdrawn],
            self::GenerationFailed => [self::ScriptGenerating, self::Cancelled, self::Withdrawn],
            self::ValidationBlocked => [self::ScriptDraft, self::Cancelled, self::Withdrawn],
            self::ChangesRequested  => [self::ScriptDraft, self::Cancelled, self::Withdrawn],
            self::RenderFailed      => [self::RenderQueued, self::Cancelled, self::Withdrawn],
            self::PublishFailed     => [self::PublishQueued, self::Cancelled, self::Withdrawn],
            self::Cancelled         => [],
            self::Withdrawn         => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitions($this), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Cancelled, self::Withdrawn], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
