<?php

declare(strict_types=1);

namespace Fhulufhelo\Rapaport\Exception;

/**
 * Marker for every exception this package throws, so callers can catch the
 * whole family with one clause.
 */
interface RapaportException extends \Throwable {}
