<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Tests;

use Fhulufhelo\Rapaport\Exception\InvalidSource;
use Fhulufhelo\Rapaport\RapaportParser;
use Fhulufhelo\Rapaport\Result\PriceList;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

class RapaportParserTest extends TestCase
{
    private static ?PriceList $list = null;

    private static function fixture(): string
    {
        $path = getenv('RAPAPORT_FIXTURE') ?: __DIR__.'/fixtures/report.pdf';

        if (! is_file($path)) {
            self::markTestSkipped("No fixture at {$path}. Set RAPAPORT_FIXTURE to a report PDF.");
        }

        return $path;
    }

    private static function list(): PriceList
    {
        return self::$list ??= RapaportParser::make()->parse(self::fixture());
    }

    public function test_it_reads_every_grid_cleanly(): void
    {
        $list = self::list();

        $this->assertGreaterThan(0, count($list));
        $this->assertSame([], $list->issues(), 'Every grid should line up.');
        $this->assertTrue($list->isComplete());
    }

    public function test_it_recovers_the_clarity_suffixes_the_shifted_font_hides(): void
    {
        $columns = [];

        foreach (self::list() as $table) {
            $columns[implode(',', $table->clarities())] = true;
        }

        // Naive extraction loses the digits and yields VVS,VVS / SI,SI,SI.
        foreach (array_keys($columns) as $set) {
            $this->assertStringNotContainsString('COL', $set, 'Columns should be named, not numbered.');
            $this->assertDoesNotMatchRegularExpression('/\bVVS,VVS\b/', $set);
            $this->assertDoesNotMatchRegularExpression('/\bSI,SI\b/', $set);
        }

        $this->assertArrayHasKey('IF,VVS1,VVS2,VS1,VS2,SI1,SI2,SI3,I1,I2,I3', $columns);
    }

    public function test_prices_never_rise_as_colour_and_clarity_worsen(): void
    {
        foreach (self::list() as $table) {
            $grid = $table->grid();
            $colors = $table->colors();

            foreach ($grid as $color => $cells) {
                $values = array_values($cells);

                for ($i = 1; $i < count($values); $i++) {
                    $this->assertLessThanOrEqual(
                        $values[$i - 1],
                        $values[$i],
                        "{$table->sizeLabel()} row {$color} rises across clarity."
                    );
                }
            }

            for ($j = 1; $j < count($colors); $j++) {
                foreach ($table->clarities() as $clarity) {
                    $better = $grid[$colors[$j - 1]][$clarity];
                    $worse = $grid[$colors[$j]][$clarity];

                    if ($better !== null && $worse !== null) {
                        $this->assertLessThanOrEqual($better, $worse);
                    }
                }
            }
        }
    }

    public function test_every_row_is_as_wide_as_the_header(): void
    {
        foreach (self::list() as $table) {
            $width = count($table->clarities());

            foreach ($table->grid() as $color => $cells) {
                $this->assertCount($width, $cells, "{$table->sizeLabel()} row {$color}");
                $this->assertNotContains(null, $cells, "{$table->sizeLabel()} row {$color} has a gap.");
            }
        }
    }

    public function test_main_sheet_prices_are_quoted_in_hundreds(): void
    {
        $table = self::list()->ofCategory('list')->tables()[0];
        $color = $table->colors()[0];
        $clarity = $table->clarities()[0];

        $this->assertSame(
            round($table->price($color, $clarity) * 100, 2),
            $table->usdPerCarat($color, $clarity)
        );
    }

    public function test_parcel_prices_are_quoted_per_carat(): void
    {
        $parcels = self::list()->ofCategory('parcel')->tables();

        if ($parcels === []) {
            $this->markTestSkipped('This report has no parcel sheet.');
        }

        $table = $parcels[0];
        $color = $table->colors()[0];
        $clarity = $table->clarities()[0];

        $this->assertSame(
            (float) $table->price($color, $clarity),
            $table->usdPerCarat($color, $clarity)
        );
    }

    public function test_it_looks_a_price_up_by_shape_weight_colour_and_clarity(): void
    {
        $list = self::list();
        $table = $list->find('ROUND', 1.05);

        $this->assertNotNull($table);
        $this->assertTrue($table->covers(1.05));
        $this->assertSame(
            $table->usdPerCarat('G', 'VS1'),
            $list->priceFor('ROUND', 1.05, 'G', 'VS1')
        );
    }

    public function test_it_accepts_a_path_raw_bytes_a_file_object_and_a_stream(): void
    {
        $path = self::fixture();
        $expected = count(self::list());

        $this->assertCount($expected, RapaportParser::make()->parse($path)->tables());
        $this->assertCount($expected, RapaportParser::make()->parse(file_get_contents($path))->tables());
        $this->assertCount($expected, RapaportParser::make()->parse(new SplFileInfo($path))->tables());

        $handle = fopen($path, 'r');
        $this->assertCount($expected, RapaportParser::make()->parse($handle)->tables());
        fclose($handle);
    }

    public function test_it_rejects_input_that_is_not_a_pdf(): void
    {
        $this->expectException(InvalidSource::class);

        RapaportParser::make()->parse('/no/such/report.pdf');
    }

    public function test_it_serialises_to_arrays_json_and_csv(): void
    {
        $list = self::list();

        $array = $list->toArray();
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('tables', $array);

        $this->assertIsArray(json_decode($list->toJson(), true));

        $csv = $list->toCsv();
        $this->assertStringContainsString('usd_per_carat', $csv);
        $this->assertCount(count($list->prices()) + 1, array_filter(explode("\n", trim($csv))));
    }
}
