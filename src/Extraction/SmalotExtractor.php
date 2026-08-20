<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Extraction;

use Fhulufhelo\Rapaport\Exception\ExtractionFailed;
use Fhulufhelo\Rapaport\Source;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Throwable;

/**
 * Pure-PHP extraction built on smalot/pdfparser.
 *
 * smalot handles the PDF container — cross reference tables, object streams,
 * decompression, the page tree and the font maps. The text positioning is done
 * here rather than through getDataTm() for two reasons:
 *
 *  1. Cell boundaries have to be exact. A grid line is drawn as one TJ array
 *     whose elements are the individual prices, separated by wide kerning
 *     ("-2462") while the digits inside one price are nudged by a fraction
 *     ("-0.2"). Reading the kerning directly separates the cells with no
 *     guesswork; smalot's own word splitter merges some rows of single digit
 *     prices into one string.
 *  2. Some pages hold their content as an array of streams that smalot's
 *     getContent() returns empty, which would silently drop whole pages.
 */
final class SmalotExtractor implements Extractor
{
    /**
     * Kerning, in thousandths of the font size, wide enough to count as a gap
     * between two cells rather than a nudge inside one.
     */
    private const CELL_GAP = -100.0;

    /** Operators that paint text and therefore close a run. */
    private const SHOW_TEXT = ['Tj' => true, 'TJ' => true, "'" => true, '"' => true];

    private const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    private float $cellGap;

    private ?Parser $parser;

    public function __construct(?Parser $parser = null, float $cellGap = self::CELL_GAP)
    {
        $this->parser = $parser;
        $this->cellGap = $cellGap;
    }

    /**
     * @return list<PageText>
     */
    public function extract(Source $source): array
    {
        try {
            $document = $this->parser()->parseContent($source->bytes());
            $pages = $document->getPages();
        } catch (Throwable $e) {
            throw ExtractionFailed::unreadable($e);
        }

        $result = [];

        foreach ($pages as $index => $page) {
            try {
                $runs = $this->readPage($document, $page);
            } catch (Throwable $e) {
                // One unreadable page should not sink the document.
                $runs = [];
            }

            $result[] = new PageText($index + 1, $runs);
        }

        foreach ($result as $page) {
            if ($page->runs() !== []) {
                return $result;
            }
        }

        throw ExtractionFailed::noText();
    }

    /**
     * @return list<TextRun>
     */
    private function readPage(Document $document, Page $page): array
    {
        $content = $this->contentOf($document, $page);

        if ($content === '') {
            return [];
        }

        // Each section carries a single operator, so the text state has to be
        // tracked across all of them rather than per section.
        $commands = [];

        foreach ($page->getSectionsText($content) as $section) {
            foreach ($page->getCommandsText($section) as $command) {
                $commands[] = $command;
            }
        }

        return $this->readCommands($page, $commands);
    }

    /**
     * Walk a page's operators, tracking the text matrix so every painted run
     * gets a position.
     *
     * @param  list<array<string, mixed>>  $commands
     * @return list<TextRun>
     */
    private function readCommands(Page $page, array $commands): array
    {
        $matrix = self::IDENTITY;      // text matrix, already combined with the CTM
        $concat = self::IDENTITY;      // current transformation matrix
        $stack = [];
        $x = 0.0;
        $y = 0.0;
        $leading = 0.0;
        $font = null;

        $runs = [];

        foreach ($commands as $command) {
            $operator = $command['o'] ?? '';
            $argument = $command['c'] ?? '';

            if (isset(self::SHOW_TEXT[$operator])) {
                if ($operator === "'" || $operator === '"') {
                    $y -= $leading;
                    $matrix[5] = $y;
                }

                $cells = $this->cellsOf($command, $font);

                if ($cells !== []) {
                    // Text space runs bottom-up; negate so rows sort top-down.
                    $runs[] = new TextRun((float) $matrix[4], -(float) $matrix[5], $cells);
                }

                continue;
            }

            switch ($operator) {
                case 'BT':
                    $matrix = self::IDENTITY;
                    $leading = 0.0;
                    $x = 0.0;
                    $y = 0.0;
                    break;

                case 'q':
                    $stack[] = $concat;
                    break;

                case 'Q':
                    $concat = array_pop($stack) ?? self::IDENTITY;
                    break;

                case 'cm':
                    $concat = $this->multiply($this->numbers($argument), $concat);
                    break;

                case 'Tm':
                    $matrix = $this->multiply($this->numbers($argument), $concat);
                    $x = (float) $matrix[4];
                    $y = (float) $matrix[5];
                    break;

                case 'TL':
                    $leading = (float) $argument * (float) $matrix[3];
                    break;

                case 'Td':
                case 'TD':
                    $coords = $this->numbers($argument);
                    $dx = $coords[0] ?? 0.0;
                    $dy = $coords[1] ?? 0.0;

                    if ($operator === 'TD') {
                        $leading = -($dy * (float) $matrix[3]);
                    }

                    $x += $dx * (float) $matrix[0];
                    $y += $dy * (float) $matrix[3];
                    $matrix[4] = $x;
                    $matrix[5] = $y;
                    break;

                case 'T*':
                    $y -= $leading;
                    $matrix[5] = $y;
                    break;

                case 'Tf':
                    $font = $this->fontOf($page, $argument);
                    break;
            }
        }

        return $runs;
    }

    /**
     * Split one text-painting command into cells.
     *
     * A TJ array interleaves strings with kerning adjustments. Anything more
     * negative than the gap threshold starts a new cell; smaller nudges are
     * kerning inside a single value and are joined.
     *
     * @param  array<string, mixed>  $command
     * @return list<string>
     */
    private function cellsOf(array $command, ?Font $font): array
    {
        $parts = $command['c'] ?? '';

        if (! is_array($parts)) {
            return $this->clean([$this->decode($command, $font)]);
        }

        $cells = [];
        $buffer = '';

        foreach ($parts as $part) {
            if (($part['t'] ?? '') === 'n') {
                if ((float) $part['c'] <= $this->cellGap) {
                    $cells[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            $buffer .= $this->decode($part, $font);
        }

        $cells[] = $buffer;

        return $this->clean($cells);
    }

    /**
     * Decode one string element through its font, so a two-byte Identity-H code
     * becomes the single byte the shifted alphabet uses.
     *
     * @param  array<string, mixed>  $element
     */
    private function decode(array $element, ?Font $font): string
    {
        if ($font === null) {
            return (string) ($element['c'] ?? '');
        }

        // Decoding one element at a time makes merging impossible.
        return $font->decodeText([$element]);
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function clean(array $cells): array
    {
        $clean = [];

        foreach ($cells as $cell) {
            // Only whitespace is stripped: control bytes are payload here, they
            // carry the clarity suffix digits.
            $cell = trim(str_replace("\x00", '', $cell), " \t\r\n\f\v");

            if ($cell !== '') {
                $clean[] = $cell;
            }
        }

        return $clean;
    }

    private function fontOf(Page $page, string $argument): ?Font
    {
        $parts = preg_split('/\s+/', trim($argument)) ?: [];
        $id = ltrim((string) ($parts[0] ?? ''), '/');

        return $id === '' ? null : $page->getFont($id);
    }

    /**
     * The page's content streams, joined.
     *
     * Contents may be a single stream, an array of streams, or an object whose
     * header holds the array. smalot returns an empty string for the last of
     * those, which would lose the page entirely.
     */
    private function contentOf(Document $document, Page $page): string
    {
        $contents = $page->get('Contents');
        $parts = [];

        foreach ($this->streamsOf($contents) as $stream) {
            $text = (string) $stream->getContent();

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($parts === []) {
            $direct = (string) $page->getContent();

            if ($direct !== '') {
                $parts[] = $direct;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  mixed  $contents
     * @return list<PDFObject>
     */
    private function streamsOf($contents): array
    {
        if ($contents instanceof ElementArray) {
            return $this->flatten($contents->getContent());
        }

        if ($contents instanceof PDFObject) {
            if ((string) $contents->getContent() !== '') {
                return [$contents];
            }

            $header = $contents->getHeader();

            if ($header !== null) {
                return $this->flatten($header->getElements());
            }
        }

        return [];
    }

    /**
     * @param  mixed  $elements
     * @return list<PDFObject>
     */
    private function flatten($elements): array
    {
        if (! is_array($elements)) {
            return [];
        }

        $streams = [];

        foreach ($elements as $element) {
            if ($element instanceof PDFObject) {
                $streams[] = $element;
            }
        }

        return $streams;
    }

    /**
     * @return list<float>
     */
    private function numbers(string $argument): array
    {
        $parts = preg_split('/\s+/', trim($argument)) ?: [];

        return array_map('floatval', array_values(array_filter($parts, 'strlen')));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    private function multiply(array $a, array $b): array
    {
        $a += self::IDENTITY;
        $b += self::IDENTITY;

        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    private function parser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = new Parser;
        }

        return $this->parser;
    }
}
