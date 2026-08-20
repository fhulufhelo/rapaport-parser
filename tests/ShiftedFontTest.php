<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Tests;

use Fhulufhelo\Rapaport\Parsing\ShiftedFont;
use PHPUnit\Framework\TestCase;

class ShiftedFontTest extends TestCase
{
    public function test_it_raises_each_glyph_back_to_what_it_draws(): void
    {
        // The suffix digits and the hyphen land on control-code bytes, which is
        // exactly what naive extraction drops.
        $labels = [
            ",)\x10996" => 'IF-VVS',
            "996\x14" => 'VVS1',
            "996\x15" => 'VVS2',
            "6,\x16" => 'SI3',
            ",\x14" => 'I1',
            "\x03" => ' ',
        ];

        foreach ($labels as $encoded => $expected) {
            $this->assertSame($expected, ShiftedFont::decode((string) $encoded));
        }
    }

    public function test_it_offers_the_text_as_printed_and_as_decoded(): void
    {
        $readings = ShiftedFont::readings(",)\x10996");

        $this->assertCount(2, $readings);
        $this->assertSame(",)\x10996", $readings[0]);
        $this->assertSame('IF-VVS', $readings[1]);
    }

    public function test_the_caller_keeps_whichever_reading_matches(): void
    {
        $isClarity = static function (string $text): bool {
            return (bool) preg_match('/^(?:IF-VVS|IF|VVS|VS|SI|I)\d?$/', $text);
        };

        // Shifted text has to be decoded...
        $this->assertSame('SI2', ShiftedFont::firstMatching("6,\x15", $isClarity));

        // ...while text already in an ordinary font must be left alone.
        $this->assertSame('SI2', ShiftedFont::firstMatching('SI2', $isClarity));

        $this->assertNull(ShiftedFont::firstMatching('Tel: 877-987-3400', $isClarity));
    }
}
