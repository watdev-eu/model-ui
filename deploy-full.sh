#!/usr/bin/env bash
set -euo pipefail

# Pull latest code
sudo git pull

# Generate version metadata
export GIT_SHA="$(git rev-parse HEAD)"
export BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Rebuild ALL services
sudo --preserve-env=GIT_SHA,BUILD_DATE docker compose \
  -f docker-compose.prod.yml \
  up -d --build