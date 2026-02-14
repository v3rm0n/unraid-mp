#!/bin/bash
set -euo pipefail

TARGET="/usr/local/emhttp/plugins/media-player-sync"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)/usr/local/emhttp/plugins/media-player-sync"

mkdir -p "$TARGET"
cp "$SOURCE_DIR"/* "$TARGET"/

mkdir -p /boot/config/plugins/media-player-sync/logs

echo "Installed plugin files to $TARGET"
echo "Open Unraid Web UI and browse to /plugins/media-player-sync/MediaPlayerSync.page"
