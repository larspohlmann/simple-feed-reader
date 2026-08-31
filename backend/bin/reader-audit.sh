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

echo "Sweeping $LIMIT articles in $SHARDS shards (seed $SEED)…"
docker compose exec -T php sh -c "rm -f $OUT_DIR/findings*.jsonl"

pids=()
for shard in $(seq 0 $((SHARDS - 1))); do
  docker compose exec -T php bin/console app:reader:audit \
    ${user_option[@]+"${user_option[@]}"} \
    --limit "$LIMIT" --per-feed "$PER_FEED" --seed "$SEED" \
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

docker compose exec -T php bin/console app:reader:audit:report \
  --in "$OUT_DIR/findings*.jsonl" --out "$OUT_DIR/report.html"

echo
echo "Report: backend/$OUT_DIR/report.html"
