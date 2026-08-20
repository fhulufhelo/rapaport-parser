<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Tests;

use Fhulufhelo\Rapaport\Extraction\Extractor;
use Fhulufhelo\Rapaport\Extraction\PageText;
use Fhulufhelo\Rapaport\Extraction\TextRun;
use Fhulufhelo\Rapaport\RapaportParser;
use Fhulufhelo\Rapaport\Source;
use PHPUnit\Framework\TestCase;

class MetaTest extends TestCase
{
    private const MASTHEAD = 'July 3, 2026 : Volume 49 No. 25: NEW YORK HIGH CASH ASKING PRICES : Page 1';

    /**
     * Backends disagree about how much text one run holds: poppler reports a
     * box per word, others a whole line. The masthead has to be read either
     * way, so it is matched against rebuilt lines rather than single runs.
     */
    public function test_it_reads_the_masthead_however_the_backend_chunks_the_text(): void
    {
        foreach (['one run per word', 'one run per line'] as $style) {
            $runs = $style === 'one run per line'
                ? [new TextRun(50.0, 10.0, [self::MASTHEAD], 500.0)]
                : $this->words(self::MASTHEAD);

            $meta = RapaportParser::make()
                ->using($this->extractor($runs))
                ->lenient()
                ->parse('%PDF-1.6 stub')
                ->meta();

            $this->assertSame('July 3, 2026', $meta['issue_date'], $style);
            $this->assertSame(49, $meta['volume'], $style);
            $this->assertSame(25, $meta['number'], $style);
            $this->assertSame('NEW YORK HIGH CASH ASKING PRICES', $meta['market'], $style);
        }
    }

    public function test_it_reports_pages_it_could_not_read(): void
    {
        $meta = RapaportParser::make()
            ->using($this->extractor($this->words(self::MASTHEAD), 3))
            ->lenient()
            ->parse('%PDF-1.6 stub')
            ->meta();

        $this->assertSame(3, $meta['pages']);
        $this->assertSame([2, 3], $meta['pages_without_text']);
    }

    /**
     * Lay a line out one word per run, the way poppler reports it.
     *
     * @return list<TextRun>
     */
    private function words(string $line): array
    {
        $runs = [];
        $x = 50.0;

        foreach (explode(' ', $line) as $word) {
            $width = strlen($word) * 4.0;
            $runs[] = new TextRun($x, 10.0, [$word], $x + $width);
            $x += $width + 4.0;
        }

        return $runs;
    }

    /**
     * @param list<TextRun> $runs
     */
    private function extractor(array $runs, int $pages = 1): Extractor
    {
        return new class($runs, $pages) implements Extractor
        {
            /** @var list<TextRun> */
            private array $runs;

            private int $pages;

            /**
             * @param list<TextRun> $runs
             */
            public function __construct(array $runs, int $pages)
            {
                $this->runs = $runs;
                $this->pages = $pages;
            }

            /**
             * @return list<PageText>
             */
            public function extract(Source $source): array
            {
                $out = [new PageText(1, $this->runs)];

                for ($i = 2; $i <= $this->pages; $i++) {
                    $out[] = new PageText($i, []);
                }

                return $out;
            }
        };
    }
}
