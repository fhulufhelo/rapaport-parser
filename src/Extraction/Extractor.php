<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Extraction;

use Fhulufhelo\Rapaport\Source;

interface Extractor
{
    /**
     * Pull positioned text out of a PDF.
     *
     * Implementations must preserve the raw byte values of the text. The
     * clarity headers are drawn in a shifted font whose suffix digits land on
     * control-code bytes; an extractor that strips or normalises those bytes
     * loses the difference between VVS1 and VVS2.
     *
     * @return list<PageText>
     */
    public function extract(Source $source): array;
}
