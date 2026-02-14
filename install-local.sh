#!/bin/bash
set -euo pipefail

TARGET="/usr/local/emhttp/plugins/media-player-sync"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)/usr/local/emhttp/plugins/media-player-sync"
PLUGIN_FILE_SOURCE="$(cd "$(dirname "$0")" && pwd)/media-player-sync.plg"
PLUGIN_FILE_TARGET="/boot/config/plugins/media-player-sync.plg"

mkdir -p "$TARGET"
cp "$SOURCE_DIR"/* "$TARGET"/

if [ -d /boot/config/plugins ] || mkdir -p /boot/config/plugins 2>/dev/null; then
  cp "$PLUGIN_FILE_SOURCE" "$PLUGIN_FILE_TARGET"
  mkdir -p /boot/config/plugins/media-player-sync/logs
  echo "Registered plugin manifest at $PLUGIN_FILE_TARGET"
else
  echo "Skipped plugin manifest registration; /boot/config/plugins is unavailable"
fi

echo "Installed plugin files to $TARGET"
echo "Open Unraid Web UI and browse to /Settings/MediaPlayerSync"
