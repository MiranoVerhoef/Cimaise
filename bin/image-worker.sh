#!/bin/sh
# ───────────────────────────────────────────────────────────────────────────
# Cimaise image worker (Docker)
#
# Apache's mod_php cannot keep working after a response has been sent, so the
# container drains the image job queue from here. PHP is started only while
# job files exist: an idle site costs one directory listing per interval.
# The queue lives on the storage volume and survives restarts.
#
#   image-worker.sh [application directory]
# ───────────────────────────────────────────────────────────────────────────
APP_DIR="${1:-/var/www/html}"
JOBS_DIR="$APP_DIR/storage/image-jobs"
INTERVAL="${CIMAISE_IMAGE_WORKER_INTERVAL:-3}"
# After a failed run, or while the only jobs left are waiting for a retry.
RETRY_INTERVAL="${CIMAISE_IMAGE_WORKER_RETRY_INTERVAL:-15}"

has_jobs() {
  for job in "$JOBS_DIR"/*.job; do
    [ -e "$job" ] && return 0
  done
  return 1
}

while :; do
  delay="$INTERVAL"
  # Not before the installer has run, and never during an update.
  if has_jobs && [ -f "$APP_DIR/.env" ] && [ ! -f "$APP_DIR/storage/.maintenance" ]; then
    php "$APP_DIR/bin/console" images:work --quiet-mode || delay="$RETRY_INTERVAL"
    if has_jobs; then
      delay="$RETRY_INTERVAL"
    fi
  fi
  sleep "$delay"
done
