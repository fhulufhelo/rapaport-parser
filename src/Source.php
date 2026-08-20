<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport;

use Fhulufhelo\Rapaport\Exception\InvalidSource;
use SplFileInfo;

/**
 * Normalises whatever the caller has to hand into raw PDF bytes.
 *
 * Accepts a path, the bytes themselves, an SplFileInfo (which covers Laravel's
 * and Symfony's UploadedFile), a stream resource, and PSR-7 uploaded files or
 * streams. Anything exposing getPathname(), getRealPath(), getStream() or
 * __toString() is handled by duck typing, so framework classes work without
 * this package depending on them.
 */
final class Source
{
    /** PDFs may carry junk before the header, so allow a short run-up. */
    private const HEADER_SEARCH_BYTES = 1024;

    private string $bytes;

    private ?string $name;

    private function __construct(string $bytes, ?string $name)
    {
        $this->bytes = $bytes;
        $this->name = $name;
    }

    /**
     * @param  mixed  $input  path, raw bytes, SplFileInfo, uploaded file, resource or PSR-7 stream
     */
    public static function from($input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if (is_string($input)) {
            return self::fromString($input);
        }

        if (is_resource($input)) {
            return new self(self::readStream($input), null);
        }

        if (is_object($input)) {
            return self::fromObject($input);
        }

        throw InvalidSource::unsupported($input);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Original filename when the source came from disk or an upload.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    private static function fromString(string $input): self
    {
        if (self::looksLikePdf($input)) {
            return new self($input, null);
        }

        // Not PDF bytes, so it has to be a path. Guard against passing a huge
        // blob to the filesystem functions.
        if (strlen($input) > PHP_MAXPATHLEN || $input === '') {
            throw InvalidSource::notAPdf();
        }

        return self::fromPath($input);
    }

    private static function fromPath(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidSource::missing($path);
        }

        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw InvalidSource::empty();
        }

        return new self($bytes, basename($path));
    }

    private static function fromObject(object $input): self
    {
        // Laravel and Symfony UploadedFile both extend SplFileInfo.
        if ($input instanceof SplFileInfo) {
            $path = $input->getRealPath();

            if ($path === false || $path === '') {
                $path = $input->getPathname();
            }

            $source = self::fromPath($path);

            return new self($source->bytes, self::uploadedName($input) ?? $source->name);
        }

        // PSR-7 UploadedFileInterface.
        if (method_exists($input, 'getStream')) {
            $stream = $input->getStream();
            $name = method_exists($input, 'getClientFilename') ? $input->getClientFilename() : null;

            return new self(self::readPsrStream($stream), $name);
        }

        // PSR-7 StreamInterface, or anything stringable.
        if (method_exists($input, '__toString')) {
            return new self(self::readPsrStream($input), null);
        }

        throw InvalidSource::unsupported($input);
    }

    private static function uploadedName(SplFileInfo $file): ?string
    {
        if (method_exists($file, 'getClientOriginalName')) {
            $name = $file->getClientOriginalName();

            return is_string($name) && $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * @param  resource  $stream
     */
    private static function readStream($stream): string
    {
        $bytes = stream_get_contents($stream);

        if ($bytes === false || $bytes === '') {
            throw InvalidSource::empty();
        }

        return $bytes;
    }

    /**
     * @param  object  $stream  PSR-7 StreamInterface, or any stringable
     */
    private static function readPsrStream(object $stream): string
    {
        if (method_exists($stream, 'isSeekable') && method_exists($stream, 'rewind')) {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }

        $bytes = method_exists($stream, 'getContents')
            ? $stream->getContents()
            : (string) $stream;

        if ($bytes === '') {
            throw InvalidSource::empty();
        }

        return $bytes;
    }

    private static function looksLikePdf(string $input): bool
    {
        return strpos(substr($input, 0, self::HEADER_SEARCH_BYTES), '%PDF-') !== false;
    }
}
