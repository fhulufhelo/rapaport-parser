<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Exception;

use RuntimeException;

class ExtractorUnavailable extends RuntimeException implements RapaportException
{
    public static function poppler(string $binary): self
    {
        return new self(
            "Cannot find '{$binary}'. This package reads the price grids through poppler, "
            ."which lays glyphs out with the font's own metrics and so separates the columns "
            ."correctly however a sheet spaced them.\n"
            ."  Debian/Ubuntu:  apt-get install poppler-utils\n"
            ."  macOS:          brew install poppler\n"
            ."  Alpine:         apk add poppler-utils\n"
            ."Point at a non-standard location with RapaportParser::make()->using(new PopplerExtractor('/path/to/pdftotext')). "
            .'A pure-PHP fallback exists but is best effort: see SmalotExtractor.'
        );
    }
}
