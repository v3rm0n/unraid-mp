# AGENTS.md

Guidance for humans and coding agents working on this repository.

## Mission

Maintain the Unraid Media Player Sync plugin for FAT32 music players with safe, predictable behavior:

- mount/unmount from UI
- browse `/mnt/user` shares
- preview sync impact
- copy missing files only
- remove stale folders only when they were previously plugin-managed
- optionally adopt an unmanaged device into managed mode

## Repository map

- `media-player-sync.plg` - plugin manifest, install-time files, sudoers entry
- `install-local.sh` - local copy/install helper for Unraid hosts
- `usr/local/emhttp/plugins/media-player-sync/MediaPlayerSync.php` - UI markup and client JS
- `usr/local/emhttp/plugins/media-player-sync/api.php` - backend actions and core sync/adopt logic
- `usr/local/emhttp/plugins/media-player-sync/plugin.css` - styling

## Behavioral invariants (do not regress)

1. Destination layout is share-root based: `<mountpoint>/<share>/<folder>`.
2. Do not reintroduce a separate `musicRoot` destination setting in active flow.
3. `selectedFolders` entries are objects: `{ "share": "...", "folder": "..." }`.
4. Sync copies with `rsync --ignore-existing`; existing device files are not overwritten.
5. Sync removal is limited to previously managed folders for that player.
6. Unmanaged players are add-only until adoption is performed.
7. Adopt cleanup must preserve protected top-level device folders:
   - `.rockbox`
   - `iPod_Control`
   - `Contacts`
   - `Calendars`
   - `Notes`
   - `System Volume Information`
8. Keep path safety checks (`isSafeRelativePath`) and block traversal.
9. Keep lock protection around sync/adopt (`/tmp/media-player-sync.lock`).

## API contract notes

Current actions in `api.php`:

- `listPlayers`
- `mount`
- `unmount`
- `listShares`
- `listFolders`
- `getSettings`
- `saveSettings`
- `checkSyncStatus`
- `getSyncPreview`
- `getAdoptPreview`
- `adoptLibrary`
- `sync`

POST requests are CSRF protected. UI currently sends form-encoded payloads with:

- `payload`: JSON string
- `csrf_token`: Unraid token

Backend supports this form payload and also raw JSON fallback.

## Runtime paths

- UI route: `/Settings/MediaPlayerSync`
- Plugin dir: `/usr/local/emhttp/plugins/media-player-sync/`
- Settings dir: `/boot/config/plugins/media-player-sync/`
- Settings file: `/boot/config/plugins/media-player-sync/settings.json`
- Managed files: `/boot/config/plugins/media-player-sync/managed-<id>.json`
- Logs: `/tmp/media-player-sync-logs/`

## Change checklist

When editing behavior, verify at least:

1. Mounted unmanaged player shows add-only preview and exposes "Adopt Existing".
2. Mounted managed player hides "Adopt Existing" and can show removals for deselected managed folders.
3. Sync preview and folder status calls still work (no CSRF or payload regressions).
4. Sync and adopt produce log output and release lock on completion/failure.
5. Destination paths remain `<mountpoint>/<share>/<folder>`.

## Local workflow

Install for testing on an Unraid host:

```bash
./install-local.sh
```

Then open:

`/Settings/MediaPlayerSync`

## Server deployment

To deploy changes to the production Unraid server (nixnas):

```bash
ssh nixnas
cd ~/unraid-mp
git pull
./install-local.sh
```

This updates the plugin files on the server and makes changes live.

Access the Unraid Web UI at: https://nixnas.bearded-stork.ts.net
Plugin settings page: https://nixnas.bearded-stork.ts.net/Settings/MediaPlayerSync

Helpful quick checks:

```bash
php -l usr/local/emhttp/plugins/media-player-sync/api.php
php -l usr/local/emhttp/plugins/media-player-sync/MediaPlayerSync.php
```
