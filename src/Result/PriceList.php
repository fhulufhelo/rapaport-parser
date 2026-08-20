<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Everything read out of one report.
 *
 * @implements IteratorAggregate<int, PriceTable>
 */
final class PriceList implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, mixed> */
    private array $meta;

    /** @var list<PriceTable> */
    private array $tables;

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<PriceTable>  $tables
     */
    public function __construct(array $meta, array $tables)
    {
        $this->meta = $meta;
        $this->tables = array_values($tables);
    }

    /**
     * Issue date, volume, number and market taken from the report masthead.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return list<PriceTable>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    public function count(): int
    {
        return count($this->tables);
    }

    /**
     * @return Traversable<int, PriceTable>
     */
    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tables);
    }

    /**
     * Shapes present in the report, in the order they first appear.
     *
     * @return list<string>
     */
    public function shapes(): array
    {
        $shapes = [];

        foreach ($this->tables as $table) {
            $shape = $table->shape();

            if ($shape !== null && ! in_array($shape, $shapes, true)) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    /**
     * A copy holding only the grids the callback keeps.
     *
     * @param  callable(PriceTable): bool  $filter
     */
    public function filter(callable $filter): self
    {
        return new self($this->meta, array_values(array_filter($this->tables, $filter)));
    }

    public function forShape(string $shape): self
    {
        $shape = strtoupper($shape);

        return $this->filter(static function (PriceTable $table) use ($shape): bool {
            return $table->shape() === $shape;
        });
    }

    /**
     * Only the main price sheets, or only the parcel sheet.
     */
    public function ofCategory(string $category): self
    {
        return $this->filter(static function (PriceTable $table) use ($category): bool {
            return $table->category() === $category;
        });
    }

    /**
     * The grid covering a stone of this shape and weight.
     */
    public function find(string $shape, float $carats, string $category = 'list'): ?PriceTable
    {
        $shape = strtoupper($shape);

        foreach ($this->tables as $table) {
            if ($table->shape() === $shape
                && $table->category() === $category
                && $table->covers($carats)) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Look up one price in dollars per carat.
     */
    public function priceFor(
        string $shape,
        float $carats,
        string $color,
        string $clarity,
        string $category = 'list'
    ): ?float {
        $table = $this->find($shape, $carats, $category);

        return $table === null ? null : $table->usdPerCarat($color, $clarity);
    }

    /**
     * Everything that did not line up, keyed by grid. Empty means every grid
     * read cleanly; check it before trusting a sheet you have not seen before.
     *
     * @return array<string, list<string>>
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->tables as $table) {
            if ($table->issues() !== []) {
                $issues[$table->shape().' '.$table->sizeLabel()] = $table->issues();
            }
        }

        foreach ($this->meta['pages_without_text'] ?? [] as $page) {
            $issues['page '.$page] = ['No text was read from this page.'];
        }

        return $issues;
    }

    public function isComplete(): bool
    {
        return $this->issues() === [];
    }

    /**
     * Every cell of every grid, flattened.
     *
     * @return list<Price>
     */
    public function prices(): array
    {
        $prices = [];

        foreach ($this->tables as $table) {
            foreach ($table->prices() as $price) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return array_map(static function (Price $price): array {
            return $price->toArray();
        }, $this->prices());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'tables' => array_map(static function (PriceTable $table): array {
                return $table->toArray();
            }, $this->tables),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }

    /**
     * Write the flattened prices as CSV. Pass a path, or omit it to get the CSV
     * back as a string.
     */
    public function toCsv(?string $path = null): string
    {
        $rows = $this->rows();
        $handle = fopen($path ?? 'php://temp', $path === null ? 'r+' : 'w');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV target: '.($path ?? 'php://temp'));
        }

        if ($rows !== []) {
            $this->putCsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                $this->putCsv($handle, $row);
            }
        }

        if ($path !== null) {
            fclose($handle);

            return $path;
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return array<string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  resource  $handle
     * @param  array<int|string, mixed>  $fields
     */
    private function putCsv($handle, array $fields): void
    {
        // The escape argument is explicit because relying on its default is
        // deprecated from PHP 8.1 onwards.
        fputcsv($handle, array_values($fields), ',', '"', '');
    }
}
