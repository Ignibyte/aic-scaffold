# ignibyte/aic-scaffold

A Composer plugin that writes an AIC app's root files and keeps them current. After every
`composer install` and `composer update` it reads `extra.aic-scaffold` from each installed
`ignibyte/aic-*` package and applies it, then prints one line per file:

```text
aic-scaffold: wrote bin/aic (replace, ignibyte/aic-framework)
aic-scaffold: kept config/app.php (add-once, ignibyte/aic-framework)
aic-scaffold: appended 2 lines to .gitignore (append, ignibyte/aic-framework)
```

```sh
composer require ignibyte/aic-scaffold
composer config allow-plugins.ignibyte/aic-scaffold true
```

## Modes

- **replace**: the package owns the file. Every install rewrites it when it differs, with the
  source's permissions. `public/index.php`, `bootstrap/serve.php` and `bin/aic` are the
  framework's; an app never edits them.
- **add-once**: a starter the app then owns, written only when the file is missing. The
  framework's `config/*.php` files are these.
- **append**: lines the file must hold. Each source line the file lacks is added at its end,
  and none is added twice. The framework's `.gitignore` lines are these.

## What a package declares

```json
"extra": {
    "aic-scaffold": {
        "file-mapping": {
            "bin/aic": {"mode": "replace", "path": "scaffold/bin/aic"},
            "config/app.php": {"mode": "add-once", "path": "scaffold/config/app.php"},
            ".gitignore": {"mode": "append", "path": "scaffold/gitignore"}
        },
        "engine": ["bin/aic-gate", "init"]
    }
}
```

Each `path` is inside the package. `engine` is a PHP script inside the package and its
arguments. It runs at the app's root once the files are written, and a failure stops the install.
`ignibyte/aic-gates` declares `aic-gate init`, which writes the gate suite's stubs.

## What the app overrides

The app's own composer.json opts a file out with `false`, points a file at its own copy with a
path under the app's root, and opts out of a package's engine with `false`:

```json
"extra": {
    "aic-scaffold": {
        "file-mapping": {
            "config/app.php": false,
            "public/index.php": "assets/index.php"
        },
        "engines": {
            "ignibyte/aic-gates": false
        }
    }
}
```

The plugin stops the install before writing anything in each of these cases:
- two packages declare one file;
- an override names a file or an engine no package declares;
- a mode is unknown;
- a path leaves its root;
- a source file is missing.

This repository is a read-only split of `scaffold/` in
[Ignibyte/aic-nextgen](https://github.com/Ignibyte/aic-nextgen), made on each release tag.
Issues and pull requests go there. MIT licensed.
