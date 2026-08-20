<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Exception;

use InvalidArgumentException;

class InvalidSource extends InvalidArgumentException implements RapaportException
{
    /**
     * @param  mixed  $input
     */
    public static function unsupported($input): self
    {
        return new self(sprintf(
            'Cannot read a PDF from %s. Pass a path, raw PDF bytes, an SplFileInfo, '
            .'an uploaded file, a stream resource, or a PSR-7 stream.',
            is_object($input) ? get_class($input) : gettype($input)
        ));
    }

    public static function missing(string $path): self
    {
        return new self("PDF not found or not readable: {$path}");
    }

    public static function notAPdf(): self
    {
        return new self('The given content does not start with %PDF- and is not a readable file path.');
    }

    public static function empty(): self
    {
        return new self('The given PDF source is empty.');
    }
}
