<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Result;

use JsonSerializable;

/**
 * One colour x clarity grid: a single shape and carat band.
 */
final class PriceTable implements JsonSerializable
{
    /** Grids on the main sheets are printed in hundreds of dollars per carat. */
    private const HUNDREDS = 100;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function page(): int
    {
        return (int) $this->data['page'];
    }

    /**
     * "list" for the main price sheets, "parcel" for the parcel price list.
     */
    public function category(): string
    {
        return (string) $this->data['category'];
    }

    public function isParcel(): bool
    {
        return $this->category() === 'parcel';
    }

    public function shape(): ?string
    {
        return $this->data['shape'];
    }

    public function sizeLabel(): string
    {
        return (string) $this->data['size_label'];
    }

    public function sizeMin(): float
    {
        return (float) $this->data['size_min'];
    }

    public function sizeMax(): float
    {
        return (float) $this->data['size_max'];
    }

    public function date(): ?string
    {
        return $this->data['date'];
    }

    public function unit(): string
    {
        return (string) $this->data['unit'];
    }

    /**
     * @return list<string>
     */
    public function clarities(): array
    {
        return $this->data['clarities'];
    }

    /**
     * @return list<string>
     */
    public function colors(): array
    {
        return $this->data['colors'];
    }

    /**
     * The whole grid as [colour][clarity] => number as printed.
     *
     * @return array<string, array<string, float|int|null>>
     */
    public function grid(): array
    {
        return $this->data['prices'];
    }

    /**
     * Rapaport's weighted average asking price index for this band, when the
     * sheet prints one.
     *
     * @return array<string, array{value: float, change_pct: float}>
     */
    public function index(): array
    {
        return $this->data['index'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->data['notes'];
    }

    /**
     * Anything that did not line up while reading this grid. Empty means the
     * columns and rows were fully consistent.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        return $this->data['issues'] ?? [];
    }

    public function isComplete(): bool
    {
        return $this->issues() === [];
    }

    public function covers(float $carats): bool
    {
        return $carats >= $this->sizeMin() && $carats <= $this->sizeMax();
    }

    /**
     * The number as printed on the sheet.
     *
     * @return float|int|null
     */
    public function price(string $color, string $clarity)
    {
        return $this->data['prices'][strtoupper($color)][strtoupper($clarity)] ?? null;
    }

    /**
     * The price resolved to dollars per carat, whichever unit the sheet used.
     */
    public function usdPerCarat(string $color, string $clarity): ?float
    {
        $printed = $this->price($color, $clarity);

        if ($printed === null) {
            return null;
        }

        return $this->isParcel()
            ? (float) $printed
            : round($printed * self::HUNDREDS, 2);
    }

    /**
     * Every cell of this grid, flattened.
     *
     * @return list<Price>
     */
    public function prices(): array
    {
        $prices = [];

        foreach ($this->grid() as $color => $cells) {
            foreach ($cells as $clarity => $printed) {
                if ($printed === null) {
                    continue;
                }

                $prices[] = new Price(
                    $this->page(),
                    $this->category(),
                    $this->shape(),
                    $this->sizeLabel(),
                    $this->sizeMin(),
                    $this->sizeMax(),
                    (string) $color,
                    (string) $clarity,
                    $printed,
                    (float) $this->usdPerCarat((string) $color, (string) $clarity),
                    $this->date()
                );
            }
        }

        return $prices;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
