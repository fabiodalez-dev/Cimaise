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
curl -fsS -b "$tmp/cookies" "$base/admin/api/image-jobs" > "$tmp/jobs.json"
python3 -c 'import json,sys; assert isinstance(json.load(open(sys.argv[1]))["jobs"],list)' "$tmp/jobs.json"
curl -sS -D "$tmp/anonymous-headers" "$base/admin/api/image-jobs" > /dev/null
grep -q '302' "$tmp/anonymous-headers"
docker exec "$container" php /tests/prime-template-cache.php prime
curl -fsS -b "$tmp/cookies" "$base/admin/albums/1/edit" > "$tmp/old-admin"
grep -q 'OLD-ITALIAN-SIDEBAR' "$tmp/old-admin"
grep -q 'id="old-equipment-field"' "$tmp/old-admin"
docker exec "$container" php /tests/prime-template-cache.php restore
curl -fsS -b "$tmp/cookies" "$base/admin/albums/1/edit" > "$tmp/admin"
grep -q 'id="image-job-progress"' "$tmp/admin"
grep -q 'Overview' "$tmp/admin"
grep -q 'id="show_equipment"' "$tmp/admin"
if grep -q 'OLD-ITALIAN-SIDEBAR' "$tmp/admin"; then exit 1; fi
echo 'PASS: release upgrade refreshes cached sidebar and equipment controls'
if [ "${3:-}" != "fpm" ]; then
  for encoding in br gzip; do
    curl -fsS --compressed -H "Accept-Encoding: $encoding" -D "$tmp/compression" "$base/admin/login" > "$tmp/decoded"
    grep -qi "content-encoding: $encoding" "$tmp/compression"
    grep -qi '<!doctype html>' "$tmp/decoded"
  done
  curl -fsS -b "$tmp/cookies" "$base/admin/settings" > "$tmp/settings"
  grep -q 'Brotli Available' "$tmp/settings"
  echo 'PASS: Brotli/gzip negotiation, decoding and Apache diagnostics'
fi
echo 'PASS: authenticated progress endpoint and English sidebar'
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
curl -fsS -b "$tmp/cookies" "$base/admin/api/image-jobs" > "$tmp/jobs.json"
python3 -c 'import json,sys; jobs=json.load(open(sys.argv[1]))["jobs"]; j=next(j for j in jobs if j["id"]==int(sys.argv[2])); assert j["total"]==10 and j["completed"]==10 and j["state"]=="complete"' "$tmp/jobs.json" "$id"
curl -fsS -b "$tmp/cookies" "$base/admin/media/images/$id/variants" > "$tmp/variants.json"
python3 -c 'import json,sys; v=json.load(open(sys.argv[1]))["variants"]; assert len(v)==9 and all(x["ready"] and x["url"].startswith("/admin/media/") for x in v)' "$tmp/variants.json"
curl -fsS -b "$tmp/cookies" "$base/admin/media/images/$id/variants/lg.jpg" > "$tmp/preview.jpg"
test -s "$tmp/preview.jpg"
curl -sS -D "$tmp/anonymous-preview" "$base/admin/media/images/$id/variants/lg.jpg" > /dev/null
grep -q '302' "$tmp/anonymous-preview"
curl -sS -D "$tmp/anonymous-headers" "$base/admin/media/images/$id/variants" > /dev/null
grep -q '302' "$tmp/anonymous-headers"
pids=""
for number in 1 2 3 4; do
  curl -fsS -b "$tmp/cookies" -H "X-CSRF-Token: $csrf" \
    -F "file=@$tmp/upload.jpg" "$base/admin/albums/1/upload" > "$tmp/batch-$number.json" &
  pids="$pids $!"
done
for pid in $pids; do wait "$pid"; done
for number in 1 2 3 4; do
  batch_id=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert d["ok"]; print(d["id"])' "$tmp/batch-$number.json")
  for attempt in $(seq 1 60); do
    if docker exec "$container" test -s "/var/www/html/public/media/${batch_id}_lg.jpg"; then break; fi
    sleep 2
  done
  docker exec "$container" test -s "/var/www/html/public/media/${batch_id}_lg.jpg"
done
echo 'PASS: concurrent uploads complete while background generation is running'
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
  # PHP-FPM resumes jobs after a verified admin response, without spawning CLI.
  sleep 3
  curl -fsS -b "$tmp/cookies" "$base/admin/media" > /dev/null
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
