# Rapaport Price List Parser

[![CI](https://github.com/fhulufhelo/rapaport-parser/actions/workflows/ci.yml/badge.svg)](https://github.com/fhulufhelo/rapaport-parser/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.0%20%E2%80%93%208.5-777bb4)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Reads the colour × clarity price grids out of a Rapaport Diamond Report PDF and hands them back as structured data.

Works on **PHP 8.0 → 8.5**. Framework-agnostic, with optional Laravel wiring.

> **Unofficial.** Not affiliated with, authorised by, or endorsed by Rapaport USA Inc. This is a reader for a report you already hold a licence to; it ships no price data of its own.

```php
use Fhulufhelo\Rapaport\RapaportParser;

$list = RapaportParser::make()->parse($pathOrBytesOrUpload);

count($list);                                    // 42 grids
$list->priceFor('ROUND', 1.05, 'G', 'VS1');      // 5400.0  (US$ per carat)
$list->toArray();                                // everything, as nested arrays
```

---

## Contents

- [Install](#install)
- [Input](#input)
- [Output](#output)
- [Looking things up](#looking-things-up)
- [Integrity](#integrity)
- [Laravel](#laravel)
- [Extraction backends](#extraction-backends)
- [How the sheet is read](#how-the-sheet-is-read)
- [Testing](#testing)

---

## Install

```bash
composer require fhulufhelo/rapaport-parser
```

Not on Packagist yet, so add the repository first:

```bash
composer config repositories.rapaport vcs https://github.com/fhulufhelo/rapaport-parser
composer require fhulufhelo/rapaport-parser:dev-main
```

It also needs **poppler** for text extraction:

```bash
apt-get install poppler-utils   # Debian / Ubuntu
brew install poppler            # macOS
apk add poppler-utils           # Alpine
```

See [Extraction backends](#extraction-backends) for why, and for the pure-PHP fallback.

---

## Input

`parse()` takes whatever you have to hand and works out what it is:

```php
$parser = RapaportParser::make();

$parser->parse('/reports/rapaport.pdf');            // path
$parser->parse(file_get_contents($path));           // raw PDF bytes
$parser->parse(new SplFileInfo($path));             // SplFileInfo
$parser->parse($request->file('report'));           // Laravel / Symfony upload
$parser->parse(fopen($path, 'r'));                  // stream resource
$parser->parse($psr7UploadedFile);                  // PSR-7 upload or stream
```

Uploads and PSR-7 objects are handled by duck typing, so the package does not depend on Laravel, Symfony or `psr/http-message`.

A path that does not exist, or bytes that are not a PDF, throw `Exception\InvalidSource`. Every exception in the package implements `Exception\RapaportException`, so one catch covers them all.

---

## Output

`parse()` returns a `PriceList` — countable, iterable and JSON-serialisable.

```php
foreach ($list as $table) {
    $table->page();        // 1
    $table->category();    // 'list' | 'parcel'
    $table->shape();       // 'ROUND', 'PEAR', …
    $table->sizeLabel();   // '.30 - .39 CT.'
    $table->sizeMin();     // 0.30
    $table->sizeMax();     // 0.39
    $table->date();        // '07/03/26'
    $table->unit();        // 'hundreds of US$ per carat'

    $table->clarities();   // ['IF','VVS1','VVS2','VS1','VS2','SI1','SI2','SI3','I1','I2','I3']
    $table->colors();      // ['D','E','F','G','H','I','J','K','L','M']
    $table->grid();        // ['D' => ['IF' => 27, 'VVS1' => 22, …], …]

    $table->index();       // ['W' => ['value' => 17.0, 'change_pct' => 0.0], …]
    $table->notes();       // ['0.60 - 0.69 may trade at 10% to 15% premiums over 0.50']
}
```

Nothing about the report's size is hard-coded. The page count, grids per page, colour rows, clarity columns and shapes are all read from the document, so a sheet with more or fewer of any of them parses the same way.

### Flat rows, JSON, CSV

```php
$list->prices();            // Price[] — one per cell
$list->rows();              // the same as plain arrays
$list->toArray();
$list->toJson(JSON_PRETTY_PRINT);
$list->toCsv('/tmp/prices.csv');   // to a file
$csv = $list->toCsv();             // or as a string
```

Each flat row:

| Field | Notes |
|---|---|
| `page`, `category`, `shape` | |
| `size_label`, `size_min`, `size_max` | |
| `color`, `clarity` | |
| `printed` | the number exactly as printed |
| `usd_per_carat` | resolved dollars per carat |
| `date` | |

### Units

The main sheets print **hundreds of US$ per carat** — a printed `27` is **$2,700/ct**. The parcel sheet prints whole dollars per carat. `printed` keeps what is on the page; `usd_per_carat` normalises both, so use that one for arithmetic.

---

## Looking things up

```php
$list->shapes();                        // ['ROUND', 'PEAR']
$list->forShape('ROUND');               // filtered PriceList
$list->ofCategory('parcel');            // filtered PriceList
$list->filter(fn ($t) => $t->sizeMin() >= 1.0);

$table = $list->find('ROUND', 1.05);    // the grid covering a 1.05ct round
$table->price('G', 'VS1');              // 54    as printed
$table->usdPerCarat('G', 'VS1');        // 5400.0

$list->priceFor('ROUND', 1.05, 'G', 'VS1');   // 5400.0, or null
```

---

## Integrity

The parser reports anything that did not line up instead of quietly filing a price under the wrong clarity.

```php
if (! $list->isComplete()) {
    foreach ($list->issues() as $where => $problems) {
        logger()->warning("Rapaport {$where}", $problems);
    }
}
```

An issue is raised when a colour row holds a different number of prices than the grid is wide, when the clarity header does not match the grid width, when no header could be read, or when a page yielded no text at all. On a clean report `issues()` is empty.

Worth checking on any sheet you have not parsed before.

---

## Laravel

The service provider is auto-discovered. `RapaportParser` resolves from the container:

```php
use Fhulufhelo\Rapaport\RapaportParser;

public function store(Request $request, RapaportParser $parser)
{
    $list = $parser->parse($request->file('report'));

    Price::upsert($list->rows(), ['shape', 'size_label', 'color', 'clarity']);
}
```

And a command:

```bash
php artisan rapaport:extract report.pdf --out=prices.csv
php artisan rapaport:extract report.pdf --format=json
```

---

## Extraction backends

Text extraction sits behind `Extraction\Extractor`, so it can be swapped.

**`PopplerExtractor` (default).** Shells out to `pdftotext -bbox-layout`. poppler lays every glyph out using the font's own metrics and reports a box per word, so the columns come apart correctly however the sheet spaced them. This is the accurate backend and the one the test suite covers.

**`SmalotExtractor` (pure PHP, best effort).** Uses `smalot/pdfparser`, no binary needed:

```php
RapaportParser::make()->using(new SmalotExtractor())->parse($pdf);
```

It is **not** used as an automatic fallback, and should not be trusted without checking `issues()`. On the July 2026 report it recovers 26 of 42 grids: two pages hold their content in a form it reads as empty, and some rows of single-digit prices are laid out by glyph advance rather than kerning, which cannot be separated without font metrics that the library does not expose. A price list that is quietly incomplete is worse than one that refuses to load, so a missing `pdftotext` raises `Exception\ExtractorUnavailable` rather than silently degrading.

Point at a binary in a non-standard place:

```php
RapaportParser::make()->using(new PopplerExtractor('/usr/local/bin/pdftotext'));
```

---

## How the sheet is read

Two things about the report make a naive read wrong, and both are handled:

**1. The clarity headers are drawn in a shifted font.** Every glyph sits 29 code points below the character it renders, and the suffix digits land on control-code bytes: `0x10` → `-`, `0x14` → `1`, `0x15` → `2`, `0x16` → `3`. So `,)\x10996` is `IF-VVS`. Extractors that sanitise control characters drop those digits, turning both `VVS1` and `VVS2` into `VVS`. The package parks those bytes in the Unicode private use area to survive XML parsing, then restores them — recovering the exact printed headers rather than guessing them from the column count.

**2. Two grids share every line.** They are printed side by side with one colour label sitting in the gutter between them. Each grid is given a horizontal span taken from the widest gaps on its clarity header line, and text is then placed by the span it falls in. Title extents are no use for this: on the parcel sheet the titles are short and left-aligned, so their midpoint lands inside the left-hand grid.

Colour rows are also tried before clarity headers, because `I` is both a colour grade and a clarity grade — a line led by `I` holding eleven numbers is the I colour row, not a one-column header.

---

## Testing

```bash
composer install
RAPAPORT_FIXTURE=/path/to/report.pdf vendor/bin/phpunit
```

The suite skips itself without a fixture, since the reports are copyrighted and are not committed. Against the July 2026 report (5 pages, 42 grids, 3,360 prices) it checks that:

- every grid reads with no issues, and every row is as wide as its header with no gaps
- the clarity suffixes survive — no `VVS,VVS`, no numbered `COL` fallbacks
- prices never rise as colour and clarity worsen, across every row and column of every grid — the check that catches a silently shifted column
- the hundreds/per-carat unit handling is right for both sheet types
- path, raw bytes, `SplFileInfo` and stream inputs all give the same result

The extracted values were additionally cross-checked, cell for cell, against an independent `pdftotext -layout` extraction of all 165 colour lines: 0 mismatches.

---

## Licence

MIT — see [LICENSE](LICENSE). Note that `smalot/pdfparser`, used by the optional pure-PHP backend, is LGPL-3.0.

The Rapaport Diamond Report is copyrighted and its redistribution is restricted; this parser is for reading a list you are licensed to hold.
