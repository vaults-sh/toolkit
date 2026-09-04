# Vaults Toolkit

Client tooling for [Vaults](https://vaults.sh), the resilient Composer infrastructure that mirrors your
dependencies onto independent storage and CDNs so `composer install` keeps working even when Packagist,
GitHub, or an upstream host goes down.

This repository holds the three client-side pieces, developed together and released in lockstep.

## Packages

| Package | What it does |
| --- | --- |
| [`vaults/php-client`](packages/php-client) | Zero-dependency PHP client for the Vaults API. |
| [`vaults/composer-plugin`](packages/composer-plugin) | Adds `composer protect` to any project. |
| `vaults` CLI ([`cli/`](cli)) | Standalone PHAR with `vaults login`, `vaults protect`, `vaults doctor`, and `vaults status`. |

## Install

Protect a project from inside Composer with the plugin:

```bash
composer require --dev vaults/composer-plugin
composer protect
```

Or use the CLI. Download the latest `vaults` PHAR from the [Releases](https://github.com/vaults-sh/toolkit/releases) page:

```bash
chmod +x vaults && mv vaults /usr/local/bin/vaults
vaults protect
```

Both authenticate with a browser device login and walk you through picking or creating a project, so
there are no UUIDs to copy and no dashboard to open. Once a project is protected, `composer install`
runs entirely from the Vaults edge, and neither tool is needed at install time.

## Development

```bash
make install   # install all three packages
make test      # run every suite
make build     # build the CLI PHAR into cli/builds/
```

## License

MIT. See [LICENSE](LICENSE).
