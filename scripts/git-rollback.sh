#!/usr/bin/env bash
set -euo pipefail

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Error: run this script inside a git repository."
  exit 1
fi

if [ "${1:-}" = "" ]; then
  echo "Available checkpoints:"
  git tag --list 'checkpoint-*' --sort=-creatordate | head -n 30
  echo
  echo "Usage: ./scripts/git-rollback.sh <checkpoint-tag>"
  exit 1
fi

target="$1"
if ! git rev-parse --verify "$target^{tag}" >/dev/null 2>&1; then
  echo "Error: tag '$target' not found."
  exit 1
fi

backup_branch="backup-before-rollback-$(date +%Y-%m-%d-%H%M%S)"
git branch "$backup_branch"

git reset --hard "$target"
echo "Rollback done to: $target"
echo "Backup branch created: $backup_branch"
