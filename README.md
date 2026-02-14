# unraid-mp

Unraid Media Player Sync plugin for syncing selected music folders to FAT32 media players (for example Rockbox devices).

## What it does

- Detects attached FAT32 (`vfat`) media player partitions.
- Mounts/unmounts the selected player from the Unraid UI.
- Lets you pick source shares/folders to sync.
- Copies only missing files (`rsync --ignore-existing`) so existing files are not overwritten.
- Removes only previously plugin-managed folders that are now unselected (safe prune).

## Project layout

- `media-player-sync.plg` - Unraid plugin manifest
- `usr/local/emhttp/plugins/media-player-sync/` - plugin UI, API, CSS, and docs
- `install-local.sh` - helper script to install files locally for testing

## Local test install

Run from the repository root on your Unraid server:

```bash
./install-local.sh
```

Then open:

`/Settings/MediaPlayerSync`

The installer also copies `media-player-sync.plg` to `/boot/config/plugins/media-player-sync.plg` so the plugin appears in Unraid's installed plugins list.

## License

MIT - see `LICENSE`.
