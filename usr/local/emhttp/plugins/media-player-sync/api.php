<?php
declare(strict_types=1);

// Disable output buffering to ensure immediate response
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);

header('Content-Type: application/json');

function configDir(): string
{
    $override = getenv('MEDIA_PLAYER_SYNC_CONFIG_DIR');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }
    return '/boot/config/plugins/media-player-sync';
}

function settingsFile(): string
{
    return configDir() . '/settings.json';
}

function lockFile(): string
{
    $override = getenv('MEDIA_PLAYER_SYNC_LOCK_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return '/tmp/media-player-sync.lock';
}

function syncStateFile(): string
{
    return '/tmp/media-player-sync-state.json';
}

function readSyncState(): array
{
    $file = syncStateFile();
    if (!is_file($file)) {
        return ['running' => false];
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return ['running' => false];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['running' => false];
    }
    return $decoded;
}

function writeSyncState(array $state): void
{
    @file_put_contents(syncStateFile(), json_encode($state, JSON_PRETTY_PRINT) . PHP_EOL);
}

function clearSyncState(): void
{
    $file = syncStateFile();
    if (is_file($file)) {
        @unlink($file);
    }
}

function logDir(): string
{
    $dir = '/tmp/media-player-sync-logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    flush();
    exit;
}

function ensureConfigDir(): void
{
    $dir = configDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $logDir = $dir . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
}

function run(string $command): array
{
    $output = [];
    $code = 0;
    $safePath = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    exec($safePath . ' ' . $command . ' 2>&1', $output, $code);
    return ['code' => $code, 'output' => $output];
}

function readJsonFile(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function readRequestPayload(): array
{
    $formPayload = $_POST['payload'] ?? null;
    if (is_string($formPayload) && $formPayload !== '') {
        $decoded = json_decode($formPayload, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveSettings(array $settings): bool
{
    ensureConfigDir();
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(settingsFile(), $json . PHP_EOL) !== false;
}

function loadSettings(): array
{
    $default = [
        'selectedFolders' => [],
        'lastPlayerId' => '',
        'lastBrowseShare' => '',
    ];
    $merged = array_merge($default, readJsonFile(settingsFile(), $default));
    if (($merged['lastPlayerId'] ?? '') === '' && isset($merged['lastPlayerUuid'])) {
        $merged['lastPlayerId'] = (string)$merged['lastPlayerUuid'];
    }
    unset($merged['lastPlayerUuid']);

    $merged['lastBrowseShare'] = is_string($merged['lastBrowseShare']) ? $merged['lastBrowseShare'] : '';
    return $merged;
}

function isSafeRelativePath(string $path): bool
{
    if ($path === '' || $path === '.' || str_starts_with($path, '/')) {
        return false;
    }
    if (str_contains($path, '..')) {
        return false;
    }
    // Allow any printable characters except control chars and backslashes
    // Forward slashes are allowed for hierarchical paths like "Artist/Album/CD 01"
    return (bool)preg_match("#^[^\x00-\x1f\x7f\\\\]+$#u", $path);
}

function getPlayers(): array
{
    $result = run('lsblk -J -o PATH,TYPE,FSTYPE,LABEL,UUID,SIZE,MOUNTPOINT');
    if ($result['code'] !== 0) {
        return [];
    }

    $json = json_decode(implode("\n", $result['output']), true);
    if (!isset($json['blockdevices']) || !is_array($json['blockdevices'])) {
        return [];
    }

    $players = [];
    $walker = function (array $device) use (&$walker, &$players): void {
        $children = $device['children'] ?? [];
        if (($device['type'] ?? '') === 'part' && strtolower((string)($device['fstype'] ?? '')) === 'vfat') {
            $mountpoint = (string)($device['mountpoint'] ?? '');
            $label = strtoupper((string)($device['label'] ?? ''));
            if ($mountpoint === '/boot' || $label === 'UNRAID') {
                return;
            }

            $mountpoint = (string)($device['mountpoint'] ?? '');
            $isMounted = !empty($mountpoint);
            $diskSpace = $isMounted ? getDiskSpace($mountpoint) : null;

            $players[] = [
                'id' => ($device['uuid'] ?? '') !== '' ? $device['uuid'] : ($device['path'] ?? ''),
                'path' => $device['path'] ?? '',
                'label' => $device['label'] ?? '',
                'uuid' => $device['uuid'] ?? '',
                'size' => $device['size'] ?? '',
                'mountpoint' => $mountpoint,
                'mounted' => $isMounted,
                'diskSpace' => $diskSpace,
            ];
        }
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $walker($child);
                }
            }
        }
    };

    foreach ($json['blockdevices'] as $device) {
        if (is_array($device)) {
            $walker($device);
        }
    }

    return $players;
}

function playerByUuid(string $uuid): ?array
{
    foreach (getPlayers() as $player) {
        $id = (string)($player['id'] ?? '');
        if (($player['uuid'] ?? '') === $uuid || $id === $uuid || ($player['path'] ?? '') === $uuid) {
            return $player;
        }
    }
    return null;
}

function isMounted(string $mountpoint): bool
{
    $mountpoint = rtrim($mountpoint, '/');
    if ($mountpoint === '') {
        $mountpoint = '/';
    }

    $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        $parts = explode(' ', $line);
        if (count($parts) < 2) {
            continue;
        }
        $current = str_replace('\\040', ' ', $parts[1]);
        if ($current === $mountpoint) {
            return true;
        }
    }

    return false;
}

function runDetached(string $command, string $logFile): array
{
    ensureConfigDir();
    $wrapped = $command . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null &';
    return run(sprintf('sh -c %s', escapeshellarg($wrapped)));
}

function waitForMountState(string $mountpoint, bool $shouldBeMounted, int $timeoutSeconds): bool
{
    $deadline = time() + $timeoutSeconds;
    while (time() <= $deadline) {
        $mounted = isMounted($mountpoint);
        if ($mounted === $shouldBeMounted) {
            return true;
        }
        usleep(300000);
    }
    return false;
}

function readTail(string $file, int $lines = 20): array
{
    if (!is_file($file)) {
        return [];
    }
    $content = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($content)) {
        return [];
    }
    return array_slice($content, -$lines);
}

function getDiskSpace(string $mountpoint): ?array
{
    if ($mountpoint === '' || !is_dir($mountpoint)) {
        return null;
    }

    $output = [];
    $code = 0;
    exec('df -B1 ' . escapeshellarg($mountpoint) . ' 2>/dev/null', $output, $code);

    if ($code !== 0 || count($output) < 2) {
        return null;
    }

    $line = $output[1];
    $parts = preg_split('/\s+/', $line);
    if (count($parts) < 6) {
        return null;
    }

    $total = (int)$parts[1];
    $used = (int)$parts[2];
    $free = (int)$parts[3];

    if ($total <= 0) {
        return null;
    }

    $usedPercent = round(($used / $total) * 100, 1);
    $freePercent = round(($free / $total) * 100, 1);

    return [
        'total' => $total,
        'used' => $used,
        'free' => $free,
        'usedPercent' => $usedPercent,
        'freePercent' => $freePercent,
    ];
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    $value = $bytes;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return round($value, 2) . ' ' . $units[$unitIndex];
}

function mountPlayer(string $uuid): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['ok' => false, 'error' => 'Player not found'];
    }

    if (!empty($player['mountpoint'])) {
        return ['ok' => true, 'mountpoint' => $player['mountpoint'], 'message' => 'Already mounted'];
    }

    $safeId = preg_replace('/[^A-Za-z0-9_-]/', '-', $uuid);
    $mountpoint = '/mnt/disks/media-player-' . ($safeId !== '' ? $safeId : basename((string)$player['path']));
    if (!is_dir($mountpoint) && !mkdir($mountpoint, 0775, true) && !is_dir($mountpoint)) {
        return ['ok' => false, 'error' => 'Failed to create mountpoint'];
    }

    $devicePath = (string)($player['path'] ?? '');
    if ($devicePath === '') {
        return ['ok' => false, 'error' => 'Player has no device path'];
    }

    if (isMounted($mountpoint)) {
        return ['ok' => true, 'mountpoint' => $mountpoint, 'message' => 'Already mounted'];
    }

    $logFile = logDir() . '/mount-' . date('Ymd-His') . '.log';
    $cmd = sprintf('sudo /bin/mount -t vfat %s %s', escapeshellarg($devicePath), escapeshellarg($mountpoint));
    
    $spawn = runDetached($cmd, $logFile);
    if ($spawn['code'] !== 0) {
        return ['ok' => false, 'error' => 'Failed to start mount command', 'logFile' => $logFile, 'output' => $spawn['output']];
    }

    if (!waitForMountState($mountpoint, true, 20)) {
        return [
            'ok' => false,
            'error' => 'Mount did not complete within 20s',
            'logFile' => $logFile,
            'logTail' => readTail($logFile),
        ];
    }

    $diskSpace = getDiskSpace($mountpoint);

    return [
        'ok' => true,
        'mountpoint' => $mountpoint,
        'message' => 'Mounted',
        'logFile' => $logFile,
        'diskSpace' => $diskSpace,
    ];
}

function unmountPlayer(string $uuid): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['ok' => false, 'error' => 'Player not found'];
    }
    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['ok' => true, 'message' => 'Already unmounted'];
    }

    $logFile = logDir() . '/unmount-' . date('Ymd-His') . '.log';
    $spawn = runDetached(sprintf('sudo /bin/umount %s', escapeshellarg($mountpoint)), $logFile);
    if ($spawn['code'] !== 0) {
        return ['ok' => false, 'error' => 'Failed to start unmount command', 'logFile' => $logFile, 'output' => $spawn['output']];
    }

    if (!waitForMountState($mountpoint, false, 15)) {
        return [
            'ok' => false,
            'error' => 'Unmount did not complete within 15s',
            'logFile' => $logFile,
            'logTail' => readTail($logFile),
        ];
    }

    return ['ok' => true, 'message' => 'Unmounted', 'logFile' => $logFile];
}

function listShares(): array
{
    $base = '/mnt/user';
    if (!is_dir($base)) {
        return [];
    }
    $entries = scandir($base);
    if ($entries === false) {
        return [];
    }

    $shares = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (str_starts_with($entry, '.')) {
            continue;
        }
        $path = $base . '/' . $entry;
        if (is_dir($path)) {
            $shares[] = $entry;
        }
    }
    sort($shares);
    return $shares;
}

function listFolders(string $share, string $subPath = ''): array
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
        return [];
    }
    $root = '/mnt/user/' . $share;
    if (!is_dir($root)) {
        return [];
    }

    $currentPath = $root;
    if ($subPath !== '') {
        if (!isSafeRelativePath($subPath)) {
            return [];
        }
        $currentPath = $root . '/' . $subPath;
        if (!is_dir($currentPath)) {
            return [];
        }
    }

    $folders = [];
    $entries = @scandir($currentPath);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (str_starts_with($entry, '.')) {
            continue;
        }
        $fullPath = $currentPath . '/' . $entry;
        if (!is_dir($fullPath)) {
            continue;
        }
        // Allow any printable characters except control chars and path separators
        // This handles music folder names like "Selected Ambient Works 85–92 (1992)" and "…I Care Because You Do"
        if (!preg_match('#^[^\x00-\x1f\x7f\\/]+$#u', $entry)) {
            continue;
        }
        $relative = ($subPath !== '' ? $subPath . '/' : '') . $entry;
        $folders[] = [
            'name' => $entry,
            'relative' => $relative,
            'hasChildren' => hasSubdirectories($fullPath),
        ];
    }

    usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $folders;
}

function hasSubdirectories(string $path): bool
{
    $entries = @scandir($path);
    if ($entries === false) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (str_starts_with($entry, '.')) {
            continue;
        }
        if (is_dir($path . '/' . $entry)) {
            return true;
        }
    }
    return false;
}

function sanitizeSelectedFolders($selected): array
{
    if (!is_array($selected)) {
        return [];
    }

    $clean = [];
    $seen = [];
    foreach ($selected as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $share = (string)($entry['share'] ?? '');
        $folder = trim((string)($entry['folder'] ?? ''), '/');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
            continue;
        }
        if (!isSafeRelativePath($folder)) {
            continue;
        }
        $key = $share . '/' . $folder;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = ['share' => $share, 'folder' => $folder];
    }

    return $clean;
}

function selectionKey(string $share, string $folder): string
{
    return $share . '/' . $folder;
}

function splitSelectionKey(string $key): ?array
{
    $parts = explode('/', $key, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$share, $folder] = $parts;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
        return null;
    }
    if (!isSafeRelativePath($folder)) {
        return null;
    }
    return ['share' => $share, 'folder' => $folder];
}

function folderPathOverlaps(string $a, string $b): bool
{
    return $a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/');
}

function selectionKeyOverlaps(string $a, string $b): bool
{
    $aParts = splitSelectionKey($a);
    $bParts = splitSelectionKey($b);
    if ($aParts === null || $bParts === null) {
        return false;
    }
    if ($aParts['share'] !== $bParts['share']) {
        return false;
    }

    return folderPathOverlaps($aParts['folder'], $bParts['folder']);
}

function selectionSetOverlapsKey(array $selectionSet, string $key): bool
{
    foreach ($selectionSet as $selectedKey => $enabled) {
        if (!$enabled || !is_string($selectedKey)) {
            continue;
        }
        if (selectionKeyOverlaps($selectedKey, $key)) {
            return true;
        }
    }

    return false;
}

function isArrayListCompat(array $value): bool
{
    return function_exists('array_is_list')
        ? array_is_list($value)
        : (count($value) === 0 || array_keys($value) === range(0, count($value) - 1));
}

function managedFileForPlayer(string $playerId): string
{
    $safeId = preg_replace('/[^A-Za-z0-9._-]/', '-', $playerId);
    if (!is_string($safeId) || $safeId === '') {
        $safeId = 'unknown';
    }
    return configDir() . '/managed-' . $safeId . '.json';
}

function legacyManagedFileForMountpoint(string $mountpoint): string
{
    return rtrim($mountpoint, '/') . '/.media-player-sync-managed.json';
}

function parseManagedFolderKeys($raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    $folderKeys = null;
    if (isArrayListCompat($raw)) {
        $folderKeys = $raw;
    } elseif (isset($raw['folders']) && is_array($raw['folders'])) {
        $folders = $raw['folders'];
        if (isArrayListCompat($folders)) {
            $folderKeys = $folders;
        } else {
            $folderKeys = [];
            foreach ($folders as $key => $enabled) {
                if (!is_string($key)) {
                    continue;
                }
                if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === null) {
                    continue;
                }
                $folderKeys[] = $key;
            }
        }
    }

    if ($folderKeys === null) {
        return null;
    }

    $clean = [];
    foreach ($folderKeys as $key) {
        if (!is_string($key)) {
            continue;
        }
        $parts = splitSelectionKey($key);
        if ($parts === null) {
            continue;
        }
        $normalized = selectionKey($parts['share'], $parts['folder']);
        $clean[$normalized] = true;
    }

    return array_keys($clean);
}

function getManagedState(string $playerId, string $mountpoint): array
{
    if ($playerId === '') {
        return ['managed' => false, 'folders' => []];
    }

    $files = [managedFileForPlayer($playerId)];
    if ($mountpoint !== '' && is_dir($mountpoint)) {
        $files[] = legacyManagedFileForMountpoint($mountpoint);
    }

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        $parsed = parseManagedFolderKeys($decoded);
        if ($parsed === null) {
            continue;
        }

        return ['managed' => true, 'folders' => $parsed];
    }

    return ['managed' => false, 'folders' => []];
}

function saveManagedState(string $playerId, array $folderKeys): bool
{
    if ($playerId === '') {
        return false;
    }

    $clean = [];
    foreach ($folderKeys as $key) {
        if (!is_string($key)) {
            continue;
        }
        $parts = splitSelectionKey($key);
        if ($parts === null) {
            continue;
        }
        $normalized = selectionKey($parts['share'], $parts['folder']);
        $clean[$normalized] = true;
    }

    $payload = [
        'managedBy' => 'media-player-sync',
        'version' => 1,
        'updatedAt' => date('c'),
        'folders' => array_keys($clean),
    ];

    ensureConfigDir();
    $target = managedFileForPlayer($playerId);
    $tmp = $target . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function buildSelectionSet(array $selected): array
{
    $set = [];
    foreach (sanitizeSelectedFolders($selected) as $entry) {
        $set[selectionKey($entry['share'], $entry['folder'])] = true;
    }
    return $set;
}

function destinationPath(string $mountpoint, string $share, string $folder): string
{
    return rtrim($mountpoint, '/') . '/' . selectionKey($share, $folder);
}

function checkFoldersSyncStatus(
    string $uuid,
    string $share,
    array $folders,
    ?array $selectedOverride = null
): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['statuses' => [], 'error' => 'Player not found'];
    }

    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['statuses' => [], 'error' => 'Player not mounted'];
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
        return ['statuses' => [], 'error' => 'Invalid share'];
    }

    $settings = loadSettings();
    $selectedSet = buildSelectionSet($selectedOverride ?? ($settings['selectedFolders'] ?? []));
    $managedState = getManagedState($uuid, $mountpoint);
    $managedSet = array_fill_keys($managedState['folders'], true);

    $statuses = [];
    foreach ($folders as $folder) {
        if (!is_string($folder) || !isSafeRelativePath($folder)) {
            continue;
        }

        $key = selectionKey($share, $folder);
        $exists = is_dir(destinationPath($mountpoint, $share, $folder));
        $isSelected = isset($selectedSet[$key]);
        $overlapsSelected = selectionSetOverlapsKey($selectedSet, $key);
        $isManaged = isset($managedSet[$key]);

        if ($isSelected && $exists) {
            $statuses[$folder] = 'keep';
        } elseif ($isSelected && !$exists) {
            $statuses[$folder] = 'add';
        } elseif ($isManaged && $exists && !$overlapsSelected) {
            $statuses[$folder] = 'remove';
        } elseif ($exists) {
            $statuses[$folder] = 'external';
        } else {
            $statuses[$folder] = 'none';
        }
    }

    return [
        'statuses' => $statuses,
        'managed' => (bool)$managedState['managed'],
    ];
}

function getSyncPreview(string $uuid, ?array $selectedOverride = null): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['error' => 'Player not found'];
    }

    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['error' => 'Player not mounted'];
    }

    $settings = loadSettings();
    $selected = $selectedOverride ?? ($settings['selectedFolders'] ?? []);
    $selected = sanitizeSelectedFolders($selected);

    $selectedSet = [];
    $selectedOut = [];
    $counts = ['keep' => 0, 'add' => 0, 'remove' => 0, 'external' => 0];

    foreach ($selected as $entry) {
        $share = $entry['share'];
        $folder = $entry['folder'];
        $key = selectionKey($share, $folder);
        $selectedSet[$key] = true;

        $exists = is_dir(destinationPath($mountpoint, $share, $folder));
        $state = $exists ? 'keep' : 'add';
        $counts[$state]++;
        $selectedOut[] = [
            'share' => $share,
            'folder' => $folder,
            'key' => $key,
            'state' => $state,
            'onDevice' => $exists,
        ];
    }

    $managedState = getManagedState($uuid, $mountpoint);
    $removeCandidates = [];
    if ($managedState['managed']) {
        foreach ($managedState['folders'] as $managedKey) {
            if (selectionSetOverlapsKey($selectedSet, $managedKey)) {
                continue;
            }
            $parts = splitSelectionKey($managedKey);
            if ($parts === null) {
                continue;
            }
            $exists = is_dir(destinationPath($mountpoint, $parts['share'], $parts['folder']));
            if (!$exists) {
                continue;
            }
            $counts['remove']++;
            $removeCandidates[] = [
                'key' => $managedKey,
                'share' => $parts['share'],
                'folder' => $parts['folder'],
            ];
        }
    }

    return [
        'managed' => (bool)$managedState['managed'],
        'summary' => [
            'keep' => $counts['keep'],
            'add' => $counts['add'],
            'remove' => $counts['remove'],
            'selected' => count($selectedOut),
            'managedFolders' => count($managedState['folders']),
        ],
        'selected' => $selectedOut,
        'removeCandidates' => $removeCandidates,
    ];
}

function listImmediateDirectories(string $path): array
{
    $entries = @scandir($path);
    if ($entries === false) {
        return [];
    }

    $dirs = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }
        $full = $path . '/' . $entry;
        if (is_dir($full)) {
            $dirs[] = $entry;
        }
    }
    return $dirs;
}

function collectAdoptedSelections(string $destRoot): array
{
    if (!is_dir($destRoot)) {
        return [];
    }

    $selected = [];
    $seen = [];
    foreach (listImmediateDirectories($destRoot) as $share) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
            continue;
        }

        $sharePath = $destRoot . '/' . $share;
        foreach (listImmediateDirectories($sharePath) as $folder) {
            $sourceFolder = '/mnt/user/' . $share . '/' . $folder;
            if (!is_dir($sourceFolder)) {
                continue;
            }
            if (!preg_match('#^[^\x00-\x1f\x7f\\/]+$#u', $folder)) {
                continue;
            }

            $entry = ['share' => $share, 'folder' => $folder];
            $key = selectionKey($entry['share'], $entry['folder']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = $entry;
        }
    }

    usort($selected, fn($a, $b) => strcasecmp($a['share'] . '/' . $a['folder'], $b['share'] . '/' . $b['folder']));
    return $selected;
}

function isProtectedDeviceRelativePath(string $relativePath): bool
{
    $topLevel = explode('/', ltrim($relativePath, '/'), 2)[0] ?? '';
    if ($topLevel === '') {
        return false;
    }

    static $protected = [
        '.rockbox' => true,
        'iPod_Control' => true,
        'Contacts' => true,
        'Calendars' => true,
        'Notes' => true,
        'System Volume Information' => true,
    ];

    return isset($protected[$topLevel]);
}

function buildAdoptionPlan(string $mountpoint): array
{
    $destRoot = rtrim($mountpoint, '/');
    $deleteFiles = [];
    $deleteDirs = [];

    if (is_dir($destRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $item->getPathname();
            $relative = ltrim(substr($destPath, strlen($destRoot)), '/');
            if ($relative === '') {
                continue;
            }

            if (isProtectedDeviceRelativePath($relative)) {
                continue;
            }

            $sourcePath = '/mnt/user/' . $relative;
            if (file_exists($sourcePath)) {
                continue;
            }

            if ($item->isDir()) {
                $deleteDirs[] = $destPath;
            } else {
                $deleteFiles[] = $destPath;
            }
        }
    }

    $selected = collectAdoptedSelections($destRoot);

    return [
        'destRoot' => $destRoot,
        'deleteFiles' => $deleteFiles,
        'deleteDirs' => $deleteDirs,
        'selectedFolders' => $selected,
    ];
}

function getAdoptPreview(string $uuid): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['error' => 'Player not found'];
    }

    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['error' => 'Player not mounted'];
    }

    $plan = buildAdoptionPlan($mountpoint);

    $sampleDeletes = [];
    foreach (array_slice($plan['deleteFiles'], 0, 8) as $path) {
        $sampleDeletes[] = ['type' => 'file', 'path' => $path];
    }
    foreach (array_slice($plan['deleteDirs'], 0, 8 - count($sampleDeletes)) as $path) {
        $sampleDeletes[] = ['type' => 'dir', 'path' => $path];
    }

    return [
        'managed' => (bool)getManagedState($uuid, $mountpoint)['managed'],
        'summary' => [
            'deleteFiles' => count($plan['deleteFiles']),
            'deleteDirs' => count($plan['deleteDirs']),
            'adoptFolders' => count($plan['selectedFolders']),
        ],
        'sampleDeletes' => $sampleDeletes,
        'selectedFolders' => $plan['selectedFolders'],
    ];
}

function adoptLibrary(string $uuid): array
{
    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['ok' => false, 'error' => 'Player not found'];
    }

    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['ok' => false, 'error' => 'Player must be mounted before adopting'];
    }

    $settings = loadSettings();
    $plan = buildAdoptionPlan($mountpoint);

    if (!acquireLock()) {
        return ['ok' => false, 'error' => 'Another sync is currently running'];
    }

    $errors = [];
    $deletedFiles = 0;
    $deletedDirs = 0;
    $log = [];
    $log[] = '[' . date('Y-m-d H:i:s') . '] Starting adoption cleanup';

    try {
        foreach ($plan['deleteFiles'] as $path) {
            if (!is_file($path) && !is_link($path)) {
                continue;
            }
            if (@unlink($path)) {
                $deletedFiles++;
            } else {
                $errors[] = 'Failed to delete file: ' . $path;
            }
        }

        foreach ($plan['deleteDirs'] as $path) {
            if (!is_dir($path)) {
                continue;
            }
            if (@rmdir($path)) {
                $deletedDirs++;
            } else {
                $errors[] = 'Failed to delete directory: ' . $path;
            }
        }

        $selected = collectAdoptedSelections($plan['destRoot']);
        $keys = [];
        foreach ($selected as $entry) {
            $keys[] = selectionKey($entry['share'], $entry['folder']);
        }
        if (!saveManagedState($uuid, $keys)) {
            $errors[] = 'Failed to save managed state after adoption';
        }

        $settings['selectedFolders'] = $selected;
        $settings['lastPlayerId'] = $uuid;
        if (!saveSettings($settings)) {
            $errors[] = 'Failed to save settings after adoption';
        }

        $log[] = 'Deleted files: ' . $deletedFiles;
        $log[] = 'Deleted directories: ' . $deletedDirs;
        $log[] = 'Adopted roots: ' . count($selected);
        foreach ($errors as $error) {
            $log[] = $error;
        }

        $logPath = logDir() . '/adopt-' . date('Ymd-His') . '.log';
        @file_put_contents($logPath, implode(PHP_EOL, $log) . PHP_EOL);

        return [
            'ok' => count($errors) === 0,
            'deletedFiles' => $deletedFiles,
            'deletedDirs' => $deletedDirs,
            'adoptedFolders' => count($selected),
            'errors' => $errors,
            'settings' => $settings,
            'managed' => true,
            'logFile' => $logPath,
            'logTail' => array_slice($log, -60),
        ];
    } finally {
        releaseLock();
    }
}

function acquireLock(): bool
{
    $lock = lockFile();
    if (is_file($lock)) {
        return false;
    }
    return file_put_contents($lock, (string)getmypid()) !== false;
}

function releaseLock(): void
{
    $lock = lockFile();
    if (is_file($lock)) {
        unlink($lock);
    }
}

function doSyncPlayer(string $uuid): array
{
    ensureConfigDir();
    $settings = loadSettings();
    $selected = sanitizeSelectedFolders($settings['selectedFolders'] ?? []);

    if (!is_array($selected) || count($selected) === 0) {
        return ['ok' => false, 'error' => 'No folders selected'];
    }

    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['ok' => false, 'error' => 'Player not found'];
    }
    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['ok' => false, 'error' => 'Player must be mounted before sync'];
    }

    $destRoot = rtrim($mountpoint, '/');
    if (!is_dir($destRoot) && !mkdir($destRoot, 0775, true) && !is_dir($destRoot)) {
        return ['ok' => false, 'error' => 'Failed to create destination root'];
    }

    if (!acquireLock()) {
        return ['ok' => false, 'error' => 'Another sync is currently running'];
    }

    $copied = 0;
    $errors = [];
    $log = [];
    $currentManaged = [];
    $timestamp = date('Y-m-d H:i:s');
    $log[] = '[' . $timestamp . '] Starting sync';

    try {
        foreach ($selected as $entry) {
            $share = (string)$entry['share'];
            $folder = (string)$entry['folder'];

            $src = '/mnt/user/' . $share . '/' . $folder;
            if (!is_dir($src)) {
                $errors[] = 'Missing source: ' . $src;
                continue;
            }

            $relativeDest = selectionKey($share, $folder);
            $currentManaged[$relativeDest] = true;
            $dest = $destRoot . '/' . $relativeDest;
            if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
                $errors[] = 'Could not create destination: ' . $dest;
                continue;
            }

            $cmd = sprintf(
                'rsync -rltDv --ignore-existing --no-owner --no-group --modify-window=1 --stats %s %s',
                escapeshellarg(rtrim($src, '/') . '/'),
                escapeshellarg(rtrim($dest, '/') . '/')
            );
            $result = run($cmd);
            $log[] = 'Syncing ' . $share . '/' . $folder;
            $rsyncError = null;
            foreach ($result['output'] as $line) {
                $log[] = $line;
                if (preg_match('/Number of regular files transferred:\s+([0-9]+)/', $line, $m)) {
                    $copied += (int)$m[1];
                }
                if (preg_match('/No space left on device/', $line)) {
                    $rsyncError = 'No space left on device';
                } elseif (preg_match('/rsync error:/', $line) && $rsyncError === null) {
                    $rsyncError = trim($line);
                }
            }
            if ($result['code'] !== 0) {
                if ($rsyncError !== null) {
                    $errors[] = 'rsync failed for ' . $share . '/' . $folder . ': ' . $rsyncError;
                } else {
                    $errors[] = 'rsync failed for ' . $share . '/' . $folder;
                }
            }
        }

        $previousManaged = getManagedState($uuid, $mountpoint)['folders'];

        $removed = 0;
        foreach ($previousManaged as $oldRelative) {
            if (!is_string($oldRelative) || !isSafeRelativePath($oldRelative)) {
                continue;
            }
            if (selectionSetOverlapsKey($currentManaged, $oldRelative)) {
                continue;
            }

            $stalePath = $destRoot . '/' . $oldRelative;
            if (!str_starts_with($stalePath, $destRoot . '/')) {
                continue;
            }
            if (is_dir($stalePath)) {
                $rm = run(sprintf('rm -rf %s', escapeshellarg($stalePath)));
                $log[] = 'Removed unselected folder: ' . $oldRelative;
                if ($rm['code'] === 0) {
                    $removed++;
                } else {
                    $errors[] = 'Failed to remove: ' . $oldRelative;
                }
            }
        }

        if (!saveManagedState($uuid, array_keys($currentManaged))) {
            $errors[] = 'Failed to save managed state';
        }

        $logPath = logDir() . '/sync-' . date('Ymd-His') . '.log';
        @file_put_contents($logPath, implode(PHP_EOL, $log) . PHP_EOL);

        return [
            'ok' => count($errors) === 0,
            'copiedFiles' => $copied,
            'removedDirs' => $removed,
            'errors' => $errors,
            'logFile' => $logPath,
            'logTail' => array_slice($log, -60),
        ];
    } finally {
        releaseLock();
    }
}

function startBackgroundSync(string $uuid): array
{
    $state = readSyncState();
    if ($state['running'] ?? false) {
        return ['ok' => false, 'error' => 'Another sync is already running'];
    }

    $settings = loadSettings();
    $selected = sanitizeSelectedFolders($settings['selectedFolders'] ?? []);

    if (!is_array($selected) || count($selected) === 0) {
        return ['ok' => false, 'error' => 'No folders selected'];
    }

    $player = playerByUuid($uuid);
    if ($player === null) {
        return ['ok' => false, 'error' => 'Player not found'];
    }
    $mountpoint = (string)($player['mountpoint'] ?? '');
    if ($mountpoint === '') {
        return ['ok' => false, 'error' => 'Player must be mounted before sync'];
    }

    $logFile = logDir() . '/sync-' . date('Ymd-His') . '.log';

    writeSyncState([
        'running' => true,
        'uuid' => $uuid,
        'startedAt' => date('c'),
        'logFile' => $logFile,
        'progress' => ['current' => 0, 'total' => count($selected), 'currentFolder' => ''],
    ]);

    $safeUuid = escapeshellarg($uuid);
    $safeLog = escapeshellarg($logFile);
    $apiPath = escapeshellarg(__FILE__);

    $cmd = sprintf(
        'php %s --run-sync=%s --log=%s > /dev/null 2>&1 &',
        $apiPath,
        $safeUuid,
        $safeLog
    );

    exec($cmd);

    return ['ok' => true, 'message' => 'Sync started in background', 'logFile' => $logFile];
}

function runBackgroundSync(string $uuid, string $logFile): void
{
    $result = doSyncPlayer($uuid);

    $log = [];
    if (is_file($logFile)) {
        $existing = @file($logFile, FILE_IGNORE_NEW_LINES);
        if (is_array($existing)) {
            $log = $existing;
        }
    }

    $log[] = '';
    $log[] = '[BACKGROUND SYNC COMPLETED]';
    $log[] = 'Result: ' . ($result['ok'] ? 'SUCCESS' : 'FAILED');
    $log[] = 'Copied files: ' . ($result['copiedFiles'] ?? 0);
    $log[] = 'Removed directories: ' . ($result['removedDirs'] ?? 0);
    if (!empty($result['errors'])) {
        $log[] = 'Errors:';
        foreach ($result['errors'] as $error) {
            $log[] = '  - ' . $error;
        }
    }

    @file_put_contents($logFile, implode(PHP_EOL, $log) . PHP_EOL);

    writeSyncState([
        'running' => false,
        'completedAt' => date('c'),
        'result' => $result,
        'logFile' => $logFile,
    ]);
}

function syncPlayer(string $uuid): array
{
    return startBackgroundSync($uuid);
}

function getSyncStatus(): array
{
    $state = readSyncState();

    if (!($state['running'] ?? false)) {
        return [
            'running' => false,
            'result' => $state['result'] ?? null,
            'completedAt' => $state['completedAt'] ?? null,
        ];
    }

    $logTail = [];
    if (!empty($state['logFile']) && is_file($state['logFile'])) {
        $logTail = readTail($state['logFile'], 30);
    }

    return [
        'running' => true,
        'startedAt' => $state['startedAt'] ?? null,
        'logFile' => $state['logFile'] ?? null,
        'progress' => $state['progress'] ?? null,
        'logTail' => $logTail,
    ];
}

if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--run-sync=')) {
            $uuid = substr($arg, 11);
            $logFile = '';
            foreach ($argv as $a) {
                if (str_starts_with($a, '--log=')) {
                    $logFile = substr($a, 6);
                    break;
                }
            }
            if ($uuid !== '' && $logFile !== '') {
                runBackgroundSync($uuid, $logFile);
            }
            exit(0);
        }
    }
}

ensureConfigDir();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'listPlayers':
        jsonOut(['ok' => true, 'players' => getPlayers()]);

    case 'mount':
        $uuid = (string)($_POST['uuid'] ?? '');
        jsonOut(mountPlayer($uuid));

    case 'unmount':
        $uuid = (string)($_POST['uuid'] ?? '');
        jsonOut(unmountPlayer($uuid));

    case 'listShares':
        jsonOut(['ok' => true, 'shares' => listShares()]);

    case 'listFolders':
        $share = (string)($_GET['share'] ?? '');
        $path = (string)($_GET['path'] ?? '');
        jsonOut(['ok' => true, 'folders' => listFolders($share, $path)]);

    case 'getSettings':
        jsonOut(['ok' => true, 'settings' => loadSettings()]);

    case 'saveSettings':
        $payload = readRequestPayload();
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }

        if (!isset($payload['selectedFolders']) || !is_array($payload['selectedFolders'])) {
            jsonOut(['ok' => false, 'error' => 'Invalid selected folders'], 400);
        }

        $cleanSelected = sanitizeSelectedFolders($payload['selectedFolders']);

        $lastBrowseShare = (string)($payload['lastBrowseShare'] ?? '');
        if ($lastBrowseShare !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $lastBrowseShare)) {
            $lastBrowseShare = '';
        }
        $allShares = listShares();
        if ($lastBrowseShare !== '' && !in_array($lastBrowseShare, $allShares, true)) {
            $lastBrowseShare = '';
        }

        $settings = loadSettings();
        $settings['selectedFolders'] = $cleanSelected;
        $settings['lastPlayerId'] = (string)($payload['lastPlayerId'] ?? ($settings['lastPlayerId'] ?? ''));
        $settings['lastBrowseShare'] = $lastBrowseShare;

        if (!saveSettings($settings)) {
            jsonOut(['ok' => false, 'error' => 'Failed to save settings'], 500);
        }

        jsonOut(['ok' => true, 'settings' => $settings]);

    case 'checkSyncStatus':
        $payload = readRequestPayload();
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }
        $uuid = (string)($payload['uuid'] ?? '');
        $share = (string)($payload['share'] ?? '');
        $folders = (array)($payload['folders'] ?? []);
        $selectedOverride = isset($payload['selectedFolders']) && is_array($payload['selectedFolders'])
            ? $payload['selectedFolders']
            : null;

        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        if ($share === '') {
            jsonOut(['ok' => false, 'error' => 'Missing share'], 400);
        }

        $result = checkFoldersSyncStatus($uuid, $share, $folders, $selectedOverride);
        if (isset($result['error'])) {
            jsonOut(['ok' => false, 'error' => $result['error'], 'statuses' => $result['statuses'] ?? []]);
        }
        $synced = [];
        foreach (($result['statuses'] ?? []) as $folder => $status) {
            $synced[$folder] = ($status === 'keep');
        }
        jsonOut([
            'ok' => true,
            'managed' => (bool)($result['managed'] ?? false),
            'statuses' => $result['statuses'] ?? [],
            'synced' => $synced,
        ]);

    case 'getSyncPreview':
        $payload = readRequestPayload();
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }
        $uuid = (string)($payload['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }

        $selectedOverride = isset($payload['selectedFolders']) && is_array($payload['selectedFolders'])
            ? $payload['selectedFolders']
            : null;

        $preview = getSyncPreview($uuid, $selectedOverride);
        if (isset($preview['error'])) {
            jsonOut(['ok' => false, 'error' => $preview['error']], 400);
        }
        jsonOut(array_merge(['ok' => true], $preview));

    case 'getAdoptPreview':
        $payload = readRequestPayload();
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }
        $uuid = (string)($payload['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        $preview = getAdoptPreview($uuid);
        if (isset($preview['error'])) {
            jsonOut(['ok' => false, 'error' => $preview['error']], 400);
        }
        jsonOut(array_merge(['ok' => true], $preview));

    case 'adoptLibrary':
        $payload = readRequestPayload();
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }
        $uuid = (string)($payload['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        jsonOut(adoptLibrary($uuid));

    case 'sync':
        $uuid = (string)($_POST['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        jsonOut(syncPlayer($uuid));

    case 'getSyncStatus':
        jsonOut(['ok' => true, 'status' => getSyncStatus()]);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action'], 404);
}
