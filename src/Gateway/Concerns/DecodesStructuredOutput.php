<?php

namespace Laravel\Ai\Gateway\Concerns;

trait DecodesStructuredOutput
{
    /**
     * Decode a structured output payload, tolerating markdown code fences.
     */
    protected function decodeStructuredOutput(?string $text): array
    {
        $trimmed = trim((string) $text);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            $decoded = json_decode($this->extractJsonCodeFence($trimmed) ?? '', true);
        }

        if (! is_array($decoded)) {
            $decoded = json_decode($this->escapeQuotesInObjectKeys($this->extractJsonCodeFence($trimmed) ?? $trimmed), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Escape double quotes left unescaped inside object keys, as Gemini does while escaping them in values.
     */
    private function escapeQuotesInObjectKeys(string $json): string
    {
        $result = '';
        $containers = [];
        $expectsKey = false;

        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];

            if ($char !== '"') {
                if ($char === '{' || $char === '[') {
                    $containers[] = $char;
                } elseif ($char === '}' || $char === ']') {
                    array_pop($containers);
                }

                $expectsKey = match (true) {
                    $char === '{' => true,
                    $char === ',' => end($containers) === '{',
                    default => $expectsKey && ctype_space($char),
                };

                $result .= $char;

                continue;
            }

            // A value ends at the next unescaped quote, a key only at one followed by a colon...
            $string = '';

            for ($i++; $i < $length; $i++) {
                if ($json[$i] === '\\') {
                    $string .= substr($json, $i++, 2);

                    continue;
                }

                if ($json[$i] === '"') {
                    if (! $expectsKey || preg_match('/\G\s*:/', $json, $matches, 0, $i + 1) === 1) {
                        break;
                    }

                    $string .= '\\';
                }

                $string .= $json[$i];
            }

            $result .= '"'.$string.'"';
            $expectsKey = false;
        }

        return $result;
    }

    /**
     * Extract the contents of a markdown code fence from the payload, if present.
     */
    private function extractJsonCodeFence(string $text): ?string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
