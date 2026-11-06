<?php

declare(strict_types=1);

namespace AIC\Scaffold\Domain;

/**
 * One root file the scaffold plugin keeps: where it goes, how, and the file it
 * is made from.
 */
final readonly class ScaffoldFile
{
    /**
     * @param string       $destination The path under the app's root, such as `bin/aic`.
     * @param ScaffoldMode $mode        How it is kept.
     * @param string       $source      The absolute path of the file it is made from.
     * @param string       $owner       The package that declares it, or `the root` for the app's own copy.
     */
    public function __construct(
        public string $destination,
        public ScaffoldMode $mode,
        public string $source,
        public string $owner,
    ) {
    }
}
