<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Parsing;

/**
 * The report sets its clarity headers, and some of its commentary, in a font
 * whose every glyph sits a fixed distance below the character it draws.
 *
 * Shifting ",)\x10996" up by 29 gives "IF-VVS". The interesting part is that
 * the hyphen and the suffix digits land on control-code bytes:
 *
 *     0x10 -> "-"   0x13 -> "0"   0x14 -> "1"   0x15 -> "2"   0x16 -> "3"
 *
 * Extractors that sanitise control characters silently drop those, which is
 * why a naive read of this PDF turns both VVS1 and VVS2 into "VVS".
 *
 * Shifted and unshifted blocks sit side by side in the same rows, so there is
 * no document-wide answer to whether a given run needs decoding. Rather than
 * guess from the shape of the text — an all-caps heading looks a lot like
 * shifted output — callers ask for both readings and keep whichever one
 * actually matches what they are looking for.
 */
final class ShiftedFont
{
    private const SHIFT = 29;

    /**
     * Raise every byte back to the character it renders.
     */
    public static function decode(string $text): string
    {
        $decoded = '';

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $code = ord($text[$i]) + self::SHIFT;

            if ($code <= 0xFF) {
                $decoded .= chr($code);
            }
        }

        return $decoded;
    }

    /**
     * The text as printed and as decoded, for the caller to test in turn.
     *
     * @return list<string>
     */
    public static function readings(string $text): array
    {
        $decoded = self::decode($text);

        return $decoded === $text ? [$text] : [$text, $decoded];
    }

    /**
     * The first reading that satisfies $matches, or null when neither does.
     *
     * @param  callable(string): bool  $matches
     */
    public static function firstMatching(string $text, callable $matches): ?string
    {
        foreach (self::readings($text) as $reading) {
            if ($matches($reading)) {
                return $reading;
            }
        }

        return null;
    }
}
