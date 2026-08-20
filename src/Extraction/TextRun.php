<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Extraction;

/**
 * One positioned run of text from the PDF, already split into the cells the
 * layout separated with wide kerning.
 *
 * In a Rapaport report a run is a whole grid line: the eight or eleven prices
 * of one colour, or the clarity headers of one grid. That grouping is what
 * makes the grids recoverable — the two grids printed side by side end up as
 * two separate runs rather than one interleaved line of text.
 */
final class TextRun
{
    private float $x;

    private float $endX;

    private float $y;

    /** @var list<string> */
    private array $cells;

    /**
     * @param  list<string>  $cells
     */
    public function __construct(float $x, float $y, array $cells, ?float $endX = null)
    {
        $this->x = $x;
        $this->y = $y;
        $this->cells = array_values($cells);
        $this->endX = $endX ?? $x;
    }

    public function x(): float
    {
        return $this->x;
    }

    /**
     * Right edge, where the extractor knows it. Backends that report a run's
     * origin only leave this equal to x, which is enough to place the run.
     */
    public function endX(): float
    {
        return $this->endX;
    }

    /**
     * Distance from the top of the page, so rows sort naturally.
     */
    public function y(): float
    {
        return $this->y;
    }

    /**
     * @return list<string>
     */
    public function cells(): array
    {
        return $this->cells;
    }

    public function text(): string
    {
        return implode(' ', $this->cells);
    }

    public function isEmpty(): bool
    {
        return trim($this->text()) === '';
    }
}
