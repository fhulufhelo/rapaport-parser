<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport;

use Fhulufhelo\Rapaport\Exception\ExtractionFailed;
use Fhulufhelo\Rapaport\Exception\ExtractorUnavailable;
use Fhulufhelo\Rapaport\Extraction\Extractor;
use Fhulufhelo\Rapaport\Extraction\PageText;
use Fhulufhelo\Rapaport\Extraction\PopplerExtractor;
use Fhulufhelo\Rapaport\Parsing\GridParser;
use Fhulufhelo\Rapaport\Parsing\ShiftedFont;
use Fhulufhelo\Rapaport\Result\PriceList;
use Fhulufhelo\Rapaport\Result\PriceTable;

/**
 * Reads a Rapaport Diamond Report PDF and hands back its price grids.
 *
 *     $list = RapaportParser::make()->parse($pathOrBytesOrUpload);
 *
 *     $list->count();                                  // grids found
 *     $list->priceFor('ROUND', 1.05, 'G', 'VS1');      // US$ per carat
 *     $list->toArray();                                // everything, as arrays
 *
 * The report's shape is read from the document rather than assumed, so a sheet
 * with more or fewer grids, colour rows or clarity columns parses the same way.
 */
final class RapaportParser
{
    private Extractor $extractor;

    private GridParser $grids;

    private bool $strict = true;

    public function __construct(?Extractor $extractor = null, ?GridParser $grids = null)
    {
        $this->extractor = $extractor ?? self::bestExtractor();
        $this->grids = $grids ?? new GridParser;
    }

    /**
     * The default backend.
     *
     * poppler lays glyphs out with the font's own metrics, so it separates the
     * columns correctly however a sheet spaced them. Falling back silently to
     * the pure-PHP backend is deliberately not done: it drops pages on some
     * sheets, and a price list that is quietly incomplete is worse than one
     * that refuses to load.
     */
    public static function bestExtractor(): Extractor
    {
        $poppler = new PopplerExtractor;

        if (! $poppler->isAvailable()) {
            throw ExtractorUnavailable::poppler('pdftotext');
        }

        return $poppler;
    }

    public static function make(): self
    {
        return new self;
    }

    /**
     * Swap in a different text extraction backend.
     */
    public function using(Extractor $extractor): self
    {
        $clone = clone $this;
        $clone->extractor = $extractor;

        return $clone;
    }

    /**
     * Vertical tolerance for deciding that two runs of text share a line.
     * Raise it if a sheet's rows are being split, lower it if they are merging.
     */
    public function withRowTolerance(float $points): self
    {
        $clone = clone $this;
        $clone->grids = new GridParser($points);

        return $clone;
    }

    /**
     * Return an empty list instead of throwing when a PDF holds no grids.
     */
    public function lenient(bool $lenient = true): self
    {
        $clone = clone $this;
        $clone->strict = ! $lenient;

        return $clone;
    }

    /**
     * @param  mixed  $input  path, raw PDF bytes, SplFileInfo, uploaded file,
     *                        stream resource, or PSR-7 stream
     */
    public function parse($input): PriceList
    {
        $source = Source::from($input);
        $pages = $this->extractor->extract($source);
        $tables = $this->grids->parse($pages);

        if ($tables === [] && $this->strict) {
            throw ExtractionFailed::noTables();
        }

        return new PriceList(
            $this->meta($source, $pages),
            array_map(static function (array $table): PriceTable {
                return new PriceTable($table);
            }, $tables)
        );
    }

    /**
     * @param  mixed  $input
     * @return array<string, mixed>
     */
    public function toArray($input): array
    {
        return $this->parse($input)->toArray();
    }

    /**
     * Pages the backend read nothing from, which usually means it could not
     * reach their content rather than that the page was blank.
     *
     * @param  list<PageText>  $pages
     * @return list<int>
     */
    private function blankPages(array $pages): array
    {
        $blank = [];

        foreach ($pages as $page) {
            if ($page->runs() === []) {
                $blank[] = $page->number();
            }
        }

        return $blank;
    }

    /**
     * Masthead details: "July 3, 2026 : Volume 49 No. 25: NEW YORK HIGH CASH
     * ASKING PRICES : Page 1".
     *
     * @param  list<PageText>  $pages
     * @return array<string, mixed>
     */
    private function meta(Source $source, array $pages): array
    {
        $meta = [
            'source' => $source->name(),
            'pages' => count($pages),
            'issue_date' => null,
            'volume' => null,
            'number' => null,
            'market' => null,
            'pages_without_text' => $this->blankPages($pages),
        ];

        $first = $pages[0] ?? null;

        if ($first === null) {
            return $meta;
        }

        $pattern = '/^(.+?)\s*:\s*Volume\s+(\d+)\s+No\.\s*(\d+)\s*:\s*(.+?)\s*:\s*Page/i';

        // Read whole lines: with a backend that reports one run per word, no
        // single run ever holds the masthead.
        foreach ($first->lineParts($this->grids->rowTolerance()) as $parts) {
            foreach (ShiftedFont::lineReadings($parts) as $text) {
                if (! preg_match($pattern, trim($text), $match)) {
                    continue;
                }

                $meta['issue_date'] = trim($match[1]);
                $meta['volume'] = (int) $match[2];
                $meta['number'] = (int) $match[3];
                $meta['market'] = trim($match[4]);

                return $meta;
            }
        }

        return $meta;
    }
}
