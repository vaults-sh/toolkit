# Vaults CLI

The command-line client for [Vaults](https://vaults.sh), the resilient Composer infrastructure. Distributed as a standalone PHAR.

## Install

```bash
curl -fsSL https://vaults.sh/install.sh | sh
```

The installer downloads the latest `vaults` PHAR from the [vaults-sh/toolkit Releases](https://github.com/vaults-sh/toolkit/releases), verifies its checksum, and puts it on your path. To do it by hand, download `vaults` from the release page, `chmod +x vaults`, and move it to `/usr/local/bin/vaults`.

## Staying up to date

```bash
vaults self-update
```

Every command also checks for a newer release at most once a day and prints a one-line notice when one exists. It never blocks, never slows a command by more than two seconds, and can be silenced with `VAULTS_NO_UPDATE_CHECK=1`.

## Commands

| Command | Purpose |
|---|---|
| `vaults login` | Device login: shows a code, opens the browser, waits for approval. Run once per team; `--token=` for CI. |
| `vaults teams` | List the teams stored on this machine and which applies here; `--use=` sets the default. |
| `vaults init` | Link this directory to a Vaults project (pick one or create by name) without depositing anything. |
| `vaults deposit` | Deposits everything in `composer.lock`; offers to wire composer.json; `--write` applies the rewritten lock. |
| `vaults deposit --check` | Read-only deposit report; exit code 1 if anything is undeposited (CI-friendly). |
| `vaults status` | Deposit status of the project in the current directory. |
| `vaults doctor` | Connectivity diagnosis: API, auth, DNS, and edge health. |
| `vaults open` | Open the Vaults dashboard in your browser. |
| `vaults private:link` | Create a revocable key named after this machine (one year by default, `--expires`, `--name`) and wire `composer.json` plus `auth.json`; re-running rotates it. `--global` writes your user's `auth.json`. |
| `vaults private:keys` | List private access keys. |
| `vaults private:keys:create` | Create a CI or client key; `--package`, `--expires`, `--write`. |
| `vaults private:keys:revoke` | Revoke a key. |
| `vaults repositories` | Paid or private Composer repositories your team has given Vaults credentials for. |
| `vaults repositories:add <host>` | Store credentials for a host from `auth.json` or a prompt; `--from-auth`, `--type`, `--username`. |
| `vaults repositories:remove <host>` | Remove a host's credentials. |
| `vaults self-update` | Replace the running PHAR with the latest release. |
| `vaults logout` | Forget the current team, `--team=<uuid or name>`, or `--all`. |

No UUIDs needed: any command that requires a project will walk you through picking or creating one by name, then remembers it, and its team, in a committed `.vaults.json`. CI authenticates with the `VAULTS_TOKEN` environment variable and uses `--project=<uuid>` or the committed manifest.

Both `vaults login` and `vaults open` open your browser for you. Set `VAULTS_NO_BROWSER=1` to stop that on a headless or remote machine; the URL is always printed as well.

## Working with several teams

Credentials are stored once per machine, one entry per team. Run `vaults login` for each team you work with, and each project picks its team from `.vaults.json`, so switching clients is just changing directory. `vaults teams` shows what is stored and which team applies in the current directory; `vaults teams --use=<team>` sets the default for directories without a manifest.

## Private packages

`vaults private:link` creates a private access key named after your machine, valid for a year, visible and revocable under Team settings. It adds the private repository to `composer.json` and the key to `auth.json`, then offers to route public packages through this project's Vaults repository as well, so one command wires everything. That repository serves only the versions Vaults verified for your lockfile, so every public dependency is guaranteed present; if the project has not been deposited yet, the deposit runs there and then. Skip the question with `--with-public` or `--no-public`. Never commit `auth.json`. For CI or a client project, create a dedicated key with `vaults private:keys:create` and put it in `COMPOSER_AUTH` or that project's `auth.json`.

## Paid and private Composer repositories

Before a deposit starts, `vaults deposit` reads `composer.lock` and, for every host you already hold credentials for in `auth.json` or `COMPOSER_AUTH` that your team has not given Vaults yet, asks whether to use them. Say yes and those packages deposit privately in the same run. Anything that still cannot deposit is listed afterwards with its reason; a paid or private Composer repository such as a vendor's Satis or Private Packagist shows as `needs credentials for <host>`. You can also run `vaults repositories:add <host>` at any time, or add them under Team settings → Repositories.

Vaults uses those credentials only to download that host's packages on your team's behalf. The packages are stored as private packages, never on the public mirror, and served only to your team from its private repository, so `vaults private:link` is needed once per project to install them. Storing credentials confirms your team holds the licence for those packages; they cannot be shared with other teams. Vendors that meter installs still receive Composer's install notifications.

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
