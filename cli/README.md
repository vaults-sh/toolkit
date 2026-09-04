# Vaults CLI

The command-line client for [Vaults](https://vaults.sh), the resilient Composer infrastructure. Distributed as a standalone PHAR.

## Install

Download the latest `vaults` PHAR from the [vaults-sh/toolkit Releases](https://github.com/vaults-sh/toolkit/releases) page, then:

```bash
chmod +x vaults
mv vaults /usr/local/bin/vaults
```

## Commands

| Command | Purpose |
|---|---|
| `vaults login` | Device login: shows a code, opens the browser, waits for approval. `--token=` for CI. |
| `vaults init` | Link this directory to a Vaults project (pick one or create by name) without depositing anything. |
| `vaults deposit` | Deposits everything in `composer.lock`; offers to wire composer.json; `--write` applies the rewritten lock. |
| `vaults deposit --check` | Read-only deposit report; exit code 1 if anything is undeposited (CI-friendly). |
| `vaults status` | Deposit status of the project in the current directory. |
| `vaults doctor` | Connectivity diagnosis: API, auth, DNS, and edge health. |
| `vaults logout` | Remove stored credentials. |

No UUIDs needed: any command that requires a project will walk you through picking or creating one by name, then remembers it in a committed `.vaults.json`. CI authenticates with the `VAULTS_TOKEN` environment variable and uses `--project=<uuid>` or the committed manifest.

## Development

```bash
composer install
vendor/bin/pest
php vaults <command>
composer build   # mirrors the path dependency, then builds
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
