<?php

namespace App\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes rich-text HTML (RichEditor / Trix / imported document content) down to
 * a safe formatting-only subset. Strips <script>, <style>, <iframe>, event-handler
 * attributes (onerror/onload/onclick/…) and dangerous URL schemes (javascript:,
 * data:) — neutralizing stored-XSS payloads while preserving legitimate formatting.
 *
 * Client-side editors (Trix) only sanitize in the browser; the server must never
 * trust that. Apply this on write (persisted clean) and on read (neutralizes any
 * payloads already stored before this guard existed).
 */
class RichTextSanitizer
{
    public static function clean(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        return trim(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            // W3C "safe" element/attribute set: formatting + structural tags only,
            // no script/style/iframe/object and no on* event-handler attributes.
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener nofollow ugc')
            ->forceAttribute('a', 'target', '_blank');

        return new HtmlSanitizer($config);
    }
}
