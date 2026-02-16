### Media Player Sync

Sync selected `/mnt/user` folders to a mounted FAT32 media player from the Unraid UI.

- Copies missing files only (`rsync --ignore-existing`) and keeps existing device files untouched.
- Removes deselected folders only when they were previously plugin-managed.
- Supports "Adopt Existing" for unmanaged devices while preserving protected system folders.
