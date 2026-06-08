<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant requests an isolation mode that is not yet enabled
 * (e.g. dedicated-DB "mixed mode", which is architected but not implemented).
 */
class UnsupportedIsolationModeException extends RuntimeException {}
