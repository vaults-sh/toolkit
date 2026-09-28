# Vaults Composer Plugin

Adds the `composer vaults:*` commands to any project, so you can deposit your `composer.lock` with [Vaults](https://vaults.sh) resilient Composer infrastructure and manage private packages without leaving Composer.

The plugin is optional and is never required for installs: once a project is deposited, `composer install` works from the Vaults edge with no plugin, no CLI, and no Vaults control plane involved.

## Install

```bash
composer require --dev vaults/composer-plugin
```

## Usage

```bash
composer vaults:deposit            # deposit everything in composer.lock
composer vaults:deposit --check    # read-only report; exit code 1 if anything is undeposited
composer vaults:deposit --write    # also rewrite composer.lock to install from Vaults
```

`composer deposit` is a shorter alias for `composer vaults:deposit`.

Everything is built in: run `composer deposit` in a terminal and it walks you through a browser device login, then lets you pick an existing Vaults project or create one by name, with no UUIDs and no dashboard required. The committed `.vaults.json` remembers the link, and after depositing it offers to add the Vaults repository to composer.json for you. CI uses `VAULTS_TOKEN` plus the committed manifest (or `--project=<uuid>`). The Vaults CLI is optional and shares the same credentials.

## All commands

```bash
composer vaults:login [--token=...]          # device login, once per team; or store a team API token
composer vaults:logout [--team=...|--all]
composer vaults:teams [--use=...]            # teams stored on this machine, and which applies here
composer vaults:init [--project=<uuid>]      # link this directory without depositing
composer vaults:status [--project=<uuid>]
composer vaults:doctor                       # API, auth, DNS and edge health
composer vaults:open                         # open the Vaults dashboard in your browser
```

## Private packages

```bash
composer vaults:private:link [--global] [--expires=365] [--name=...]
                             [--with-public|--no-public]
                                             # create a key for this machine, wire composer.json and auth.json,
                                             # and offer to route public packages through Vaults too
composer vaults:private:keys                 # list keys
composer vaults:private:keys:create "GitHub Actions" [--package=vendor/name]... [--expires=365] [--write]
composer vaults:private:keys:revoke <key-uuid>
```

## Third-party private Composer repositories

```bash
composer vaults:repositories [--all]         # hosts this project has given Vaults credentials for
composer vaults:repositories:add <host> [--from-auth] [--type=http-basic|bearer] [--username=...]
composer vaults:repositories:remove <host>
```

A third-party private package is authorised **per project**. One project's vendor login is never used for another, and a project can only install such a package once its own credentials have been accepted by the vendor.

Before a deposit starts, the plugin reads `composer.lock` and, for every host you already hold credentials for in `auth.json` or `COMPOSER_AUTH` that *this project* has not given Vaults yet, asks whether to use them. Say yes and the project is authorised in the same run. If another project in your team already mirrored that version, Vaults does not download it again: it checks your credentials against the vendor with a single request and links the project to the stored copy. If the vendor refuses, so does Vaults. Anything that still cannot deposit is listed afterwards with its reason.

Installs are scoped the same way. `composer vaults:private:link` creates a key bound to the linked project, and the private edge serves a third-party package only to a key whose project is authorised for it. CI needs a key created for that project: `composer vaults:private:keys:create "CI" --project=<uuid>`. Every command that edits `composer.json` refreshes the lock's content hash, so `composer install` never warns about a stale lock because of Vaults.

## Automatic deposits after `composer update`

Once a project is linked (a committed `.vaults.json`) and you are logged in, the plugin finishes the job after every `composer update` or `composer require`: it deposits the new `composer.lock`, waits for the deposit, and rewrites the lock so it installs from Vaults. There is no second command to run.

```
Vaults: 143 packages in composer.lock · 141 already in Vaults · 2 to deposit
Vaults: ✓ composer.lock now installs from Vaults.
```

It waits at most 30 seconds. Only packages Vaults does not hold yet need depositing, so a normal update finishes well inside that. When a deposit takes longer, such as the first one for a large project, the plugin leaves the lock alone, carries on depositing in the background and tells you to run `composer vaults:deposit --write` when you are ready.

The plugin never asks a question during an update and never fails one. It leaves `composer.lock` untouched and says why when:

- a package did not deposit, or a private repository needs credentials;
- private packages were deposited but this project is not yet set up to install them from Vaults;
- the Vaults repository is not in `composer.json` yet.

Run `composer vaults:deposit --write` once in those cases. It asks what it needs to and wires the project, after which updates pin themselves.

This happens in non-interactive runs too, so a bot that opens dependency update pull requests produces a lock that already installs from Vaults. Nothing is sent when there is no token or no manifest, so unlinked projects and CI without `VAULTS_TOKEN` are unaffected.

Settings in `composer.json`:

```json
"extra": {
    "vaults": {
        "auto-deposit": true,
        "auto-pin": true,
        "auto-pin-wait": 30
    }
}
```

| Setting | Default | Effect |
| --- | --- | --- |
| `auto-deposit` | `true` | `false` switches the plugin's hook off entirely |
| `auto-pin` | `true` | `false` deposits in the background and never touches the lock |
| `auto-pin-wait` | `30` | Seconds to wait for the deposit, from 0 to 120. `0` behaves like `auto-pin: false` |

Set `VAULTS_AUTO_PIN=0` in the environment to skip pinning for a single run.

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
- Vaults backfills tracked packages toward full coverage automatically, and the Composer plugin deposits whatever you update to and pins `composer.lock` itself, so you converge on Vaults with no manual step. Without the plugin, run `vaults deposit --write` after an update.
