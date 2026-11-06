<?php

declare(strict_types=1);

namespace AIC\Scaffold\Infrastructure;

use AIC\Scaffold\Domain\ScaffoldEngine;
use AIC\Scaffold\Domain\ScaffoldPlan;
use AIC\Scaffold\Exception\ScaffoldRefusal;
use Composer\Util\ProcessExecutor;

use function array_map;
use function array_merge;
use function is_string;
use function rtrim;
use function trim;

use const PHP_BINARY;

/**
 * Runs each engine of a plan with the PHP running Composer, at the app's root,
 * once the files are written, and gives back what it printed. An engine that
 * fails stops the install, with what it printed in the refusal.
 */
final readonly class ScaffoldEngines
{
    /**
     * @param ProcessExecutor $processExecutor Composer's process runner.
     */
    public function __construct(
        private ProcessExecutor $processExecutor,
    ) {
    }

    /**
     * @param ScaffoldPlan $scaffoldPlan The plan.
     * @param string       $root         The app's root, absolute.
     *
     * @throws ScaffoldRefusal When an engine exits with a status other than 0.
     *
     * @return list<string> For each engine, a line naming it and what it printed.
     */
    public function run(ScaffoldPlan $scaffoldPlan, string $root): array
    {
        return array_merge(...array_map(
            fn (ScaffoldEngine $scaffoldEngine): array => $this->engine($scaffoldEngine, $root),
            $scaffoldPlan->engines,
        ));
    }

    /**
     * @param ScaffoldEngine $scaffoldEngine The engine.
     * @param string         $root           The app's root.
     *
     * @throws ScaffoldRefusal When it exits with a status other than 0.
     *
     * @return list<string> A line naming it, and what it printed when it printed anything.
     */
    private function engine(ScaffoldEngine $scaffoldEngine, string $root): array
    {
        $output = '';
        $status = $this->processExecutor->execute(
            [PHP_BINARY, $scaffoldEngine->script, ...$scaffoldEngine->arguments],
            $output,
            $root,
        );
        $printed = is_string($output)
            ? rtrim($output)
            : '';

        if ($status !== 0) {
            throw new ScaffoldRefusal(
                $scaffoldEngine->package . "'s engine exited " . $status . ': '
                . trim($printed . "\n" . $this->processExecutor->getErrorOutput()),
            );
        }

        $ran = 'aic-scaffold: ran ' . $scaffoldEngine->package . "'s engine";

        return $printed === ''
            ? [$ran]
            : [$ran, $printed];
    }
}
