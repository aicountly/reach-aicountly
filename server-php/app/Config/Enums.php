<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Central registry of every enum-like string used across the API.
 *
 * Keeping these in one place prevents drift between:
 *   - migrations (CHECK constraints)
 *   - models (allowedFields validation)
 *   - controllers (payload validation)
 *   - services / policies
 *
 * Every controller that accepts one of these string values MUST validate
 * against the list here rather than hard-coding the accepted set inline.
 * When adding a new value, also update the corresponding migration's CHECK
 * constraint and the RBAC / audit docs.
 */
class Enums extends BaseConfig
{
    /**
     * Blog post workflow states — kept in sync with BlogController::WORKFLOW.
     */
    public array $blogStatus = [
        'idea', 'draft', 'seo_review', 'internal_review',
        'approved', 'scheduled', 'published', 'rejected', 'archived',
    ];

    /**
     * Approval subject types.
     */
    public array $approvalSubject = [
        'blog', 'campaign', 'social_post', 'email_campaign',
        'whatsapp_campaign', 'landing_page',
    ];

    /**
     * Approval outcomes.
     */
    public array $approvalDecision = ['pending', 'approved', 'rejected'];

    /**
     * Campaign lifecycle states.
     */
    public array $campaignStatus = [
        'draft', 'approved', 'scheduled', 'live', 'paused',
        'completed', 'rejected', 'archived',
    ];

    /**
     * Actor kinds tracked on actor-aware tables.
     */
    public array $actorType = ['human', 'system', 'bot', 'service'];

    /**
     * Job lifecycle states — matches reach_jobs.status CHECK constraint.
     */
    public array $jobStatus = [
        'pending', 'reserved', 'processing', 'completed',
        'failed', 'retry_wait', 'dead_letter', 'cancelled',
    ];

    /**
     * Rate-limit scope strings (matches RateLimits config keys).
     */
    public array $rateLimitScope = ['ip', 'user', 'ip+user', 'token'];

    /**
     * User permission override modes (reach_user_permissions.mode).
     */
    public array $permissionMode = ['grant', 'deny'];

    /**
     * Return true if the value is a member of the named enum. Unknown enum
     * names return false so typos in controllers fail closed.
     */
    public function isValid(string $enumName, mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }
        $list = $this->{$enumName} ?? null;
        if (! is_array($list)) {
            return false;
        }
        return in_array($value, $list, true);
    }

    /**
     * Return the enum list or throw for unknown names.
     */
    public function values(string $enumName): array
    {
        $list = $this->{$enumName} ?? null;
        if (! is_array($list)) {
            throw new \InvalidArgumentException("Unknown enum: $enumName");
        }
        return $list;
    }
}
