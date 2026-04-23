#!/usr/bin/env bash
set -euo pipefail

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Error: run this script inside a git repository."
  exit 1
fi

label="${1:-manual}"
label="$(
  echo "$label" \
    | tr '[:upper:]' '[:lower:]' \
    | tr -cs 'a-z0-9._-' '-' \
    | sed -E 's/^-+//; s/-+$//; s/-+/-/g'
)"
[ -n "$label" ] || label="manual"
stamp="$(date +%Y-%m-%d-%H%M%S)"
tag="checkpoint-${label}-${stamp}"

git tag "$tag"
echo "Created checkpoint tag: $tag"
echo "To rollback: git reset --hard $tag"
