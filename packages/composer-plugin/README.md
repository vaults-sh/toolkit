# Vaults Composer Plugin

Adds `composer deposit` and the `composer vaults:*` commands to any project, so you can deposit your `composer.lock` with [Vaults](https://vaults.sh) resilient Composer infrastructure and manage private packages without leaving Composer.

The plugin is optional and is never required for installs: once a project is deposited, `composer install` works from the Vaults edge with no plugin, no CLI, and no Vaults control plane involved.

## Install

```bash
composer require --dev vaults/composer-plugin
```

## Usage

```bash
composer deposit            # deposit everything in composer.lock
composer deposit --check    # read-only report; exit code 1 if anything is undeposited
composer deposit --write    # also rewrite composer.lock to install from Vaults
```

Everything is built in: run `composer deposit` in a terminal and it walks you through a browser device login, then lets you pick an existing Vaults project or create one by name, with no UUIDs and no dashboard required. The committed `.vaults.json` remembers the link, and after depositing it offers to add the Vaults repository to composer.json for you. CI uses `VAULTS_TOKEN` plus the committed manifest (or `--project=<uuid>`). The Vaults CLI is optional and shares the same credentials.

## All commands

```bash
composer vaults:login [--token=...]          # device login, or store a team API token
composer vaults:logout
composer vaults:init [--project=<uuid>]      # link this directory without depositing
composer vaults:status [--project=<uuid>]
composer vaults:doctor                       # API, auth, DNS and edge health
composer vaults:connect                      # open the dashboard to connect GitHub/GitLab/Bitbucket
```

## Private packages

```bash
composer vaults:private:link [--global]      # add the private repository and write a bearer token to auth.json
composer vaults:private:keys                 # list keys
composer vaults:private:keys:create "GitHub Actions" [--package=vendor/name]... [--expires=365] [--write]
composer vaults:private:keys:revoke <key-uuid>
```

`private:link` issues a short-lived token for your own machine. For CI or a client project, create a named key and put it in `COMPOSER_AUTH` or the consuming project's `auth.json`. Never commit `auth.json`.

## Automatic deposits after `composer update`

Once a project is linked (a committed `.vaults.json`) and you are logged in, the plugin deposits the new `composer.lock` after every `composer update` so the mirror never lags behind your lockfile. It runs after Composer has finished, never blocks or fails an update, and prints one line when it has queued a deposit. Nothing is sent when there is no token or no manifest, so unlinked projects and CI without `VAULTS_TOKEN` are unaffected.

Opt out per project in `composer.json`:

```json
"extra": {
    "vaults": {"auto-deposit": false}
}
```

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
- Vaults backfills tracked packages toward full coverage automatically, and this plugin deposits whatever you update to in the background, so you converge on Vaults with no manual step. Run `composer deposit --write` after an update to pin the lock immediately.
