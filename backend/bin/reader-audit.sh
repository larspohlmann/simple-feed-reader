#!/usr/bin/env bash
#
# The reader cleanup audit, end to end: sweep a stratified sample of the
# subscribed articles through the real extract-and-clean pipeline, then write the
# ranked HTML report whose titles deep-link into the running SPA.
#
# Runs inside the Docker php container, because that stack holds the real
# subscriptions and entries; the native SQLite database is the test one.
#
# Usage (from the repository root or from backend/):
#   backend/bin/reader-audit.sh                 # 1000 articles, 8 parallel shards
#   backend/bin/reader-audit.sh 200 4           # 200 articles, 4 shards
#   LIMIT=1000 SHARDS=8 USER=lars@example.com backend/bin/reader-audit.sh
#
set -euo pipefail

LIMIT="${1:-${LIMIT:-1000}}"
SHARDS="${2:-${SHARDS:-8}}"
PER_FEED="${PER_FEED:-8}"
SEED="${SEED:-20260831}"
BASE_URL="${BASE_URL:-http://localhost:4200}"
OUT_DIR="${OUT_DIR:-var/reader-audit}"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

if ! docker compose ps --status running --services | grep -qx php; then
  echo "The php container is not running. Start the stack with: docker compose up -d" >&2
  exit 1
fi

user_option=()
if [ -n "${USER_ACCOUNT:-}" ]; then
  user_option=(--user "$USER_ACCOUNT")
fi

# One cutoff for every shard: the refresh worker keeps ingesting during a sweep,
# and a shard that saw a newer entry set would draw a different sample.
BEFORE="$(date -u '+%Y-%m-%d %H:%M:%S')"

echo "Sweeping $LIMIT articles in $SHARDS shards (seed $SEED, entries before $BEFORE UTC)…"
docker compose exec -T php sh -c "rm -f $OUT_DIR/findings*.jsonl"

pids=()
for shard in $(seq 0 $((SHARDS - 1))); do
  docker compose exec -T php bin/console app:reader:audit \
    ${user_option[@]+"${user_option[@]}"} \
    --limit "$LIMIT" --per-feed "$PER_FEED" --seed "$SEED" --before "$BEFORE" \
    --shards "$SHARDS" --shard "$shard" \
    --base-url "$BASE_URL" \
    --out "$OUT_DIR/findings-$shard.jsonl" \
    >"/tmp/reader-audit-$shard.log" 2>&1 &
  pids+=($!)
done

failed=0
for pid in "${pids[@]}"; do
  wait "$pid" || failed=1
done

if [ "$failed" -ne 0 ]; then
  echo "At least one shard failed; its output is in /tmp/reader-audit-*.log" >&2
  tail -n 20 /tmp/reader-audit-*.log >&2
  exit 1
fi

# The shards fetch under their own concurrency, and a host that rate-limits the
# burst (Substack answers 429 at eight parallel fetches) reads as a failed
# extraction. Every failure is measured again alone before it counts; the report
# reads the files in name order and takes the later measurement, and
# "remeasured" sorts after the shard digits (#783).
failed_ids="$(docker compose exec -T php sh -c "cat $OUT_DIR/findings-*.jsonl" \
  | grep -F '"extracted":false' | sed -E 's/.*"entryId":([0-9]+).*/\1/' | paste -sd, -)"
if [ -n "$failed_ids" ]; then
  failed_count="$(printf '%s' "$failed_ids" | tr ',' '\n' | wc -l | tr -d ' ')"
  echo "Re-measuring $failed_count failed articles one at a time…"
  docker compose exec -T php bin/console app:reader:audit \
    ${user_option[@]+"${user_option[@]}"} \
    --entries "$failed_ids" --base-url "$BASE_URL" \
    --out "$OUT_DIR/findings-remeasured.jsonl" >"/tmp/reader-audit-remeasured.log" 2>&1
  recovered="$(docker compose exec -T php cat "$OUT_DIR/findings-remeasured.jsonl" | grep -cF '"extracted":true' || true)"
  echo "$recovered of $failed_count extracted alone; $((failed_count - recovered)) failures hold."
fi

docker compose exec -T php bin/console app:reader:audit:report \
  --in "$OUT_DIR/findings*.jsonl" --out "$OUT_DIR/report.html"

echo
echo "Report: backend/$OUT_DIR/report.html"
