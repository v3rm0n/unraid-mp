# Unraid Media Player Sync Plugin

This plugin manages FAT32 media players (for example Rockbox devices) from the Unraid web UI.

## Features

- Detect attached FAT32 partitions and let you select a target player.
- Mount and unmount the selected player.
- Browse `/mnt/user` shares and choose folders to sync.
- Preview sync impact before running changes.
- Copy only missing files (`rsync --ignore-existing`) so existing device files are not overwritten.
- Remove only deselected folders that were previously plugin-managed.
- Support "Adopt Existing" for unmanaged devices.

## Sync model

- Destination layout is share-root based: `<mountpoint>/<share>/<folder>`.
- Selected folders are stored as objects: `{ "share": "...", "folder": "..." }`.
- Unmanaged players are add-only during sync.
- Managed players can remove stale plugin-managed folders.

## Adopt Existing behavior

Adopt performs cleanup of unmatched content and then marks the player as managed.

- Deletes device paths that do not exist under `/mnt/user` at the same relative location.
- Preserves protected top-level device folders:
  - `.rockbox`
  - `iPod_Control`
  - `Contacts`
  - `Calendars`
  - `Notes`
  - `System Volume Information`

## Runtime paths

- UI route: `/Settings/MediaPlayerSync`
- API: `/usr/local/emhttp/plugins/media-player-sync/api.php`
- Settings: `/boot/config/plugins/media-player-sync/settings.json`
- Managed files: `/boot/config/plugins/media-player-sync/managed-<id>.json`
- Lock file: `/tmp/media-player-sync.lock`
- Logs: `/tmp/media-player-sync-logs/`

## Local testing

Run from the project root on an Unraid host:

```bash
./install-local.sh
```

Then open `/Settings/MediaPlayerSync`.

## Notes

- POST actions are CSRF protected and currently use form payloads with `payload` + `csrf_token`.
- Backend accepts this form payload and also supports raw JSON fallback.
- For local non-Unraid testing, `api.php` supports `MEDIA_PLAYER_SYNC_CONFIG_DIR` and `MEDIA_PLAYER_SYNC_LOCK_FILE` environment overrides.
