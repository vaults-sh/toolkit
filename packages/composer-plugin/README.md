# Vaults Composer Plugin

Adds `composer protect` to any project, protecting your `composer.lock` with [Vaults](https://vaults.sh) resilient Composer infrastructure without leaving Composer.

The plugin is optional and is never required for installs: once a project is protected, `composer install` works from the Vaults edge with no plugin, no CLI, and no Vaults control plane involved.

## Install

```bash
composer require --dev vaults/composer-plugin
```

## Usage

```bash
composer protect            # protect everything in composer.lock
composer protect --check    # read-only report; exit code 1 if anything is unprotected
composer protect --write    # also rewrite composer.lock to install from Vaults
```

Everything is built in: run `composer protect` in a terminal and it walks you through a browser device login, then lets you pick an existing Vaults project or create one by name, with no UUIDs and no dashboard required. The committed `.vaults.json` remembers the link, and after protecting it offers to add the Vaults repository to composer.json for you. CI uses `VAULTS_TOKEN` plus the committed manifest (or `--project=<uuid>`). The Vaults CLI is optional and shares the same credentials.

## Development

```bash
composer install
composer test
```

## Going fully Packagist-free

Installs from a Vaults-rewritten `composer.lock` need nothing from Packagist or GitHub: every artifact comes from the Vaults edge, and the only remaining contact is Composer's own best-effort repository index load, which is non-fatal when Packagist is down. To eliminate even that, disable Packagist in `composer.json`:

```json
"repositories": [
    {"packagist.org": false}
]
```

Caveat: with Packagist disabled, `composer update` and `composer require` cannot discover new versions. Most projects should leave it enabled, since installs stay resilient either way.

## How resolution works

- **`composer install`** always installs from Vaults: the rewritten `composer.lock` points every dist at `dist.vaults-edge.net`, with each package's `source` (GitHub) kept as an automatic fallback if a Vaults download ever fails.
- **`composer update` / `require`** resolve versions against Packagist and prefer Vaults for any version Vaults already holds (the Vaults repository is `canonical: false`, so it's consulted first but never hides newer upstream releases).
- Vaults backfills tracked packages toward full coverage automatically, and this plugin protects whatever you update to in the background, so you converge on Vaults with no manual step. Run `composer protect --write` after an update to pin the lock immediately.
