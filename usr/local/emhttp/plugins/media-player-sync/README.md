# Unraid Media Player Sync Plugin

This plugin manages FAT32 media players (for example Rockbox devices) as removable drives.

## Features

- Detect attached FAT32 partitions and let you select a target media player.
- Mount and unmount the selected player.
- Choose source music shares and specific folders to sync.
- Copy only missing files (`rsync --ignore-existing`) so existing files are kept.
- Remove only directories that were previously managed by this plugin and are now unselected.

## Paths

- Plugin page: `/usr/local/emhttp/plugins/media-player-sync/MediaPlayerSync.page`
- API: `/usr/local/emhttp/plugins/media-player-sync/api.php`
- Settings: `/boot/config/plugins/media-player-sync/settings.json`
- Logs: `/boot/config/plugins/media-player-sync/logs/`

## Install for local testing

Run from this project root on Unraid:

```bash
./install-local.sh
```

Then open:

- `/plugins/media-player-sync/MediaPlayerSync.page`

`install-local.sh` also registers `media-player-sync.plg` in `/boot/config/plugins/` so it appears in Unraid's plugin list.

## Sync behavior

- Destination root defaults to `Music` on the player and can be changed in the UI.
- Selected folders are mirrored into `<musicRoot>/<share>/<folder>`.
- Existing files on the media player are not overwritten.
- Unselected folders are pruned only if they were previously created/managed by this plugin.

## Notes

- The plugin expects `rsync`, `lsblk`, and standard mount tools to be available on Unraid.
- FAT32 filename limitations still apply; errors are written to the sync log.
- For local non-Unraid smoke testing, `api.php` supports `MEDIA_PLAYER_SYNC_CONFIG_DIR` and `MEDIA_PLAYER_SYNC_LOCK_FILE` environment overrides.
