#!/bin/bash
set -euo pipefail
container="$1"
port="$2"
base="http://localhost:$port"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
docker exec -u www-data "$container" php /tests/setup-web.php
docker exec "$container" cat /var/www/html/storage/tmp/upload.jpg > "$tmp/upload.jpg"
for attempt in $(seq 1 40); do
  if curl -fsS -c "$tmp/cookies" -D "$tmp/headers" "$base/admin/login" > "$tmp/login"; then break; fi
  sleep 2
done
csrf=$(awk 'tolower($1)=="x-csrf-token:" {gsub("\r", "", $2); print $2}' "$tmp/headers")
test -n "$csrf"
curl -fsS -b "$tmp/cookies" -c "$tmp/cookies" -D "$tmp/headers" \
  --data-urlencode "email=test@example.test" --data-urlencode "password=Test-pass-12345" \
  --data-urlencode "csrf=$csrf" "$base/admin/login" > "$tmp/result"
grep -q '302' "$tmp/headers"
csrf=$(awk 'tolower($1)=="x-csrf-token:" {gsub("\r", "", $2); print $2}' "$tmp/headers")
test -n "$csrf"
curl -fsS -b "$tmp/cookies" -D "$tmp/headers" -H "X-CSRF-Token: $csrf" \
  -F "file=@$tmp/upload.jpg" "$base/admin/albums/1/upload" > "$tmp/result"
cat "$tmp/result"
id=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["ok"]; print(d["id"])' "$tmp/result")
grep -qi 'x-csrf-token:' "$tmp/headers"
for attempt in $(seq 1 60); do
  if docker exec "$container" test -f "/var/www/html/public/media/${id}_lg.avif"; then break; fi
  sleep 2
done
for size in sm md lg; do
  for format in jpg webp avif; do
    docker exec "$container" test -s "/var/www/html/public/media/${id}_${size}.${format}"
  done
done
echo 'PASS: authenticated HTTP upload automatically generated all variants'
for command in images:generate images:generate-variants; do
  if docker exec -u www-data "$container" php /var/www/html/bin/console "$command" --image=999; then
    echo 'FAIL: CLI swallowed a generation failure'
    exit 1
  fi
done
echo 'PASS: bin/console propagates generation failures to the shell'
# Persist a job, interrupt the container, then verify retry without another upload.
docker exec "$container" sh -c "rm /var/www/html/public/media/${id}_lg.jpg; touch /var/www/html/storage/image-jobs/${id}.job; chown www-data:www-data /var/www/html/storage/image-jobs/${id}.job"
docker restart "$container"
if [ "${3:-}" = "fpm" ]; then
  # Non-Docker FPM deployments wake their detached CLI worker on a web request.
  sleep 3
  curl -fsS "$base/admin/login" > /dev/null
fi
for attempt in $(seq 1 60); do
  if docker exec "$container" test -s "/var/www/html/public/media/${id}_lg.jpg"; then
    echo 'PASS: queued work recovered after container restart'
    exit 0
  fi
  sleep 2
done
docker logs "$container"
exit 1
