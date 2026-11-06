<?php

declare(strict_types=1);

namespace AIC\Scaffold\Exception;

use RuntimeException;

/**
 * A scaffold declaration or override the plugin cannot apply: its message names
 * the file or the package and the cause, and the install stops before any file
 * is written.
 */
final class ScaffoldRefusal extends RuntimeException
{
}
