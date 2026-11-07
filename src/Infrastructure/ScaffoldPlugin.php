<?php

declare(strict_types=1);

namespace AIC\Scaffold\Infrastructure;

use AIC\Scaffold\Application\ScaffoldManifest;
use AIC\Scaffold\Application\ScaffoldPlanner;
use AIC\Scaffold\Domain\ScaffoldPlan;
use AIC\Scaffold\Exception\ScaffoldRefusal;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use Override;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function dirname;
use function json_decode;
use function json_encode;
use function realpath;
use function str_starts_with;
use function strcmp;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * The Composer plugin (`extra.class` of ignibyte/aic-scaffold): after every
 * `composer install` and `composer update` it writes the app's root files that
 * the installed `ignibyte/aic-*` packages declare in `extra.aic-scaffold`, with
 * the root package's own `extra.aic-scaffold` as overrides, then runs the
 * packages' engines. It runs at Composer time only; no runtime code reaches it.
 *
 * @api Composer instantiates it from the package's `extra.class`.
 */
final readonly class ScaffoldPlugin implements EventSubscriberInterface, PluginInterface
{
    /**
     * The packages whose declarations count.
     */
    private const string VENDOR = 'ignibyte/aic-';

    /**
     * The script-event priority: above the default 0, so the scaffold runs first.
     */
    private const int PRIORITY = 10;

    /**
     * Says, when Composer runs verbose, which root the plugin keeps; the work happens on the script events.
     *
     * @param Composer    $composer The running Composer.
     * @param IOInterface $io       Its output.
     */
    #[Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write('aic-scaffold: keeps the root files of ' . $composer->getPackage()->getName(), verbosity: IOInterface::VERBOSE);
    }

    /**
     * Says, when Composer runs verbose, that the plugin stops; it holds nothing to release.
     *
     * @param Composer    $composer The running Composer.
     * @param IOInterface $io       Its output.
     */
    #[Override]
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $io->write('aic-scaffold: stops for ' . $composer->getPackage()->getName(), verbosity: IOInterface::VERBOSE);
    }

    /**
     * Says that the files the plugin wrote stay, with nothing to keep them current.
     *
     * @param Composer    $composer The running Composer.
     * @param IOInterface $io       Its output.
     */
    #[Override]
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $io->write(
            'aic-scaffold: removed from ' . $composer->getPackage()->getName()
            . '; the root files it wrote stay, and no install rewrites them now.',
        );
    }

    /**
     * @param Event $event The finished install or update.
     *
     * @throws ScaffoldRefusal When a declaration cannot be applied; nothing is written then.
     */
    public function scaffold(Event $event): void
    {
        $composer = $event->getComposer();
        $composerFile = $composer->getConfig()->getConfigSource()->getName();
        $root = realpath(dirname($composerFile));

        if ($root === false) {
            throw new ScaffoldRefusal('cannot find the root of ' . $composerFile . '.');
        }

        $scaffoldPlan = (new ScaffoldPlanner())->plan(
            $this->packages($composer),
            $root,
            $this->declaration($composer->getPackage(), 'the root'),
        );

        foreach ((new ScaffoldFiles())->apply($scaffoldPlan, $root) as $line) {
            $event->getIO()->write('aic-scaffold: ' . $line);
        }

        foreach ((new ScaffoldEngines(new ProcessExecutor($event->getIO())))->run($scaffoldPlan, $root) as $line) {
            $event->getIO()->write($line);
        }
    }

    /**
     * @param Composer $composer The running Composer.
     *
     * @throws ScaffoldRefusal When a package's declaration cannot be read.
     *
     * @return list<ScaffoldPlan> Each installed `ignibyte/aic-*` package's declarations, by package name.
     */
    private function packages(Composer $composer): array
    {
        $packages = array_values(array_filter(
            $composer->getRepositoryManager()->getLocalRepository()->getPackages(),
            static fn (PackageInterface $package): bool => str_starts_with($package->getName(), self::VENDOR)
                && array_key_exists('aic-scaffold', $package->getExtra()),
        ));
        usort($packages, static fn (PackageInterface $one, PackageInterface $other): int => strcmp($one->getName(), $other->getName()));

        return array_map(
            fn (PackageInterface $package): ScaffoldPlan => (new ScaffoldManifest())->read(
                $package->getName(),
                (string) $composer->getInstallationManager()->getInstallPath($package),
                $this->declaration($package, $package->getName()),
            ),
            $packages,
        );
    }

    /**
     * @param PackageInterface $package A package, the root included.
     * @param string           $what    Whose it is, for a refusal.
     *
     * @throws ScaffoldRefusal When its `aic-scaffold` is not an object.
     *
     * @return stdClass Its `aic-scaffold` as an object; an empty one when it has none.
     */
    private function declaration(PackageInterface $package, string $what): stdClass
    {
        $encoded = json_encode($package->getExtra()['aic-scaffold'] ?? new stdClass(), JSON_THROW_ON_ERROR);
        $declaration = json_decode($encoded, flags: JSON_THROW_ON_ERROR);

        // Composer decodes `{}` as an empty array, which encodes back as `[]`.
        if ($declaration === []) {
            return new stdClass();
        }

        if (!$declaration instanceof stdClass) {
            throw new ScaffoldRefusal($what . "'s extra.aic-scaffold must be an object.");
        }

        return $declaration;
    }

    /**
     * Runs ahead of the other plugins' handlers for the same events (priority
     * self::PRIORITY), so the files it writes are there when they run:
     * captainhook/hook-installer reads the `captainhook.json` that `aic-gate init`
     * writes, and installs no hooks while it is missing.
     *
     * @return array<string, array{string, int}> Each script event, the method that handles it and its priority.
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['scaffold', self::PRIORITY],
            ScriptEvents::POST_UPDATE_CMD => ['scaffold', self::PRIORITY],
        ];
    }
}
