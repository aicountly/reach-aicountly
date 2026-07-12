<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\ContentSanitization;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Thin, immutable wrapper around HTMLPurifier used by any controller/service
 * that persists user-authored HTML (blogs, campaigns, landing pages).
 *
 * Contract:
 *   - Input is treated as untrusted HTML.
 *   - Output is a safe HTML subset limited by ContentSanitization::$allowedTags
 *     and ::$allowedAttributes.
 *   - Anchors are forced to `rel="nofollow noopener"` and http/https-only.
 *   - Content exceeding $maxContentBytes is truncated defensively before
 *     purification so we never hand pathological payloads to HTMLPurifier.
 *
 * The sanitiser NEVER throws on malformed HTML — HTMLPurifier is designed to
 * always emit valid, safe HTML, even for corrupted input.
 */
class HtmlSanitizer
{
    private HTMLPurifier $purifier;
    private ContentSanitization $config;

    public function __construct(?ContentSanitization $config = null)
    {
        $this->config = $config ?? config(ContentSanitization::class);

        $purifierConfig = HTMLPurifier_Config::createDefault();
        $purifierConfig->set('HTML.Allowed', $this->buildAllowedString());
        $purifierConfig->set('URI.AllowedSchemes', array_fill_keys($this->config->allowedSchemes, true));
        $purifierConfig->set('AutoFormat.RemoveEmpty', $this->config->removeEmpty);
        $purifierConfig->set('Attr.AllowedFrameTargets', []);
        $purifierConfig->set('HTML.TargetBlank', false);
        $purifierConfig->set('HTML.Nofollow', true);
        $purifierConfig->set('Attr.EnableID', false);
        $purifierConfig->set('CSS.AllowedProperties', []);
        $purifierConfig->set('Core.EscapeInvalidTags', false);

        if ($this->config->cacheDir !== '') {
            $cachePath = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . $this->config->cacheDir;
            if (! is_dir($cachePath) && ! @mkdir($cachePath, 0775, true) && ! is_dir($cachePath)) {
                $purifierConfig->set('Cache.DefinitionImpl', null);
            } else {
                $purifierConfig->set('Cache.SerializerPath', $cachePath);
            }
        } else {
            $purifierConfig->set('Cache.DefinitionImpl', null);
        }

        $this->purifier = new HTMLPurifier($purifierConfig);
    }

    /**
     * Purify a single field. Returns the safe HTML string. Empty/null in ->
     * empty string out.
     */
    public function purify(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        if (strlen($html) > $this->config->maxContentBytes) {
            $html = substr($html, 0, $this->config->maxContentBytes);
        }

        return $this->purifier->purify($html);
    }

    /**
     * Purify a set of keys inside an associative row. Missing keys are
     * skipped. Returns the mutated row so callers can chain.
     */
    public function purifyFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            if ($row[$field] === null) {
                continue;
            }
            $row[$field] = $this->purify((string) $row[$field]);
        }
        return $row;
    }

    /**
     * Purify plain text: strips ALL tags, collapses whitespace, keeps
     * newlines. Used for titles, excerpts, meta descriptions where no
     * markup should ever survive.
     */
    public function purifyText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $stripped = strip_tags($text);
        $stripped = preg_replace('/[ \t]+/', ' ', $stripped) ?? $stripped;
        return trim($stripped);
    }

    private function buildAllowedString(): string
    {
        $attrsByTag = [];
        foreach ($this->config->allowedAttributes as $descriptor) {
            [$tag, $attr] = array_pad(explode('.', $descriptor, 2), 2, null);
            if ($tag === null || $attr === null) {
                continue;
            }
            $attrsByTag[$tag][] = $attr;
        }

        $parts = [];
        foreach ($this->config->allowedTags as $tag) {
            if (isset($attrsByTag[$tag])) {
                $parts[] = $tag . '[' . implode('|', $attrsByTag[$tag]) . ']';
            } else {
                $parts[] = $tag;
            }
        }
        return implode(',', $parts);
    }
}
