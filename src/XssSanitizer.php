<?php

namespace Ace;

class XssSanitizer
{
    /**
     * Clean input value recursively from XSS vectors and malicious payloads.
     *
     * @param mixed $value Input value
     * @return mixed Cleaned value
     */
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        // 1. Remove null bytes and non-printable control characters (except tab, newline, carriage return)
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // 2. Strip dangerous executable and embedding tags along with their inner contents
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'base', 'frame', 'frameset'];
        foreach ($dangerousTags as $tag) {
            $value = preg_replace("/<{$tag}\b[^>]*>(.*?)<\/{$tag}>/is", '', $value);
            $value = preg_replace("/<{$tag}\b[^>]*\/?>/is", '', $value);
        }

        // 3. Strip SVG tags if they contain inline scripts or event handlers
        if (stripos($value, '<svg') !== false && preg_match('/<svg\b[^>]*(?:script|onload|onerror|onclick)[^>]*>/is', $value)) {
            $value = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', $value);
            $value = preg_replace('/<svg\b[^>]*\/?>/is', '', $value);
        }

        // 4. Strip all inline DOM event handlers (e.g. onload, onerror, onclick, onmouseover, onfocus, onblur, etc.)
        // Handles quoted, single-quoted, and unquoted event handler values
        $value = preg_replace('/on[a-z0-9_-]+\s*=\s*(["\'])(.*?)\1/is', '', $value);
        $value = preg_replace('/on[a-z0-9_-]+\s*=\s*[^\s>]+/is', '', $value);

        // 5. Remove dangerous URI schemes (javascript:, vbscript:, data:text/html, etc.)
        $value = preg_replace('/(href|src|action|data)\s*=\s*(["\'])\s*(?:javascript|vbscript|data\s*:\s*text\/html|data\s*:\s*application):.*?\2/is', '', $value);
        $value = preg_replace('/(href|src|action|data)\s*=\s*(?:javascript|vbscript|data\s*:\s*text\/html):[^\s>]+/is', '', $value);
        $value = preg_replace('/(?:javascript|vbscript)\s*:[^\s]*/is', '', $value);

        // 6. Neutralize CSS expressions and dynamic binding behaviors
        $value = preg_replace('/expression\s*\((.*?)\)/is', '', $value);
        $value = preg_replace('/url\s*\(\s*(["\']?)\s*(?:javascript|vbscript):.*?\1\s*\)/is', '', $value);
        $value = preg_replace('/-moz-binding\s*:/is', '', $value);

        return $value;
    }
}
