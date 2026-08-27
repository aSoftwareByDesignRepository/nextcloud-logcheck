# Makefile for LogCheck (logcheck) app release

app_name = logcheck
build_dir = build
release_dir = $(build_dir)/release
version = $(shell grep '^\s*<version>' appinfo/info.xml | sed 's/.*<version>\([0-9.]*\)<\/version>.*/\1/' | head -1)
archive_name = $(app_name)-$(version).tar.gz
archive_path = $(release_dir)/$(archive_name)
occ = ../../occ
SIGN_KEY := $(if $(strip $(APP_CERT_KEY_PATH)),$(APP_CERT_KEY_PATH),$(HOME)/.nextcloud/certificates/$(app_name).key)
SIGN_CRT := $(if $(strip $(APP_CERT_CRT_PATH)),$(APP_CERT_CRT_PATH),$(HOME)/.nextcloud/certificates/$(app_name).crt)
ready2publish_sign = ../../ready2publish/scripts/sign-nextcloud-appstore-archive.sh

.PHONY: release verify-release verify-signature-manifest sign-release release-signed sign-tarball clean

release:
	@echo "Building $(app_name) v$(version)..."
	@mkdir -p $(release_dir)
	@staging=$$(mktemp -d) && \
		mkdir -p "$$staging/$(app_name)" && \
		rsync -a --exclude='.git' --exclude='$(build_dir)' --exclude='.github' \
			--exclude='node_modules' --exclude='tests' --exclude='e2e' \
			--exclude='.phpunit.result.cache' --exclude='test-results' \
			--exclude='scripts' --exclude='vendor' --exclude='infection.json5' \
			--exclude='playwright.config.js' --exclude='package.json' --exclude='package-lock.json' \
			--exclude='phpunit.xml' --exclude='composer.lock' --exclude='.php-cs-fixer*' \
			--exclude='appinfo/signature.json' \
			./ "$$staging/$(app_name)/" && \
		tar -czf $(archive_path) -C "$$staging" $(app_name) && \
		rm -rf "$$staging"
	@echo "Created $(archive_path)"

verify-release:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@if tar -tzf $(archive_path) | grep -Eq '/(\.git/|node_modules/|build/|tests/|e2e/|test-results/|scripts/|vendor/)'; then \
		echo "Error: release archive contains forbidden development paths"; \
		tar -tzf $(archive_path) | grep -E '/(\.git/|node_modules/|build/|tests/|e2e/|test-results/|scripts/|vendor/)' || true; \
		exit 1; \
	fi
	@echo "Release archive layout looks clean."

verify-signature-manifest:
	@test -f $(archive_path) || (echo "Error: Run 'make release-signed' first"; exit 1)
	@tmpdir=$$(mktemp -d) && \
		trap 'rm -rf "$$tmpdir"' EXIT && \
		tar -xzf $(archive_path) -C "$$tmpdir" "$(app_name)/appinfo/signature.json" && \
		sig="$$tmpdir/$(app_name)/appinfo/signature.json" && \
		if ! test -f "$$sig"; then \
			echo "Error: signature.json missing from signed archive"; \
			exit 1; \
		fi && \
		if grep -Eq '"([^"]*/)?(\.git|node_modules|build|tests|e2e|test-results|scripts|vendor)\\/' "$$sig"; then \
			echo "Error: signature.json references forbidden development paths"; \
			grep -E '"([^"]*/)?(\.git|node_modules|build|tests|e2e|test-results|scripts|vendor)\\/' "$$sig" || true; \
			exit 1; \
		fi
	@echo "Signature manifest sanity check passed."

clean:
	rm -rf $(build_dir)

sign-tarball:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@test -f $(ready2publish_sign) || (echo "Error: Missing $(ready2publish_sign)"; exit 1)
	@APPSTORE_SIGNING_KEY="$(SIGN_KEY)" APPSTORE_SIGNING_CERT="$(SIGN_CRT)" bash "$(ready2publish_sign)" $(app_name) $(archive_path)

sign-release: verify-release
	@test -f "$(SIGN_KEY)" || (echo "Error: Missing signing key: $(SIGN_KEY) — request a certificate for $(app_name) (see https://github.com/nextcloud/app-certificate-requests)"; exit 1)
	@test -f "$(SIGN_CRT)" || (echo "Error: Missing certificate: $(SIGN_CRT)"; exit 1)
	@test -f $(occ) || (echo "Error: occ not found at $(occ). Override with 'make sign-release occ=/path/to/occ'"; exit 1)
	@staging=$$(mktemp -d) && \
		trap 'rm -rf "$$staging"' EXIT && \
		tar -xzf $(archive_path) -C "$$staging" && \
		php $(occ) integrity:sign-app \
			--privateKey="$(SIGN_KEY)" \
			--certificate="$(SIGN_CRT)" \
			--path="$$staging/$(app_name)" && \
		tar -czf $(archive_path) -C "$$staging" $(app_name)
	@echo "Signed archive updated at $(archive_path)"

release-signed: release sign-release verify-signature-manifest
	@echo "Release build + Nextcloud signature complete."
