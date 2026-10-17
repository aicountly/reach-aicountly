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
     * Whether purify() would take something away from $html, as opposed to
     * only normalising it.
     *
     * Comparing purify($html) with $html byte for byte cannot answer that:
     * HTMLPurifier adds rel="nofollow noopener" to every link, rewrites <br/>
     * as <br />, re-encodes entities, swaps attribute quotes and closes open
     * paragraphs, so nearly every real answer "changed" and was reported as
     * unsafe. Both sides are parsed into a DOM and compared on what survives —
     * elements, their attributes and their text — so a removed <script>,
     * event handler, style attribute or javascript: link still counts, while
     * markup the purifier merely re-spells does not. Tags outside the allowed
     * set are compared by their text (the purifier unwraps them), and a few
     * attributes that carry no risk (rel, target, class, id, lang, dir) are
     * ignored.
     */
    public function wouldStripContent(?string $html): bool
    {
        if ($html === null || trim($html) === '') {
            return false;
        }

        return $this->canonical($html) !== $this->canonical($this->purify($html));
    }

    /** Attributes whose removal by the purifier changes nothing a reader or the page can act on. */
    private const BENIGN_ATTRIBUTES = ['rel', 'target', 'class', 'id', 'lang', 'dir'];

    /** Attributes that make a disallowed element dangerous, not just unknown. */
    private const ACTIVE_ATTRIBUTE = '/^(on|style$|src$|href$|data$|action$|formaction$|srcdoc$|xlink:href$)/i';

    /** Elements the purifier keeps even when empty (AutoFormat.RemoveEmpty exemptions) or that are void. */
    private const KEEP_WHEN_EMPTY = ['br', 'hr', 'td', 'th'];

    private function canonical(string $html): string
    {
        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);

        return $body === null ? '' : $this->canonicalChildren($body);
    }

    private function canonicalChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->canonicalNode($child);
        }
        return $out;
    }

    private function canonicalNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            $text = preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent;
            return trim($text) === '' ? '' : $text;
        }
        if (! $node instanceof \DOMElement) {
            return ''; // comments, processing instructions: the purifier drops them
        }

        $tag      = strtolower($node->tagName);
        $children = $this->canonicalChildren($node);

        if (! in_array($tag, $this->config->allowedTags, true)) {
            // Unwrapped by the purifier: only its text survives. An active
            // attribute on it is a marker the purified side can never match.
            foreach ($node->attributes ?? [] as $attr) {
                if (preg_match(self::ACTIVE_ATTRIBUTE, $attr->name) === 1) {
                    return '[' . $tag . '@' . strtolower($attr->name) . ']' . $children;
                }
            }
            return $children;
        }

        if (trim($children) === '' && ! in_array($tag, self::KEEP_WHEN_EMPTY, true)) {
            return '';
        }

        $attrs = [];
        foreach ($node->attributes ?? [] as $attr) {
            $name = strtolower($attr->name);
            if (in_array($name, self::BENIGN_ATTRIBUTES, true)) {
                continue;
            }
            $attrs[$name] = trim($attr->value);
        }
        ksort($attrs);

        $open = $tag;
        foreach ($attrs as $name => $value) {
            $open .= ' ' . $name . '="' . $value . '"';
        }

        return '<' . $open . '>' . $children . '</' . $tag . '>';
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
