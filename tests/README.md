# Tests

```bash
composer test                      # the whole PHPUnit suite
vendor/bin/phpunit -c phpunit.xml tests/Lifecycle   # install + update only
npx playwright test --workers=1    # browser tests, against http://localhost:8000
```

## Install and update (`tests/Lifecycle`)

Both run in an isolated copy of the tree under the system temp directory (`tests/Support/AppSandbox.php`), served by PHP's built-in server. They never read or write the working copy's `.env`, database or uploads.

- **`FreshInstallTest`** walks the real web installer (`public/installer.php`) step by step, then checks the database, the `.env`, the public pages, that the installer locks itself and that the new admin can sign in.
- **`UpdateTest`** installs a site, turns it into an older release (older `version.json`, schema without the newest column, a modified template, an album, an original file, an edited translation), publishes a package built from the checkout on a local stand-in for the GitHub API, and runs the real updater: lookup, download, checksum, backup, file replacement, migrations, cache refresh. It then checks that code and schema are current, that content, `.env` and admin edits survived, and that the site no longer serves the templates compiled by the old release.

## Tests that need something external

These are part of the suite and skip themselves unless their environment variable is set.

| Test | Run it with | What it does |
|------|-------------|--------------|
| `tests/Smoke/LiveSiteSmokeTest.php` | `CIMAISE_SMOKE_URL=https://example.org` | Read-only checks of a deployed site, meant for right after an update: pages render without PHP errors, the admin is gated, the home revalidates with its ETag, album pages answer, images are real image streams. |
| `tests/Docker/ImageUpgradeTest.php` | `CIMAISE_DOCKER_IMAGE=fabiodalez/cimaise:<tag>` (optional `CIMAISE_DOCKER_BASELINE`, default `fabiodalez/cimaise:1.4.25`) | Creates an install with the baseline image, starts the image under test on the same volumes, uploads a photo over HTTP and waits for the image worker to generate every variant. Needs a Docker engine. |

After a release:

```bash
CIMAISE_SMOKE_URL=https://your-site.example vendor/bin/phpunit -c phpunit.xml tests/Smoke
CIMAISE_DOCKER_IMAGE=fabiodalez/cimaise:X.Y.Z vendor/bin/phpunit -c phpunit.xml tests/Docker/ImageUpgradeTest.php
```
