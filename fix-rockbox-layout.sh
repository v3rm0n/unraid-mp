#!/bin/bash
# Fix Rockbox device layout: move albums from root to Music/ subdirectory
# Preserves built-in Rockbox directories

DEVICE_PATH="${1:-}"
DRY_RUN="${2:-}"

if [ -z "$DEVICE_PATH" ]; then
    echo "Usage: $0 <device-mount-path> [--dry-run]"
    echo "Example: $0 /mnt/disks/MYSANSACLIP"
    echo ""
    echo "This script moves all non-system folders from the device root"
    echo "to a Music/ subdirectory while preserving Rockbox system folders."
    exit 1
fi

if [ ! -d "$DEVICE_PATH" ]; then
    echo "Error: Device path does not exist: $DEVICE_PATH"
    exit 1
fi

# Protected directories that must stay in root
PROTECTED_DIRS=(
    ".rockbox"
    "iPod_Control"
    "Contacts"
    "Calendars"
    "Notes"
    "System Volume Information"
    "Music"
)

# Function to check if a directory is protected
is_protected() {
    local dir_name="$1"
    for protected in "${PROTECTED_DIRS[@]}"; do
        if [ "$dir_name" = "$protected" ]; then
            return 0
        fi
    done
    return 1
}

echo "=================================="
echo "Rockbox Layout Fix Tool"
echo "=================================="
echo "Device: $DEVICE_PATH"
echo ""

if [ "$DRY_RUN" = "--dry-run" ]; then
    echo "DRY RUN MODE - No changes will be made"
    echo ""
fi

# Create Music directory if it doesn't exist
if [ "$DRY_RUN" = "--dry-run" ]; then
    echo "[DRY RUN] Would create: $DEVICE_PATH/Music"
else
    if [ ! -d "$DEVICE_PATH/Music" ]; then
        mkdir -p "$DEVICE_PATH/Music"
        echo "Created: Music/"
    else
        echo "Already exists: Music/"
    fi
fi

echo ""
echo "Scanning directories..."
echo ""

# Find all directories in root (excluding . and ..)
MOVED_COUNT=0
SKIPPED_COUNT=0

for dir in "$DEVICE_PATH"/*/; do
    # Remove trailing slash and get basename
    dir="${dir%/}"
    dir_name=$(basename "$dir")
    
    # Skip if it's not a directory or if it's protected
    if [ ! -d "$dir" ]; then
        continue
    fi
    
    if is_protected "$dir_name"; then
        echo "SKIP (protected): $dir_name"
        ((SKIPPED_COUNT++))
        continue
    fi
    
    # Move the directory
    if [ "$DRY_RUN" = "--dry-run" ]; then
        echo "[DRY RUN] Would move: $dir_name -> Music/$dir_name"
    else
        echo "Moving: $dir_name -> Music/$dir_name"
        mv "$dir" "$DEVICE_PATH/Music/"
        if [ $? -eq 0 ]; then
            echo "  ✓ Success"
        else
            echo "  ✗ Failed"
        fi
    fi
    ((MOVED_COUNT++))
done

echo ""
echo "=================================="
echo "Summary:"
echo "  Directories to move: $MOVED_COUNT"
echo "  Protected (skipped): $SKIPPED_COUNT"
echo "=================================="

if [ "$DRY_RUN" = "--dry-run" ]; then
    echo ""
    echo "This was a dry run. To actually move the folders, run without --dry-run"
fi
