# Vaults Toolkit

Client tooling for [Vaults](https://vaults.sh), the resilient Composer infrastructure that mirrors your
dependencies onto independent storage and CDNs so `composer install` keeps working even when Packagist,
GitHub, or an upstream host goes down.

This repository holds the three client-side pieces, developed together and released in lockstep.

## Packages

| Package | What it does |
| --- | --- |
| [`vaults/php-client`](packages/php-client) | Zero-dependency PHP client for the Vaults API. |
| [`vaults/composer-plugin`](packages/composer-plugin) | Adds `composer deposit` and the full `composer vaults:*` command set to any project. |
| `vaults` CLI ([`cli/`](cli)) | Standalone PHAR with the same commands as the plugin, for machines that do not have a Composer project. |

## Install

Deposit a project from inside Composer with the plugin:

```bash
composer require --dev vaults/composer-plugin
composer deposit
```

Or use the CLI, which installs itself and keeps itself current with `vaults self-update`:

```bash
curl -fsSL https://vaults.sh/install.sh | sh
vaults deposit
```

Both authenticate with a browser device login and walk you through picking or creating a project, so
there are no UUIDs to copy and no dashboard to open.

Credentials live once per machine in `~/.config/vaults/config.json`, like SSH keys. Log in once per
team you work with; each project's committed `.vaults.json` records its team, so a contractor moving
between clients never switches accounts by hand. `VAULTS_TOKEN` overrides everything for CI. Once a project is deposited, `composer install`
runs entirely from the Vaults edge, and neither tool is needed at install time.

The plugin and the CLI expose the same commands, so you never need both:

| Plugin | CLI | Purpose |
| --- | --- | --- |
| `composer deposit` / `composer vaults:deposit` | `vaults deposit` | Deposit `composer.lock`; `--check`, `--write`, `--project=` |
| `composer vaults:login` | `vaults login` | Browser device login, or `--token=` |
| `composer vaults:logout` | `vaults logout` | Forget the current team, `--team=`, or `--all` |
| `composer vaults:teams` | `vaults teams` | List stored teams; `--use=` picks the default |
| `composer vaults:init` | `vaults init` | Link the directory to a project without depositing |
| `composer vaults:status` | `vaults status` | Deposit status of the linked project |
| `composer vaults:doctor` | `vaults doctor` | API, auth, DNS and edge health checks |
| `composer vaults:connect` | `vaults connect` | Open the dashboard to connect a git provider |
| `composer vaults:private:link` | `vaults private:link` | Create a key for this machine, wire private installs, and offer the public mirror too; `--global`, `--expires`, `--name`, `--with-public`, `--no-public` |
| `composer vaults:private:keys` | `vaults private:keys` | List private access keys |
| `composer vaults:private:keys:create` | `vaults private:keys:create` | Create a CI or client key; `--package`, `--expires`, `--write` |
| `composer vaults:private:keys:revoke` | `vaults private:keys:revoke` | Revoke a key |
| `composer update vaults/composer-plugin` | `vaults self-update` | Upgrade the tool itself |

## Development

```bash
make install   # install all three packages
make test      # run every suite
make build     # build the CLI PHAR into cli/builds/
```

## License

MIT. See [LICENSE](LICENSE).
