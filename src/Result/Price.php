<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Result;

use JsonSerializable;

/**
 * A single cell of a grid, flattened for a CSV row or a database insert.
 */
final class Price implements JsonSerializable
{
    private int $page;

    private string $category;

    private ?string $shape;

    private string $sizeLabel;

    private float $sizeMin;

    private float $sizeMax;

    private string $color;

    private string $clarity;

    /** @var float|int */
    private $printed;

    private float $usdPerCarat;

    private ?string $date;

    /**
     * @param  float|int  $printed
     */
    public function __construct(
        int $page,
        string $category,
        ?string $shape,
        string $sizeLabel,
        float $sizeMin,
        float $sizeMax,
        string $color,
        string $clarity,
        $printed,
        float $usdPerCarat,
        ?string $date
    ) {
        $this->page = $page;
        $this->category = $category;
        $this->shape = $shape;
        $this->sizeLabel = $sizeLabel;
        $this->sizeMin = $sizeMin;
        $this->sizeMax = $sizeMax;
        $this->color = $color;
        $this->clarity = $clarity;
        $this->printed = $printed;
        $this->usdPerCarat = $usdPerCarat;
        $this->date = $date;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function shape(): ?string
    {
        return $this->shape;
    }

    public function sizeLabel(): string
    {
        return $this->sizeLabel;
    }

    public function sizeMin(): float
    {
        return $this->sizeMin;
    }

    public function sizeMax(): float
    {
        return $this->sizeMax;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function clarity(): string
    {
        return $this->clarity;
    }

    /**
     * The number exactly as printed on the sheet.
     *
     * @return float|int
     */
    public function printed()
    {
        return $this->printed;
    }

    public function usdPerCarat(): float
    {
        return $this->usdPerCarat;
    }

    public function date(): ?string
    {
        return $this->date;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'category' => $this->category,
            'shape' => $this->shape,
            'size_label' => $this->sizeLabel,
            'size_min' => $this->sizeMin,
            'size_max' => $this->sizeMax,
            'color' => $this->color,
            'clarity' => $this->clarity,
            'printed' => $this->printed,
            'usd_per_carat' => $this->usdPerCarat,
            'date' => $this->date,
        ];
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
