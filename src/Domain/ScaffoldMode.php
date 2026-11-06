<?php

declare(strict_types=1);

namespace AIC\Scaffold\Domain;

/**
 * How the scaffold plugin keeps one of an app's root files, as a package's
 * `extra.aic-scaffold` names it: rewritten from the package on every install,
 * written once when missing, or holding the package's lines.
 */
enum ScaffoldMode: string
{
    /** The package owns the file: every install rewrites it when it differs. */
    case Replace = 'replace';

    /** A starter the app then owns: written only when the file is missing. */
    case AddOnce = 'add-once';

    /** Lines the file must hold: each one it lacks is added at its end. */
    case Append = 'append';
}
