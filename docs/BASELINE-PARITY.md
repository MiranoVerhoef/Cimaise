# Fork baseline verification

Comparison: published `1.4.23-test.12` → `1.4.24-test.2`.

The new baseline includes upstream v1.4.24 and the author's Docker migration/cache-revalidation branch from PR #139. Its original commits and contributor history are retained. Upstream contribution branches remain separate from this combined fork build.

## Feature coverage

| Existing fork behavior | Verification |
|---|---|
| Automatic JPEG/WebP/AVIF variants and correct async setting | Image-job integration; Apache, PHP-FPM and restricted PHP-FPM HTTP uploads |
| SQLite retries, generation locks, interrupted/failed jobs, CLI exit failures | Real competing SQLite writer; restart/retry and shell exit checks |
| Admin image-job progress and upload preparation/generation phases | Chromium upload and retry checks |
| Enhanced-image Reload/Dismiss pill | Chromium delayed-variant fixture, dismissal and reload |
| Original upload bytes and original downloads | Browser upload/download hashes; retained-volume file hashes |
| Authenticated variant previews and permission-denied download handling | Protected-media integration; incognito browser download checks |
| Original section and size in image details | Chromium gallery checks |
| Keyboard navigation and media filters | Chromium gallery, SPA re-entry and combined filters |
| Per-album original/generated storage breakdown | Storage unit tests and Chromium Albums table |
| Equipment visibility in Classic/Hero/Magazine without deleting metadata | Upstream template gates; save checks; retained preference/metadata migration |
| Classic hero enable/title/text controls | Browser settings and anonymous-page checks |
| Per-album limit in Classic infinite scrolling | Real query/pagination integration |
| None category fallback | Browser album creation |
| Custom font upload/select/serve | Browser font-byte and generated-CSS checks |
| Automatic shared-settings/page cache invalidation and content validators | File/database cache integration; upstream concurrent-validator tests; browser normal refresh |
| Maintenance logo and optional login link; clear cached public HTML | Browser 503/logo/login controls and warmed service-worker cache checks |
| Dark/light logo and readable dark preview | Browser theme, fallback and favicon-preservation checks |
| Custom About navigation label, permalink and form redirects | Browser repeated rename, form action and CSRF-aware validation checks |
| Category dropdown remains visible on pointer re-entry | Chromium pointer timing regression |
| Apache Brotli/gzip and correct diagnostics | Actual content-encoding negotiation/decoding checks |
| Mobile/tablet analytics detection | Android/iOS and desktop user-agent unit tests |
| Analytics Pro single sidebar registration and admin-language labels | Chromium initial-load/reload and English/Italian rendering checks |
| Existing sidebar translations and diagnostic directory fixes | Upstream unit tests and release-cache refresh checks |

## Retained-volume upgrade

CI seeds isolated volumes using the **published test.12 Docker image**, then boots the candidate through its real entrypoint. It verifies every saved setting in the fixture, image identity/metadata, existing original/variant/logo/font bytes, hidden equipment and completion of an existing queued job.

The same check compares 17 critical implementation files against their hashes from the published test.12 image. These include the generation service/queue, original resolver, storage service, mobile detection, homepage limit, typography, upload/media/page controllers, admin JavaScript, progress/notification scripts and shared logo/download templates. Those implementations are unchanged; modified upstream/shared files are checked through the behavior tests above.

Compatibility adjustments preserve legacy per-album equipment settings when migrating to the new column, normalize ownership of retained job/font directories, keep the full prerelease image identifier separate from the stable schema version in Docker/updater/package checks, and keep prerelease publishing away from stable Docker aliases.

This proves the tracked features and isolated upgrade fixture. It does not inspect the user's live Unraid volumes; retain a normal backup before deploying any database upgrade.
