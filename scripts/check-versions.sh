#!/bin/sh
# Every *_VERSION pin in .env (or .env.example when .env is absent) must appear in docs/VERSIONS.md. Exit 1 on a mismatch.
set -u
cd "$(dirname "$0")/.."
f=.env; [ -f "$f" ] || f=.env.example
bad=0
for kv in $(grep -E '^[A-Z_]+_VERSION=' "$f"); do
  k=${kv%%=*}; v=${kv#*=}
  if grep -qF "| $v " docs/VERSIONS.md; then echo "ok       $k=$v"; else echo "MISMATCH $k=$v"; bad=1; fi
done
exit $bad
