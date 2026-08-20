<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Extraction;

use Fhulufhelo\Rapaport\Exception\ExtractionFailed;
use Fhulufhelo\Rapaport\Source;
use DOMDocument;
use DOMElement;

/**
 * Extraction through poppler's pdftotext, which reports a bounding box per word.
 *
 * This is the accurate backend: poppler lays out every glyph using the font's
 * own metrics, so each price gets a real position no matter whether the sheet
 * separated its columns by kerning or by glyph advance alone.
 *
 * It needs the pdftotext binary (poppler-utils) on the host.
 */
final class PopplerExtractor implements Extractor
{
    /**
     * Private use area codepoint that control byte 0x00 is parked on.
     *
     * The clarity headers are drawn in a shifted font whose suffix digits land
     * on control-code bytes, which XML 1.0 forbids outright. Parking them keeps
     * the document parseable without losing the digits.
     */
    private const CTRL_ESCAPE_BASE = 0xE000;

    private string $binary;

    private int $timeout;

    public function __construct(string $binary = 'pdftotext', int $timeout = 60)
    {
        $this->binary = $binary;
        $this->timeout = $timeout;
    }

    /**
     * Whether the binary is present and runnable.
     */
    public function isAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $probe = @proc_open(
            escapeshellcmd($this->binary).' -v',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($probe)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($probe) === 0;
    }

    /**
     * @return list<PageText>
     */
    public function extract(Source $source): array
    {
        $xml = $this->run($source->bytes());

        // Drop the XHTML namespace so the custom <page>/<word> elements poppler
        // emits can be found by tag name.
        $xml = str_replace(' xmlns="http://www.w3.org/1999/xhtml"', '', $xml);

        $xml = (string) preg_replace_callback(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            function (array $m): string {
                return $this->encodeControl(ord($m[0]));
            },
            $xml
        );

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw ExtractionFailed::noText();
        }

        $pages = [];
        $number = 0;

        foreach ($document->getElementsByTagName('page') as $page) {
            /** @var DOMElement $page */
            $pages[] = new PageText(++$number, $this->runsOf($page));
        }

        if ($pages === []) {
            throw ExtractionFailed::noText();
        }

        return $pages;
    }

    /**
     * Every word becomes a one-cell run carrying its exact box; the grid parser
     * groups them back into columns.
     *
     * @return list<TextRun>
     */
    private function runsOf(DOMElement $page): array
    {
        $runs = [];

        foreach ($page->getElementsByTagName('word') as $word) {
            /** @var DOMElement $word */
            $text = $this->decodeControls($word->textContent);

            if (trim($text) === '') {
                continue;
            }

            $runs[] = new TextRun(
                (float) $word->getAttribute('xMin'),
                (float) $word->getAttribute('yMin'),
                [$text],
                (float) $word->getAttribute('xMax')
            );
        }

        return $runs;
    }

    private function run(string $bytes): string
    {
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled, so pdftotext cannot be run.');
        }

        $command = escapeshellcmd($this->binary).' -bbox-layout -q - -';

        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            throw new \RuntimeException("Could not run {$this->binary}. Install poppler-utils.");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        fwrite($pipes[0], $bytes);
        fclose($pipes[0]);

        $out = '';
        $err = '';
        $deadline = time() + $this->timeout;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process);
                break;
            }

            usleep(2000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (trim($out) === '') {
            throw new \RuntimeException('pdftotext produced no output. '.trim($err));
        }

        return $out;
    }

    private function encodeControl(int $byte): string
    {
        $code = self::CTRL_ESCAPE_BASE + $byte;

        // Manual UTF-8 encoding for the 3-byte range, so the class does not
        // depend on mbstring being present for this step.
        return chr(0xE0 | ($code >> 12))
            .chr(0x80 | (($code >> 6) & 0x3F))
            .chr(0x80 | ($code & 0x3F));
    }

    private function decodeControls(string $text): string
    {
        if (strpos($text, "\xEE\x80") === false) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\xEE\x80([\x80-\xBF])/',
            static function (array $m): string {
                return chr(ord($m[1]) & 0x3F);
            },
            $text
        );
    }
}
