<?php

namespace App\Support;

class ExplanationContentHelper
{
    /**
     * Determine whether an explanation HTML/text field should be treated as missing.
     *
     * Treats NULL, empty strings, whitespace-only strings, non-breaking spaces,
     * and empty Tiptap HTML shells (e.g. <p></p>, <p><br></p>, <p>&nbsp;</p>) as missing.
     * Preserves meaningful text, mathematical LaTeX nodes, and image nodes as present.
     */
    public static function isMissing(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }

        if (! preg_match('/<\/?[a-z][\s\S]*>/i', $trimmed)) {
            return self::normalizeWhitespace(html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><html><body>'.$trimmed.'</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            $body = $document->getElementsByTagName('body')->item(0);
            if (! $body) {
                return true;
            }

            return ! self::nodeHasMeaningfulContent($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function hasContent(?string $value): bool
    {
        return ! self::isMissing($value);
    }

    public static function missingStatus(?string $explanationEn, ?string $explanationUr): string
    {
        $missingEn = self::isMissing($explanationEn);
        $missingUr = self::isMissing($explanationUr);

        if ($missingEn && $missingUr) {
            return 'missing_both';
        }
        if ($missingEn) {
            return 'missing_en';
        }
        if ($missingUr) {
            return 'missing_ur';
        }

        return 'complete';
    }

    /**
     * Compute the effective language to generate without overwriting existing explanations.
     * Returns null when all requested fields already have content.
     */
    public static function effectiveLanguage(string $requestedLanguage, ?string $explanationEn, ?string $explanationUr): ?string
    {
        $missingEn = self::isMissing($explanationEn);
        $missingUr = self::isMissing($explanationUr);

        return match ($requestedLanguage) {
            'en' => $missingEn ? 'en' : null,
            'ur' => $missingUr ? 'ur' : null,
            'both' => match (true) {
                $missingEn && $missingUr => 'both',
                $missingEn => 'en',
                $missingUr => 'ur',
                default => null,
            },
            default => null,
        };
    }

    public static function extractPlainPreview(?string $html, int $limit = 180): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $decoded = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim((string) preg_replace('/[\s\x{00A0}\p{Z}]+/u', ' ', $decoded));

        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }

        return mb_substr($clean, 0, max(1, $limit - 1)).'…';
    }

    private static function nodeHasMeaningfulContent(\DOMNode $node): bool
    {
        if ($node instanceof \DOMText) {
            return self::normalizeWhitespace($node->nodeValue) !== '';
        }

        if (! $node instanceof \DOMElement) {
            return false;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style'], true)) {
            return false;
        }

        foreach (['data-latex', 'data-tex'] as $attribute) {
            if ($node->hasAttribute($attribute) && self::normalizeWhitespace($node->getAttribute($attribute)) !== '') {
                return true;
            }
        }

        if (in_array('ql-formula', preg_split('/\s+/', (string) $node->getAttribute('class')), true)
            && self::normalizeWhitespace($node->getAttribute('data-value')) !== '') {
            return true;
        }

        if ($tag === 'img' && $node->hasAttribute('src') && self::normalizeWhitespace($node->getAttribute('src')) !== '') {
            return true;
        }

        foreach ($node->childNodes as $child) {
            if (self::nodeHasMeaningfulContent($child)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeWhitespace(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], ' ', $value);

        return trim((string) preg_replace('/[\s\p{Z}]+/u', '', $value));
    }
}
