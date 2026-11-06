<?php

declare(strict_types=1);

namespace AIC\Scaffold\Application;

use AIC\Scaffold\Domain\ScaffoldEngine;
use AIC\Scaffold\Domain\ScaffoldFile;
use AIC\Scaffold\Domain\ScaffoldPlan;
use AIC\Scaffold\Exception\ScaffoldRefusal;
use stdClass;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function implode;
use function in_array;
use function is_file;

/**
 * Merges every package's scaffold declarations with the root package's own
 * `extra.aic-scaffold`: a `file-mapping` entry `false` opts the app out of a
 * file and a path points it at the app's own copy, and an `engines` entry
 * `false` opts it out of a package's engine. One package owns each file, and
 * every source must exist, so a refusal comes before anything is written.
 */
final readonly class ScaffoldPlanner
{
    /**
     * Who a file pointed at the app's own copy belongs to.
     */
    private const string ROOT = 'the root';

    /**
     * @param ScaffoldManifest $scaffoldManifest Checks a root override's path.
     */
    public function __construct(
        private ScaffoldManifest $scaffoldManifest = new ScaffoldManifest(),
    ) {
    }

    /**
     * @param list<ScaffoldPlan> $packages        Each package's declarations, in package order.
     * @param string             $root            The app's root, absolute.
     * @param stdClass           $rootDeclaration The root package's decoded `extra.aic-scaffold`.
     *
     * @throws ScaffoldRefusal When a file is declared twice, an override names no file or engine, or a source is missing.
     *
     * @return ScaffoldPlan What to apply.
     */
    public function plan(array $packages, string $root, stdClass $rootDeclaration): ScaffoldPlan
    {
        $declarationReader = new DeclarationReader($rootDeclaration, "the root's extra.aic-scaffold");
        $declarationReader->only(['file-mapping', 'engines']);

        $overrides = $declarationReader->object('file-mapping')->stringsOrFalse('a path or false');
        $files = $this->overridden($this->files($packages), $root, $overrides);

        foreach ($files as $file) {
            if (!is_file($file->source)) {
                throw new ScaffoldRefusal(
                    $file->destination . ': its source ' . $file->source . ' (from ' . $file->owner . ') is missing.',
                );
            }
        }

        return new ScaffoldPlan(
            array_values($files),
            $this->engines($packages, $declarationReader->object('engines')->stringsOrFalse('false')),
        );
    }

    /**
     * @param list<ScaffoldPlan> $packages Each package's declarations.
     *
     * @throws ScaffoldRefusal When two packages declare one file.
     *
     * @return array<string, ScaffoldFile> Every declared file, by destination.
     */
    private function files(array $packages): array
    {
        $files = [];
        $declared = array_merge(...array_map(static fn (ScaffoldPlan $scaffoldPlan): array => $scaffoldPlan->files, $packages));

        foreach ($declared as $scaffoldFile) {
            $claimed = $files[$scaffoldFile->destination] ?? null;

            if ($claimed !== null) {
                throw new ScaffoldRefusal(
                    $scaffoldFile->destination . ' is declared by both ' . $claimed->owner . ' and ' . $scaffoldFile->owner
                    . '; one package owns a file.',
                );
            }

            $files[$scaffoldFile->destination] = $scaffoldFile;
        }

        return $files;
    }

    /**
     * @param array<string, ScaffoldFile> $files     Every declared file, by destination.
     * @param string                      $root      The app's root.
     * @param array<string, string|false> $overrides The root's `file-mapping`.
     *
     * @throws ScaffoldRefusal When an override names a file no package declares.
     *
     * @return array<string, ScaffoldFile> The files the app keeps, each from the app's copy where it names one.
     */
    private function overridden(array $files, string $root, array $overrides): array
    {
        $unknown = array_diff(array_keys($overrides), array_keys($files));

        if ($unknown !== []) {
            throw new ScaffoldRefusal('the root overrides ' . implode(', ', $unknown) . ', which no package declares.');
        }

        $kept = array_map(
            fn (ScaffoldFile $scaffoldFile): ?ScaffoldFile => $this->kept(
                $scaffoldFile,
                $root,
                $overrides[$scaffoldFile->destination] ?? null,
            ),
            $files,
        );

        return array_filter($kept, static fn (?ScaffoldFile $scaffoldFile): bool => $scaffoldFile !== null);
    }

    /**
     * @param ScaffoldFile      $scaffoldFile A declared file.
     * @param string            $root         The app's root.
     * @param string|false|null $override     The root's entry for it: a path, false, or null for none.
     *
     * @throws ScaffoldRefusal When the path leaves the root.
     *
     * @return ScaffoldFile|null The file the app keeps, from its own copy when it names one; null when it opts out.
     */
    private function kept(ScaffoldFile $scaffoldFile, string $root, string|false|null $override): ?ScaffoldFile
    {
        return match (true) {
            $override === false => null,
            $override === null => $scaffoldFile,
            default => new ScaffoldFile(
                $scaffoldFile->destination,
                $scaffoldFile->mode,
                $this->scaffoldManifest->inside($root, $override),
                self::ROOT,
            ),
        };
    }

    /**
     * @param list<ScaffoldPlan>          $packages Each package's declarations.
     * @param array<string, string|false> $optOuts  The root's `engines`.
     *
     * @throws ScaffoldRefusal When an entry is not `false`, or names a package with no engine.
     *
     * @return list<ScaffoldEngine> The engines to run, in package order.
     */
    private function engines(array $packages, array $optOuts): array
    {
        $engines = array_merge(...array_map(static fn (ScaffoldPlan $scaffoldPlan): array => $scaffoldPlan->engines, $packages));
        $declared = array_map(static fn (ScaffoldEngine $scaffoldEngine): string => $scaffoldEngine->package, $engines);

        foreach ($optOuts as $package => $optOut) {
            if ($optOut !== false || !in_array($package, $declared, true)) {
                throw new ScaffoldRefusal("the root's engines entry " . $package . ' must be false and name a package with an engine.');
            }
        }

        return array_values(array_filter(
            $engines,
            static fn (ScaffoldEngine $scaffoldEngine): bool => !array_key_exists($scaffoldEngine->package, $optOuts),
        ));
    }
}
