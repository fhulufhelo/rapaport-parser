<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Parsing;

use Fhulufhelo\Rapaport\Extraction\PageText;
use Fhulufhelo\Rapaport\Extraction\TextRun;

/**
 * Turns positioned text into price grids.
 *
 * Nothing about the report's size is assumed: the number of pages, grids per
 * page, colour rows, clarity columns and shapes are all read from the document.
 * A grid is recognised by its title, its columns by its clarity header, and its
 * rows by their colour label.
 *
 * Grids are printed side by side, so each is given a horizontal span taken from
 * the gutters on its clarity header line. Runs are then placed by the span they
 * fall in, which works whichever backend supplied the text: one run per word,
 * or one run per grid line.
 */
final class GridParser
{
    /** Vertical tolerance, in text-space units, for treating runs as one line. */
    private float $rowTolerance;

    /** How far past the last colour row to look for the index and premium notes. */
    private const TRAILING_ROWS = 3;

    /** Colour grades, single (D) or banded (D-F). */
    private const COLOR = '/^[D-Z](?:-[D-Z])?$/';

    private const NUMBER = '/^\d+(?:\.\d+)?$/';

    /** Annotations worth keeping from the lines under a grid. */
    private const NOTE = '/may trade|premium|discount/i';

    /** A clarity column: a grade plus the suffix digit the shifted font encodes. */
    private const CLARITY_TOKEN = '(?:IF-VVS|IF|VVS|VS|SI|I)\d?';

    /**
     * Shapes the report prints, plural as they appear in a heading, mapped to
     * the singular this package reports.
     */
    private const SHAPES = [
        'ROUNDS' => 'ROUND',
        'PEARS' => 'PEAR',
        'PRINCESSES' => 'PRINCESS',
        'PRINCESS' => 'PRINCESS',
        'EMERALDS' => 'EMERALD',
        'ASSCHERS' => 'ASSCHER',
        'OVALS' => 'OVAL',
        'RADIANTS' => 'RADIANT',
        'MARQUISES' => 'MARQUISE',
        'CUSHIONS' => 'CUSHION',
        'HEARTS' => 'HEART',
        'TRILLIANTS' => 'TRILLIANT',
        'BAGUETTES' => 'BAGUETTE',
    ];

    private const MONTHS = 'JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY'
        .'|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER';

    public function __construct(float $rowTolerance = 3.0)
    {
        $this->rowTolerance = $rowTolerance;
    }

    public function rowTolerance(): float
    {
        return $this->rowTolerance;
    }

    /**
     * @param  list<PageText>  $pages
     * @return list<array<string, mixed>>
     */
    public function parse(array $pages): array
    {
        $tables = [];
        $shape = null;

        foreach ($pages as $page) {
            $rows = $page->rows($this->rowTolerance);
            $shape = $this->pageShape($rows) ?? $shape;

            foreach ($this->parsePage($rows, $page->number(), $shape) as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * @param  list<list<TextRun>>  $rows
     * @return list<array<string, mixed>>
     */
    private function parsePage(array $rows, int $pageNumber, ?string $pageShape): array
    {
        $tables = [];

        /** @var list<array<string, mixed>> $open grids whose rows are still arriving */
        $open = [];
        $trailing = 0;

        foreach ($rows as $row) {
            $titles = $this->titlesIn($row);

            if ($titles !== []) {
                $tables = array_merge($tables, $this->close($open));
                $open = [];
                $trailing = 0;

                foreach ($titles as $title) {
                    $open[] = $this->newTable($title, $pageNumber, $pageShape);
                }

                continue;
            }

            if ($open === []) {
                continue;
            }

            // Prices are tried first because a colour label and a clarity grade
            // can be the same letter: a row led by "I" holding eleven numbers is
            // the I colour row, not a one-column clarity header.
            if ($this->readPrices($row, $open) || $this->readHeaders($row, $open)) {
                $trailing = 0;

                continue;
            }

            if (! $this->hasPrices($open)) {
                continue;
            }

            // Past the last colour row. The index line and the premium note sit
            // just underneath, so look only a little further before giving up.
            if (++$trailing > self::TRAILING_ROWS) {
                $tables = array_merge($tables, $this->close($open));
                $open = [];
                $trailing = 0;

                continue;
            }

            $this->readAnnotations($row, $open);
        }

        return array_merge($tables, $this->close($open));
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param  list<TextRun>  $row
     * @param  list<array<string, mixed>>  $open
     */
    private function readHeaders(array $row, array &$open): bool
    {
        $headers = [];

        foreach ($row as $run) {
            $clarities = $this->clarityRun($run);

            if ($clarities === null) {
                return false;   // a header line carries nothing else
            }

            $headers[] = ['run' => $run, 'clarities' => $clarities];
        }

        if ($headers === []) {
            return false;
        }

        $spans = $this->spanRuns(array_column($headers, 'run'), count($open));

        foreach ($spans as $index => $span) {
            if (! isset($open[$index])) {
                continue;
            }

            $clarities = [];

            foreach ($headers as $header) {
                if (! $this->within($header['run'], $span)) {
                    continue;
                }

                foreach ($header['clarities'] as $one) {
                    $clarities[] = $one;
                }
            }

            if ($clarities !== []) {
                $open[$index]['clarities'] = $clarities;
                $open[$index]['span'] = $span;
            }
        }

        return true;
    }

    /**
     * @param  list<TextRun>  $row
     * @param  list<array<string, mixed>>  $open
     */
    private function readPrices(array $row, array &$open): bool
    {
        $color = $this->colorIn($row);

        if ($color === null) {
            return false;
        }

        $priced = [];

        foreach ($row as $run) {
            if ($this->valuesIn($run) !== []) {
                $priced[] = $run;
            }
        }

        if ($priced === []) {
            return false;
        }

        $spans = $this->spans($open) ?? $this->spanRuns($priced, count($open));

        foreach ($spans as $index => $span) {
            if (! isset($open[$index])) {
                continue;
            }

            $values = [];

            foreach ($priced as $run) {
                if (! $this->within($run, $span)) {
                    continue;
                }

                foreach ($this->valuesIn($run) as $value) {
                    $values[] = $value;
                }
            }

            if ($values === []) {
                continue;
            }

            $open[$index]['prices'][$color] = $values;

            if ($open[$index]['span'] === null) {
                $open[$index]['span'] = $span;
            }
        }

        return true;
    }

    /**
     * @param  list<TextRun>  $row
     * @param  list<array<string, mixed>>  $open
     */
    private function readAnnotations(array $row, array &$open): void
    {
        $spans = $this->spans($open) ?? $this->spanRuns($row, count($open));

        foreach ($spans as $index => $span) {
            if (! isset($open[$index])) {
                continue;
            }

            // The index line and the premium notes are sentences. A backend
            // that reports one run per word never holds one in a single run,
            // so the line is rebuilt before matching.
            $parts = [];

            foreach ($row as $run) {
                if ($this->within($run, $span)) {
                    $parts[] = $run->text();
                }
            }

            if ($parts === []) {
                continue;
            }

            foreach (ShiftedFont::lineReadings($parts) as $text) {
                foreach ($this->indexValues($text) as $key => $value) {
                    $open[$index]['index'][$key] = $value;
                }

                if (preg_match(self::NOTE, $text)) {
                    $open[$index]['notes'][] = trim($text);
                }
            }
        }
    }

    // ----------------------------------------------------------------- spans

    /**
     * Spans already established for the open grids, or null while the clarity
     * header has yet to be seen.
     *
     * @param  list<array<string, mixed>>  $open
     * @return list<array{0: float, 1: float}>|null
     */
    private function spans(array $open): ?array
    {
        $spans = [];

        foreach ($open as $table) {
            if ($table['span'] === null) {
                return null;
            }

            $spans[] = $table['span'];
        }

        return $spans === [] ? null : $spans;
    }

    /**
     * Divide a line into one horizontal span per grid, cutting at its widest
     * gaps: the gutter between two grids is the widest gap on the line.
     *
     * @param  list<TextRun>  $runs
     * @return list<array{0: float, 1: float}>
     */
    private function spanRuns(array $runs, int $count): array
    {
        if ($count < 1 || $runs === []) {
            return [];
        }

        if ($count === 1) {
            return [[-INF, INF]];
        }

        usort($runs, static function (TextRun $a, TextRun $b): int {
            return $a->x() <=> $b->x();
        });

        $gaps = [];

        for ($i = 1, $n = count($runs); $i < $n; $i++) {
            $gaps[] = [
                'size' => $runs[$i]->x() - $runs[$i - 1]->endX(),
                'middle' => ($runs[$i]->x() + $runs[$i - 1]->endX()) / 2,
            ];
        }

        usort($gaps, static function (array $a, array $b): int {
            return $b['size'] <=> $a['size'];
        });

        $cuts = array_column(array_slice($gaps, 0, $count - 1), 'middle');
        sort($cuts);

        $edges = array_merge([-INF], $cuts, [INF]);
        $spans = [];

        for ($i = 0; $i < $count; $i++) {
            $spans[] = [$edges[$i], $edges[$i + 1]];
        }

        return $spans;
    }

    /**
     * @param  array{0: float, 1: float}  $span
     */
    private function within(TextRun $run, array $span): bool
    {
        $middle = ($run->x() + $run->endX()) / 2;

        return $middle >= $span[0] && $middle < $span[1];
    }

    // --------------------------------------------------------------- content

    /**
     * @return list<string>|null
     */
    private function clarityRun(TextRun $run): ?array
    {
        $cells = $run->cells();

        if ($cells === []) {
            return null;
        }

        // A lone "I" is a colour label, not a one-column grid.
        if (count($cells) === 1 && preg_match(self::COLOR, strtoupper(trim($cells[0])))) {
            return null;
        }

        $clarities = [];

        foreach ($cells as $cell) {
            $token = $this->clarityToken($cell);

            if ($token === null) {
                return null;
            }

            foreach ($token as $one) {
                $clarities[] = $one;
            }
        }

        return $clarities;
    }

    /**
     * A header cell is normally one column, but neighbouring columns sometimes
     * arrive kerned together, so a cell may carry more than one.
     *
     * @return list<string>|null
     */
    private function clarityToken(string $cell): ?array
    {
        foreach (ShiftedFont::readings($cell) as $reading) {
            // Neighbouring columns are sometimes separated by spaces rather than
            // by kerning, so they arrive in one cell as "VVS1    VVS2".
            $candidate = strtoupper((string) preg_replace('/\s+/', '', $reading));

            if ($candidate === '' || ! preg_match('/^(?:'.self::CLARITY_TOKEN.')+$/', $candidate)) {
                continue;
            }

            preg_match_all('/'.self::CLARITY_TOKEN.'/', $candidate, $matches);

            return $matches[0];
        }

        return null;
    }

    /**
     * @param  list<TextRun>  $row
     */
    private function colorIn(array $row): ?string
    {
        foreach ($row as $run) {
            $first = $run->cells()[0] ?? '';
            $candidate = strtoupper(str_replace(["\xe2\x80\x93", "\xe2\x80\x94"], '-', trim($first)));

            if (preg_match(self::COLOR, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The prices in a run, ignoring a leading colour label when the layout kept
     * it in the same run.
     *
     * @return list<float|int>
     */
    private function valuesIn(TextRun $run): array
    {
        $cells = $run->cells();
        $values = [];

        foreach ($cells as $position => $cell) {
            if (preg_match(self::NUMBER, $cell)) {
                $values[] = $cell + 0;

                continue;
            }

            // Only a colour label may lead the run; anything else means this is
            // not a price line.
            if ($position === 0 && preg_match(self::COLOR, strtoupper($cell))) {
                continue;
            }

            return [];
        }

        return $values;
    }

    /**
     * @return array<string, array{value: float, change_pct: float}>
     */
    private function indexValues(string $text): array
    {
        if (! preg_match_all('/\b([WT]):\s*([\d.]+)\s*=\s*(-?[\d.]+)%/', $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $index = [];

        foreach ($matches as $match) {
            $index[$match[1]] = [
                'value' => (float) $match[2],
                'change_pct' => (float) $match[3],
            ];
        }

        return $index;
    }

    // ---------------------------------------------------------------- titles

    /**
     * @param  list<TextRun>  $row
     * @return list<array<string, mixed>>
     */
    private function titlesIn(array $row): array
    {
        $flat = $this->flatten($row);

        if ($flat === '') {
            return [];
        }

        $shape = $this->shapeIn($flat);

        // A dated grid: "RAPAPORT : (.30 - .39 CT.) : 07/03/26"
        $dated = '/RAPAPORT:\(\s*([\d.]+)\s*[-\x{2013}\x{2014}]\s*([\d.]+)CT\.?\)(?::(\d{2}\/\d{2}\/\d{2}))?/u';

        if (preg_match_all($dated, $flat, $matches, PREG_SET_ORDER)) {
            return $this->buildTitles($matches, 'list', $shape, null);
        }

        // A parcel grid: "ROUNDS  0.03 - 0.07 ct  +6.5 - 11  July 2026"
        // No word boundary after CT: spaces are stripped, so a title that runs
        // straight into its month reads as "0.18-0.22CTJULY2026".
        $parcel = '/(?:'.implode('|', array_keys(self::SHAPES)).')(-?[\d.]+)(?:[-\x{2013}\x{2014}]([\d.]+))?CT/u';

        if (preg_match_all($parcel, $flat, $matches, PREG_SET_ORDER)) {
            $month = preg_match('/('.self::MONTHS.')(\d{4})/', $flat, $stamp)
                ? ucfirst(strtolower($stamp[1])).' '.$stamp[2]
                : null;

            return $this->buildTitles($matches, 'parcel', $shape, $month);
        }

        return [];
    }

    /**
     * @param  list<array<int, string>>  $matches
     * @return list<array<string, mixed>>
     */
    private function buildTitles(array $matches, string $category, ?string $shape, ?string $month): array
    {
        $titles = [];

        foreach ($matches as $match) {
            $low = $match[1];
            $high = isset($match[2]) && $match[2] !== '' ? $match[2] : null;

            // A parcel band written "-0.01 ct" means everything up to 0.01.
            $openEnded = strncmp($low, '-', 1) === 0;
            $min = (float) ltrim($low, '-');

            $titles[] = [
                'category' => $category,
                'shape' => $shape,
                'size_min' => $openEnded ? 0.0 : $min,
                'size_max' => $openEnded ? $min : (float) ($high ?? $low),
                'size_label' => $openEnded
                    ? 'up to '.ltrim($low, '-').' CT.'
                    : $low.($high !== null ? ' - '.$high : '').' CT.',
                'date' => (isset($match[3]) && $match[3] !== '') ? $match[3] : $month,
            ];
        }

        return $titles;
    }

    /**
     * @param  list<list<TextRun>>  $rows
     */
    private function pageShape(array $rows): ?string
    {
        foreach ($rows as $row) {
            $shape = $this->shapeIn($this->flatten($row));

            if ($shape !== null) {
                return $shape;
            }
        }

        return null;
    }

    private function shapeIn(string $flat): ?string
    {
        foreach (self::SHAPES as $plural => $shape) {
            if (strpos($flat, $plural) !== false) {
                return $shape;
            }
        }

        return strpos($flat, 'ROUNDBRILLIANTCUT') !== false ? 'ROUND' : null;
    }

    /**
     * @param  list<TextRun>  $row
     */
    private function flatten(array $row): string
    {
        $parts = [];

        foreach ($row as $run) {
            $parts[] = $run->text();
        }

        $flat = strtoupper(implode('', $parts));

        // Shifted runs turn to noise here, which is harmless: titles and
        // headings are printed in ordinary fonts, so only real ones can match.
        return (string) preg_replace('/[^\x20-\x7E]|\s+/u', '', $flat);
    }

    // ----------------------------------------------------------------- close

    /**
     * @param  list<array<string, mixed>>  $open
     * @return list<array<string, mixed>>
     */
    private function close(array $open): array
    {
        $closed = [];

        foreach ($open as $table) {
            if ($table['prices'] === []) {
                continue;
            }

            $closed[] = $this->finalise($table);
        }

        return $closed;
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function finalise(array $table): array
    {
        $width = 0;

        foreach ($table['prices'] as $values) {
            $width = max($width, count($values));
        }

        $clarities = $this->columnLabels($table['clarities'], $width);
        $grid = [];
        $issues = [];

        if ($table['clarities'] === null) {
            $issues[] = 'No clarity header was found, so the columns are numbered.';
        } elseif (count($table['clarities']) !== $width) {
            $issues[] = sprintf(
                'The clarity header lists %d columns but the widest row holds %d prices.',
                count($table['clarities']),
                $width
            );
        }

        foreach ($table['prices'] as $color => $values) {
            // A short or long row means the columns could not be lined up. Say
            // so rather than quietly filing prices under the wrong clarity.
            if (count($values) !== $width) {
                $issues[] = sprintf(
                    'Colour %s holds %d prices where the grid is %d columns wide.',
                    $color,
                    count($values),
                    $width
                );
            }

            $cells = [];

            foreach ($clarities as $position => $clarity) {
                $cells[$clarity] = $values[$position] ?? null;
            }

            $grid[$color] = $cells;
        }

        return [
            'page' => $table['page'],
            'category' => $table['category'],
            'shape' => $table['shape'],
            'size_label' => $table['size_label'],
            'size_min' => $table['size_min'],
            'size_max' => $table['size_max'],
            'date' => $table['date'],
            'unit' => $table['category'] === 'parcel'
                ? 'US$ per carat'
                : 'hundreds of US$ per carat',
            'clarities' => $clarities,
            'colors' => array_keys($grid),
            'prices' => $grid,
            'index' => $table['index'],
            'notes' => array_values(array_unique($table['notes'])),
            'issues' => $issues,
        ];
    }

    /**
     * Column names, falling back to positional labels when a header could not
     * be read so the prices are still usable.
     *
     * @param  list<string>|null  $clarities
     * @return list<string>
     */
    private function columnLabels(?array $clarities, int $width): array
    {
        if ($clarities !== null && count($clarities) === $width) {
            return $clarities;
        }

        $labels = [];

        for ($i = 0; $i < $width; $i++) {
            $labels[] = $clarities[$i] ?? 'COL'.($i + 1);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $title
     * @return array<string, mixed>
     */
    private function newTable(array $title, int $pageNumber, ?string $pageShape): array
    {
        return [
            'page' => $pageNumber,
            'category' => $title['category'],
            'shape' => $title['shape'] ?? $pageShape,
            'size_label' => $title['size_label'],
            'size_min' => $title['size_min'],
            'size_max' => $title['size_max'],
            'date' => $title['date'],
            'clarities' => null,
            'prices' => [],
            'index' => [],
            'notes' => [],
            'span' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $open
     */
    private function hasPrices(array $open): bool
    {
        foreach ($open as $table) {
            if ($table['prices'] !== []) {
                return true;
            }
        }

        return false;
    }
}
