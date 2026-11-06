<?php

declare(strict_types=1);

namespace AIC\Scaffold\Application;

use AIC\Scaffold\Domain\ScaffoldEngine;
use AIC\Scaffold\Domain\ScaffoldFile;
use AIC\Scaffold\Domain\ScaffoldMode;
use AIC\Scaffold\Domain\ScaffoldPlan;
use AIC\Scaffold\Exception\ScaffoldRefusal;
use stdClass;

use function array_slice;
use function explode;
use function in_array;
use function str_starts_with;

/**
 * Reads one package's `extra.aic-scaffold`: its `file-mapping`, each
 * destination with a mode and a path inside the package, and its optional
 * `engine`, a PHP script inside the package and its arguments.
 */
final readonly class ScaffoldManifest
{
    /**
     * @param string   $package     The package's name.
     * @param string   $installPath Where it is installed, absolute.
     * @param stdClass $declaration Its decoded `extra.aic-scaffold`.
     *
     * @throws ScaffoldRefusal When the declaration holds an unknown key, mode or shape, or a path that leaves its root.
     *
     * @return ScaffoldPlan Its files and its engine.
     */
    public function read(string $package, string $installPath, stdClass $declaration): ScaffoldPlan
    {
        $declarationReader = new DeclarationReader($declaration, $package . "'s extra.aic-scaffold");
        $declarationReader->only(['file-mapping', 'engine']);

        $files = [];

        foreach ($declarationReader->object('file-mapping')->members() as $destination => $entry) {
            $files[] = $this->file($package, $installPath, $destination, $entry);
        }

        $engine = $declarationReader->strings('engine');

        return new ScaffoldPlan(
            $files,
            $engine === [] ? [] : [new ScaffoldEngine($package, $this->inside($installPath, $engine[0]), array_slice($engine, 1))],
        );
    }

    /**
     * @param string $root A root directory.
     * @param string $path A path under it, relative.
     *
     * @throws ScaffoldRefusal When the path is absolute or climbs out of the root.
     *
     * @return string The absolute path.
     */
    public function inside(string $root, string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new ScaffoldRefusal('"' . $path . '" must be a relative path that stays under its root.');
        }

        return $root . '/' . $path;
    }

    /**
     * @param string            $package           The package's name.
     * @param string            $installPath       Where it is installed.
     * @param string            $destination       The file's path under the app's root.
     * @param DeclarationReader $declarationReader Its `{mode, path}`.
     *
     * @throws ScaffoldRefusal When the entry is not one.
     *
     * @return ScaffoldFile The file.
     */
    private function file(
        string $package,
        string $installPath,
        string $destination,
        DeclarationReader $declarationReader,
    ): ScaffoldFile {
        $declarationReader->only(['mode', 'path']);
        $word = $declarationReader->string('mode');
        $mode = match ($word) {
            'replace' => ScaffoldMode::Replace,
            'add-once' => ScaffoldMode::AddOnce,
            'append' => ScaffoldMode::Append,
            default => throw new ScaffoldRefusal(
                $package . ' declares ' . $destination . ' with the mode "' . $word . '"; a mode is one of replace, add-once, append.',
            ),
        };

        $this->inside('', $destination);

        return new ScaffoldFile($destination, $mode, $this->inside($installPath, $declarationReader->string('path')), $package);
    }
}
