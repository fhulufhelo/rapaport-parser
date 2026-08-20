<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Extraction;

final class PageText
{
    private int $number;

    /** @var list<TextRun> */
    private array $runs;

    /**
     * @param  list<TextRun>  $runs
     */
    public function __construct(int $number, array $runs)
    {
        $this->number = $number;
        $this->runs = array_values($runs);
    }

    public function number(): int
    {
        return $this->number;
    }

    /**
     * @return list<TextRun>
     */
    public function runs(): array
    {
        return $this->runs;
    }

    /**
     * Runs bucketed into visual lines, each sorted left to right.
     *
     * @return list<list<TextRun>>
     */
    public function rows(float $tolerance): array
    {
        $runs = $this->runs;

        usort($runs, static function (TextRun $a, TextRun $b): int {
            return $a->y() <=> $b->y() ?: $a->x() <=> $b->x();
        });

        $rows = [];
        $current = [];
        $anchor = null;

        foreach ($runs as $run) {
            if ($anchor !== null && abs($run->y() - $anchor) > $tolerance) {
                $rows[] = self::sortByX($current);
                $current = [];
                $anchor = null;
            }

            if ($anchor === null) {
                $anchor = $run->y();
            }

            $current[] = $run;
        }

        if ($current !== []) {
            $rows[] = self::sortByX($current);
        }

        return $rows;
    }

    /**
     * @param  list<TextRun>  $runs
     * @return list<TextRun>
     */
    private static function sortByX(array $runs): array
    {
        usort($runs, static function (TextRun $a, TextRun $b): int {
            return $a->x() <=> $b->x();
        });

        return $runs;
    }
}
