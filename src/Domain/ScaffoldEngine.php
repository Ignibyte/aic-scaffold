<?php

declare(strict_types=1);

namespace AIC\Scaffold\Domain;

/**
 * A package's own scaffolder, run with PHP at the app's root once the files are
 * written, such as the gates' `aic-gate init`.
 */
final readonly class ScaffoldEngine
{
    /**
     * @param string       $package   The package that declares it.
     * @param string       $script    The absolute path of its PHP script.
     * @param list<string> $arguments What the script is given.
     */
    public function __construct(
        public string $package,
        public string $script,
        public array $arguments,
    ) {
    }
}
