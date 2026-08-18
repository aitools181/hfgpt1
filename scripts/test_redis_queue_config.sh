#!/bin/sh
set -eu
BLOCK_FOR=${REDIS_QUEUE_BLOCK_FOR:-5}
READ_TIMEOUT=${REDIS_QUEUE_READ_TIMEOUT:-15}
case "$BLOCK_FOR" in ''|*[!0-9]*) echo "invalid REDIS_QUEUE_BLOCK_FOR=$BLOCK_FOR" >&2; exit 1;; esac
# use awk for numeric decimal support
if ! awk -v b="$BLOCK_FOR" -v r="$READ_TIMEOUT" 'BEGIN { exit !((r == 0) || (r > b)) }'; then
  echo "REDIS_QUEUE_READ_TIMEOUT ($READ_TIMEOUT) must be 0/unlimited or greater than REDIS_QUEUE_BLOCK_FOR ($BLOCK_FOR)" >&2
  exit 1
fi
grep -q "'connection' => 'queue'" config/queue.php
grep -q "REDIS_QUEUE_READ_TIMEOUT" config/database.php
echo "Redis queue timeout invariant PASS (block_for=$BLOCK_FOR read_timeout=$READ_TIMEOUT)"
