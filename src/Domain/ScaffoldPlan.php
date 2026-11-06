<?php

declare(strict_types=1);

namespace AIC\Scaffold\Domain;

/**
 * What the scaffold plugin applies: the root files, each destination once, and
 * the engines to run after them, in package order.
 */
final readonly class ScaffoldPlan
{
    /**
     * @param list<ScaffoldFile>   $files   The files.
     * @param list<ScaffoldEngine> $engines The engines.
     */
    public function __construct(
        public array $files = [],
        public array $engines = [],
    ) {
    }
}
