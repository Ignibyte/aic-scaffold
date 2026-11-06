<?php

declare(strict_types=1);

namespace AIC\Scaffold\Infrastructure;

use AIC\Scaffold\Domain\ScaffoldFile;
use AIC\Scaffold\Domain\ScaffoldMode;
use AIC\Scaffold\Domain\ScaffoldPlan;
use AIC\Scaffold\Exception\ScaffoldRefusal;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function str_ends_with;
use function trim;

/**
 * Writes each root file of a plan by its mode and says what it did, one line a
 * file: a replace-mode file is rewritten when it differs from its source, with
 * the source's permissions; an add-once file is written only when missing; an
 * append-mode file gains each source line it lacks, and nothing twice.
 */
final readonly class ScaffoldFiles
{
    /**
     * @param ScaffoldPlan $scaffoldPlan The plan.
     * @param string       $root         The app's root, absolute.
     *
     * @throws ScaffoldRefusal When a file cannot be read or written.
     *
     * @return list<string> One line per file: what was done, the file, its mode and its owner.
     */
    public function apply(ScaffoldPlan $scaffoldPlan, string $root): array
    {
        return array_map(
            fn (ScaffoldFile $scaffoldFile): string => $this->applied($scaffoldFile, $root . '/' . $scaffoldFile->destination)
                . ' ' . $scaffoldFile->destination . ' (' . $scaffoldFile->mode->value . ', ' . $scaffoldFile->owner . ')',
            $scaffoldPlan->files,
        );
    }

    /**
     * @param ScaffoldFile $scaffoldFile The file.
     * @param string       $target       Where it goes.
     *
     * @throws ScaffoldRefusal When it cannot be read or written.
     *
     * @return string What was done.
     */
    private function applied(ScaffoldFile $scaffoldFile, string $target): string
    {
        return match ($scaffoldFile->mode) {
            ScaffoldMode::Replace => is_file($target) && $this->read($target) === $this->read($scaffoldFile->source)
                ? 'current'
                : $this->copy($scaffoldFile->source, $target),
            ScaffoldMode::AddOnce => is_file($target) ? 'kept' : $this->copy($scaffoldFile->source, $target),
            ScaffoldMode::Append => $this->append($scaffoldFile->source, $target),
        };
    }

    /**
     * @param string $source The file it is made from.
     * @param string $target Where it goes.
     *
     * @throws ScaffoldRefusal When it cannot be written.
     *
     * @return string What was done.
     */
    private function copy(string $source, string $target): string
    {
        $this->write($target, $this->read($source));

        $permissions = fileperms($source);

        if ($permissions === false || !chmod($target, $permissions & 0o777)) {
            throw new ScaffoldRefusal('cannot set the permissions of ' . $target . '.');
        }

        return 'wrote';
    }

    /**
     * @param string $source The file whose lines the target must hold.
     * @param string $target The file.
     *
     * @throws ScaffoldRefusal When either cannot be read, or the target cannot be written.
     *
     * @return string What was done.
     */
    private function append(string $source, string $target): string
    {
        $held = is_file($target)
            ? $this->read($target)
            : '';
        $missing = $this->missing($this->read($source), $held);

        if ($missing === []) {
            return 'current';
        }

        $this->write($target, $held . ($held === '' || str_ends_with($held, "\n") ? '' : "\n") . implode("\n", $missing) . "\n");

        return 'appended ' . count($missing) . (count($missing) === 1 ? ' line to' : ' lines to');
    }

    /**
     * @param string $lines The lines a file must hold.
     * @param string $held  What it holds.
     *
     * @return list<string> Each line, once, it lacks; blank lines never count.
     */
    private function missing(string $lines, string $held): array
    {
        $present = explode("\n", $held);

        return array_values(array_unique(array_filter(
            explode("\n", $lines),
            static fn (string $line): bool => trim($line) !== '' && !in_array($line, $present, true),
        )));
    }

    /**
     * @param string $path A file.
     *
     * @throws ScaffoldRefusal When it cannot be read.
     *
     * @return string Its content.
     */
    private function read(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new ScaffoldRefusal('cannot read ' . $path . '.');
        }

        return $content;
    }

    /**
     * @param string $path    A file.
     * @param string $content What it holds.
     *
     * @throws ScaffoldRefusal When it, or its folder, cannot be written.
     */
    private function write(string $path, string $content): void
    {
        $folder = dirname($path);

        if (!is_dir($folder) && !mkdir($folder, 0o755, true) && !is_dir($folder)) {
            throw new ScaffoldRefusal('cannot make the folder ' . $folder . '.');
        }

        if (file_put_contents($path, $content) === false) {
            throw new ScaffoldRefusal('cannot write ' . $path . '.');
        }
    }
}
