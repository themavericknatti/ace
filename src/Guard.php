<?php

namespace Ace;

/**
 * Guard — Automatic Input & Output Protection
 *
 * Provides three layers of protection:
 *   1. Input Guard:  Sanitizes values before database storage
 *   2. Mass Assignment Guard:  Controls which fields can be mass-assigned
 *   3. Output Guard: Escapes values for safe HTML rendering
 */
class Guard
{
    // =========================================================================
    // Input Guard — Sanitize Before Storage
    // =========================================================================

    /**
     * Sanitize a single value for safe database storage.
     * Strips script tags, event handlers, and dangerous URI schemes.
     */
    public static function input(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([static::class, 'input'], $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        // Trim whitespace
        $value = trim($value);

        // Delegate deep XSS cleaning to the existing sanitizer
        $value = XssSanitizer::clean($value);

        return $value;
    }

    /**
     * Sanitize an associative array of attributes, skipping specified raw fields.
     *
     * @param array $data      Key-value attribute pairs
     * @param array $rawFields Field names that should NOT be sanitized (e.g. rich text)
     * @return array Sanitized data
     */
    public static function sanitizeAttributes(array $data, array $rawFields = []): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $rawFields, true)) {
                // Bypass sanitization for explicitly raw fields
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = static::input($value);
            }
        }

        return $sanitized;
    }

    // =========================================================================
    // Mass Assignment Guard — Fillable / Guarded
    // =========================================================================

    /**
     * Filter attributes through fillable/guarded rules.
     *
     * @param array $data      Incoming attributes
     * @param array $fillable  If non-empty, ONLY these keys are allowed
     * @param array $guarded   These keys are ALWAYS rejected
     * @return array Filtered attributes
     */
    public static function filterMassAssignment(array $data, array $fillable, array $guarded): array
    {
        // If fillable is defined, only allow those keys
        if (!empty($fillable)) {
            $data = array_intersect_key($data, array_flip($fillable));
        }

        // Always remove guarded keys
        foreach ($guarded as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    // =========================================================================
    // Output Guard — Escape for HTML Rendering
    // =========================================================================

    /**
     * Escape a value for safe HTML output.
     *
     *   Guard::output($user->bio)  =>  "Hello &amp; welcome"
     */
    public static function output(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(', ', array_map([static::class, 'output'], $value));
        }

        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escape an entire array of values for safe HTML output.
     */
    public static function outputAll(array $data): array
    {
        return array_map([static::class, 'output'], $data);
    }
}
