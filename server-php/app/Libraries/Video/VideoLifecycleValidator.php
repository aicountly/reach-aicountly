<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Enums\VideoIdeaStatus;
use App\Enums\VideoProjectStatus;
use App\Enums\VideoScriptWorkflowStatus;
use App\Enums\VideoRenderJobStatus;

class VideoLifecycleValidator
{
    public function canTransitionIdea(string $fromStatus, string $toStatus): bool
    {
        $from = VideoIdeaStatus::tryFrom($fromStatus);
        $to   = VideoIdeaStatus::tryFrom($toStatus);

        if ($from === null || $to === null) {
            return false;
        }

        return $from->canTransitionTo($to);
    }

    public function canTransitionProject(string $fromStatus, string $toStatus): bool
    {
        $from = VideoProjectStatus::tryFrom($fromStatus);
        $to   = VideoProjectStatus::tryFrom($toStatus);

        if ($from === null || $to === null) {
            return false;
        }

        return $from->canTransitionTo($to);
    }

    public function canTransitionScript(string $fromStatus, string $toStatus): bool
    {
        $from = VideoScriptWorkflowStatus::tryFrom($fromStatus);
        $to   = VideoScriptWorkflowStatus::tryFrom($toStatus);

        if ($from === null || $to === null) {
            return false;
        }

        return $from->canTransitionTo($to);
    }

    public function canTransitionRenderJob(string $fromStatus, string $toStatus): bool
    {
        $from = VideoRenderJobStatus::tryFrom($fromStatus);
        $to   = VideoRenderJobStatus::tryFrom($toStatus);

        if ($from === null || $to === null) {
            return false;
        }

        return $from->canTransitionTo($to);
    }

    public function assertIdeaTransition(string $fromStatus, string $toStatus): void
    {
        if (! $this->canTransitionIdea($fromStatus, $toStatus)) {
            throw new \LogicException(
                "Invalid idea status transition: {$fromStatus} → {$toStatus}"
            );
        }
    }

    public function assertProjectTransition(string $fromStatus, string $toStatus): void
    {
        if (! $this->canTransitionProject($fromStatus, $toStatus)) {
            throw new \LogicException(
                "Invalid project status transition: {$fromStatus} → {$toStatus}"
            );
        }
    }

    public function assertScriptTransition(string $fromStatus, string $toStatus): void
    {
        if (! $this->canTransitionScript($fromStatus, $toStatus)) {
            throw new \LogicException(
                "Invalid script workflow status transition: {$fromStatus} → {$toStatus}"
            );
        }
    }

    public function assertRenderJobTransition(string $fromStatus, string $toStatus): void
    {
        if (! $this->canTransitionRenderJob($fromStatus, $toStatus)) {
            throw new \LogicException(
                "Invalid render job status transition: {$fromStatus} → {$toStatus}"
            );
        }
    }
}
