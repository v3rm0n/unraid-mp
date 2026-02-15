# Unraid Media Player Sync

Sync selected folders from `/mnt/user` to a mounted FAT32 media player.

- Detect, mount, and unmount FAT32 players from the Unraid UI.
- Browse shares, select folders, and preview add/keep/remove changes.
- Copy missing files only (`rsync --ignore-existing`); existing device files are preserved.
- Remove only deselected folders that were previously plugin-managed.
- "Adopt Existing" converts unmanaged devices to managed mode and preserves protected folders (`.rockbox`, `iPod_Control`, `Contacts`, `Calendars`, `Notes`, `System Volume Information`).

UI route: `/Settings/MediaPlayerSync`
