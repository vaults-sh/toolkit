PACKAGES := packages/php-client packages/composer-plugin cli

.PHONY: install test build release

install:
	@for dir in $(PACKAGES); do \
		echo "==> composer install ($$dir)"; \
		composer install --no-interaction --prefer-dist --working-dir=$$dir || exit 1; \
	done

test:
	@for dir in $(PACKAGES); do \
		echo "==> pest ($$dir)"; \
		(cd $$dir && vendor/bin/pest) || exit 1; \
	done

build:
	@rm -f cli/vendor/vaults/php-client
	@cd cli && COMPOSER_MIRROR_PATH_REPOS=1 composer update vaults/php-client --no-interaction
	@rm -rf cli/vendor/vaults/php-client/vendor cli/vendor/vaults/php-client/tests cli/vendor/vaults/php-client/.git
	@cd cli && php vaults app:build vaults --no-interaction
	@echo "==> built cli/builds/vaults"

release:
	@test -n "$(VERSION)" || { echo "usage: make release VERSION=0.1.0"; exit 1; }
	@echo "$(VERSION)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$' || { echo "VERSION must be X.Y.Z (no leading v)"; exit 1; }
	@test -z "$$(git status --porcelain)" || { echo "working tree is dirty, commit or stash first"; exit 1; }
	@test "$$(git rev-parse --abbrev-ref HEAD)" = "main" || { echo "release from main only"; exit 1; }
	@git rev-parse "v$(VERSION)" >/dev/null 2>&1 && { echo "tag v$(VERSION) already exists"; exit 1; } || true
	@echo "==> running the full suite before tagging"
	@$(MAKE) test
	@git pull --ff-only origin main
	@git tag -a "v$(VERSION)" -m "Release v$(VERSION)"
	@git push origin main "v$(VERSION)"
	@echo "==> pushed tag v$(VERSION); the Release workflow builds the PHAR and splits the packages"
