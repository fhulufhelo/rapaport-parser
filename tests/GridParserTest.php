<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Tests;

use Fhulufhelo\Rapaport\Extraction\PageText;
use Fhulufhelo\Rapaport\Extraction\TextRun;
use Fhulufhelo\Rapaport\Parsing\GridParser;
use PHPUnit\Framework\TestCase;

/**
 * Drives the grid parser with synthetic text, so the shape-independent
 * behaviour is covered without shipping a copyrighted report.
 */
class GridParserTest extends TestCase
{
    private const LEFT = 50.0;

    private const RIGHT = 350.0;

    private const PITCH = 25.0;

    public function test_it_reads_two_grids_printed_side_by_side(): void
    {
        $clarities = ['IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'];

        $tables = (new GridParser())->parse([$this->page(
            ['.30 - .39' => [27, 22, 20, 18, 16, 14, 13, 12, 11, 10, 7], '.40 - .49' => [31, 25, 22, 20, 18, 16, 15, 14, 13, 11, 8]],
            $clarities,
            ['D']
        )]);

        $this->assertCount(2, $tables);

        $this->assertSame('.30 - .39 CT.', $tables[0]['size_label']);
        $this->assertSame($clarities, $tables[0]['clarities']);
        $this->assertSame(27, $tables[0]['prices']['D']['IF']);
        $this->assertSame(7, $tables[0]['prices']['D']['I3']);

        $this->assertSame('.40 - .49 CT.', $tables[1]['size_label']);
        $this->assertSame(31, $tables[1]['prices']['D']['IF']);
        $this->assertSame(8, $tables[1]['prices']['D']['I3']);

        $this->assertSame([], $tables[0]['issues']);
        $this->assertSame([], $tables[1]['issues']);
    }

    /**
     * The report's size is read from the document, so a sheet with fewer or
     * more columns than usual parses the same way.
     */
    public function test_it_follows_whatever_width_the_sheet_uses(): void
    {
        $widths = [
            'three columns' => ['IF', 'VS1', 'SI1'],
            'small stone set' => ['IF-VVS', 'VS', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'],
            'full set' => ['IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'],
            'wider than today' => ['IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3', 'IF2'],
        ];

        foreach ($widths as $name => $clarities) {
            $prices = range(count($clarities) * 10, 10, -10);

            $tables = (new GridParser())->parse([$this->page(
                ['1.00 - 1.49' => $prices],
                $clarities,
                ['D']
            )]);

            $this->assertCount(1, $tables, $name);
            $this->assertSame($clarities, $tables[0]['clarities'], $name);
            $this->assertSame(array_combine($clarities, $prices), $tables[0]['prices']['D'], $name);
            $this->assertSame([], $tables[0]['issues'], $name);
        }
    }

    public function test_it_reads_clarity_headers_drawn_in_the_shifted_font(): void
    {
        $clarities = ['IF-VVS', 'VS', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'];

        $tables = (new GridParser())->parse([$this->page(
            ['.01 - .03' => [76, 67, 56, 49, 43, 38, 32, 26]],
            array_map([self::class, 'shift'], $clarities),
            ['D-F']
        )]);

        // Without the control bytes this reads as IF,VVS / SI,SI,SI / I,I,I.
        $this->assertSame($clarities, $tables[0]['clarities']);
        $this->assertSame(76, $tables[0]['prices']['D-F']['IF-VVS']);
        $this->assertSame(26, $tables[0]['prices']['D-F']['I3']);
    }

    public function test_the_colour_row_named_i_is_not_mistaken_for_a_clarity_header(): void
    {
        $clarities = ['IF', 'VS1', 'SI1'];

        $tables = (new GridParser())->parse([$this->page(
            ['1.00 - 1.49' => [30, 20, 10]],
            $clarities,
            ['H', 'I', 'J']
        )]);

        $this->assertSame(['H', 'I', 'J'], $tables[0]['colors']);
        $this->assertSame($clarities, $tables[0]['clarities']);
    }

    public function test_it_reports_a_row_that_does_not_fill_the_grid(): void
    {
        $page = $this->page(
            ['1.00 - 1.49' => [30, 20, 10]],
            ['IF', 'VS1', 'SI1'],
            ['D']
        );

        // A second colour row that is one price short.
        $runs = $page->runs();
        $runs[] = new TextRun(self::LEFT - 15, 200.0, ['E'], self::LEFT - 5);
        $runs[] = new TextRun(self::LEFT, 200.0, ['25'], self::LEFT + 10);
        $runs[] = new TextRun(self::LEFT + self::PITCH, 200.0, ['15'], self::LEFT + self::PITCH + 10);

        $tables = (new GridParser())->parse([new PageText(1, $runs)]);

        $this->assertNotSame([], $tables[0]['issues']);
        $this->assertStringContainsString('Colour E', $tables[0]['issues'][0]);
    }

    public function test_it_reads_the_parcel_sheet_layout(): void
    {
        $runs = [];
        $runs[] = new TextRun(self::LEFT, 100.0, ['ROUNDS', '0.03 - 0.07 ct', '+6.5 - 11', 'July 2026'], self::LEFT + 150);

        foreach (['VVS', 'VS', 'SI1'] as $i => $clarity) {
            $x = self::LEFT + $i * self::PITCH;
            $runs[] = new TextRun($x, 120.0, [$clarity], $x + 12);
        }

        $runs[] = new TextRun(self::LEFT - 15, 140.0, ['D-F'], self::LEFT - 5);

        foreach ([860, 750, 660] as $i => $price) {
            $x = self::LEFT + $i * self::PITCH;
            $runs[] = new TextRun($x, 140.0, [(string) $price], $x + 12);
        }

        $tables = (new GridParser())->parse([new PageText(1, $runs)]);

        $this->assertCount(1, $tables);
        $this->assertSame('parcel', $tables[0]['category']);
        $this->assertSame('ROUND', $tables[0]['shape']);
        $this->assertSame(0.03, $tables[0]['size_min']);
        $this->assertSame(0.07, $tables[0]['size_max']);
        $this->assertSame('July 2026', $tables[0]['date']);
        $this->assertSame(860, $tables[0]['prices']['D-F']['VVS']);
    }

    public function test_a_parcel_band_written_as_up_to_is_open_ended(): void
    {
        $runs = [new TextRun(self::LEFT, 100.0, ['ROUNDS', '-0.01 ct', '-2', 'July 2026'], self::LEFT + 150)];
        $runs[] = new TextRun(self::LEFT, 120.0, ['VVS'], self::LEFT + 12);
        $runs[] = new TextRun(self::LEFT - 15, 140.0, ['D-F'], self::LEFT - 5);
        $runs[] = new TextRun(self::LEFT, 140.0, ['1080'], self::LEFT + 12);

        $tables = (new GridParser())->parse([new PageText(1, $runs)]);

        $this->assertSame(0.0, $tables[0]['size_min']);
        $this->assertSame(0.01, $tables[0]['size_max']);
        $this->assertSame('up to 0.01 CT.', $tables[0]['size_label']);
    }

    // ------------------------------------------------------------------ setup

    /**
     * Lay a page out the way the report does: a title line, a clarity header,
     * then colour rows, with up to two grids side by side.
     *
     * @param array<string, list<int>> $grids   size label => the first colour row
     * @param list<string>             $clarities
     * @param list<string>             $colors
     */
    private function page(array $grids, array $clarities, array $colors): PageText
    {
        $runs = [];
        $titles = [];
        $origins = [self::LEFT, self::RIGHT];

        foreach (array_keys($grids) as $i => $label) {
            $titles[] = "RAPAPORT : ({$label} CT.) : 07/03/26";
        }

        $runs[] = new TextRun(self::LEFT, 100.0, [implode(' ROUNDS ', $titles)], self::RIGHT + 150);

        foreach (array_values($grids) as $g => $prices) {
            $origin = $origins[$g];

            foreach ($clarities as $i => $clarity) {
                $x = $origin + $i * self::PITCH;
                $runs[] = new TextRun($x, 120.0, [$clarity], $x + 12);
            }

            foreach ($colors as $r => $color) {
                $y = 140.0 + $r * 15.0;
                $runs[] = new TextRun($origin - 15, $y, [$color], $origin - 5);

                foreach ($prices as $i => $price) {
                    $x = $origin + $i * self::PITCH;
                    // Later colour rows are cheaper, keeping the grid realistic.
                    $runs[] = new TextRun($x, $y, [(string) max(1, $price - $r)], $x + 12);
                }
            }
        }

        return new PageText(1, $runs);
    }

    /**
     * Encode a label the way the report's shifted font stores it: every glyph
     * 29 code points below the character it draws.
     */
    private static function shift(string $label): string
    {
        $out = '';

        foreach (str_split($label) as $char) {
            $out .= chr(ord($char) - 29);
        }

        return $out;
    }
}
