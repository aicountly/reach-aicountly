<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Content sanitisation policy for rich-text fields (blog, campaign, landing).
 *
 * Only a small, deliberately restrictive HTML subset is allowed. Scripts,
 * event handlers, inline styles, iframes, and `javascript:` URLs are stripped
 * by HTMLPurifier configured from these values. Never widen these lists to
 * `*` — every additional element/attribute increases the XSS surface.
 */
class ContentSanitization extends BaseConfig
{
    /**
     * Whitelisted tag list. Purifier receives this as a comma-separated string
     * via HTML.AllowedElements. Attributes are declared separately below.
     */
    public array $allowedTags = [
        'p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li',
        'a', 'strong', 'em', 'code', 'pre',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'blockquote', 'hr', 'br',
    ];

    /**
     * Whitelisted attributes per tag. Fed to HTML.AllowedAttributes.
     * `a` gets href/title/rel only — target is stripped so we can force
     * `rel="nofollow noopener"` and open in the same tab.
     */
    public array $allowedAttributes = [
        'a.href', 'a.title', 'a.rel',
        'th.scope', 'td.colspan', 'td.rowspan',
        'th.colspan', 'th.rowspan',
    ];

    /**
     * Allowed URI schemes for hrefs. `mailto` is deliberately excluded to
     * avoid mailto-based phishing until we need it.
     */
    public array $allowedSchemes = ['http', 'https'];

    /**
     * Maximum length of the sanitised HTML body in bytes.
     * Fields larger than this are rejected up-front before Purifier runs.
     */
    public int $maxContentBytes = 262144;

    /**
     * If true, unknown/disallowed tags are removed with their text preserved.
     * If false, they are converted to plain text (Purifier default).
     */
    public bool $removeEmpty = true;

    /**
     * Optional cache directory (relative to WRITEPATH). Set to an empty
     * string to disable Purifier's on-disk definition cache — useful on
     * hosts where WRITEPATH is not writable at runtime.
     */
    public string $cacheDir = 'htmlpurifier';
}
