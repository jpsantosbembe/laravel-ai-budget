<?php

namespace Bembe\AiBudget\Support;

/**
 * Decodes model output as JSON, tolerating the ```json fences models keep
 * adding no matter how the prompt is worded.
 */
final class JsonExtractor
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $content): ?array
    {
        $raw = trim($content);
        $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
