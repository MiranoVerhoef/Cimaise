# Current fork baseline: upstream 1.4.28

The 1.4.28-test.1 application tree matches upstream release 1.4.28 exactly.
Upstream merged PR #142, retaining our navigation, media controls, branding,
cache invalidation, album storage, uploads and performance work, with the
maintainer's subsequent corrections. No application feature remains fork-only.

Fork differences are release metadata/history, this audit record and the older
published-fork upgrade fixture added alongside upstream's Docker upgrade test.
No deployment or volume mapping changes are included.

Intentional adopted behavior changes:
- Jobs park after ten failures; regeneration or re-upload requeues them.
- Workers wake only for due jobs; synchronous generation waits at most 20 seconds.
- Visitors poll only while generation is pending, for at most ten minutes.
- Docker pulls the upstream floating PHP 8.5 base; CI verifies PHP >= 8.5.12.
- Uploads retain originals, fitting files only when server limits require it.

The archived audit below describes earlier test releases and is historical;
its old indefinite-retry and pinning decisions are superseded above.

---

# Fork baseline verification

Comparison: published `1.4.23-test.12` → the fork baseline including upstream `1.4.26`.

The new baseline includes upstream v1.4.26, including merged PRs #139 and #140. Its original commits and contributor history are retained. Upstream contribution branches remain separate from this combined fork build.

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

The upgrade check retains exact source hashes for 9 unchanged implementations, including the per-image completion revision, album storage, mobile detection, homepage limit, typography, media/page/font controllers, progress/notification scripts and shared logo/download templates. Five intentionally adapted files (UploadService, ImageJobQueue, OriginalImage, UploadController and admin.js), plus three files with PHPDoc-only type clarifications (album storage and media/font controllers), are verified through their behavior rather than claiming identical source bytes.

## Upstream 1.4.26 reconciliation

- Adopt the queue's time budget, affected-album batch cache invalidation, guarded cache failures, authenticated PHP-FPM resumption and worker that avoids starting PHP while idle.
- Retain upload-token correlation and file-based per-variant progress. Public completion notices advance after album caches are invalidated.
- Retain failed jobs indefinitely with a capped retry delay; do not adopt upstream's six-attempt deletion policy.
- Retain SQLite write retries, per-image encoder locks, original-byte downloads and their public/admin controls.
- Retain prerelease migration handling, legacy equipment preferences and restored-volume ownership fixes unchanged.
- Include the separately committed login branding and collapsible Media Library toolbar filters.
- Remove the old global queue wakeup and duplicate Docker/console worker registration; only verified admin requests can resume jobs on PHP-FPM.

Verification uses the real production image, upgrade volumes, HTTP uploads and restricted PHP-FPM, queue/SQLite/CLI integration checks, and Chromium admin controls. The local PHP suite excludes Linux shell-worker tests; those run inside the production Docker image in CI.

## Album deletion regression

Deletion captures category tags before cascading the database rows, then invalidates and warms after the mutation. The Galleries warmer uses the same listing cover loader and first-image fallback as live rendering. Tests reproduce both stale deleted cards and blank warmed previews, verify shared media bytes remain unchanged, and exercise a real HTTP deletion with automatic warming enabled.
