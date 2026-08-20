<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Exception;

use RuntimeException;
use Throwable;

class ExtractionFailed extends RuntimeException implements RapaportException
{
    public static function unreadable(Throwable $previous): self
    {
        return new self('The PDF could not be read: '.$previous->getMessage(), 0, $previous);
    }

    public static function noText(): self
    {
        return new self(
            'No text layer found in the PDF. Scanned or image-only price lists need OCR first.'
        );
    }

    public static function noTables(): self
    {
        return new self(
            'No Rapaport price grids were found. Is this a Rapaport Diamond Report PDF?'
        );
    }
}
