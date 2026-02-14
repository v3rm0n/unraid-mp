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

function saveSettings(array $settings): bool
{
    ensureConfigDir();
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents(settingsFile(), $json . PHP_EOL) !== false;
}

function loadSettings(): array
{
    $default = [
        'musicRoot' => 'Music',
        'selectedFolders' => [],
        'lastPlayerId' => '',
    ];
    $merged = array_merge($default, readJsonFile(settingsFile(), $default));
    if (($merged['lastPlayerId'] ?? '') === '' && isset($merged['lastPlayerUuid'])) {
        $merged['lastPlayerId'] = (string)$merged['lastPlayerUuid'];
    }
    unset($merged['lastPlayerUuid']);
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

            $players[] = [
                'id' => ($device['uuid'] ?? '') !== '' ? $device['uuid'] : ($device['path'] ?? ''),
                'path' => $device['path'] ?? '',
                'label' => $device['label'] ?? '',
                'uuid' => $device['uuid'] ?? '',
                'size' => $device['size'] ?? '',
                'mountpoint' => $device['mountpoint'] ?? '',
                'mounted' => !empty($device['mountpoint']),
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

    return ['ok' => true, 'mountpoint' => $mountpoint, 'message' => 'Mounted', 'logFile' => $logFile];
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

function normalizeMusicRoot(?string $value): string
{
    $musicRoot = trim((string)$value, '/');
    return $musicRoot === '' ? 'Music' : $musicRoot;
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

function getManagedState(string $uuid): array
{
    $file = managedFileForPlayer($uuid);
    if (!is_file($file)) {
        return ['managed' => false, 'folders' => []];
    }

    $raw = readJsonFile($file, []);
    $folderKeys = [];

    $isList = is_array($raw)
        && (function_exists('array_is_list')
            ? array_is_list($raw)
            : (count($raw) === 0 || array_keys($raw) === range(0, count($raw) - 1)));
    if ($isList) {
        $folderKeys = $raw;
    } elseif (is_array($raw) && isset($raw['folders']) && is_array($raw['folders'])) {
        $folderKeys = $raw['folders'];
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

    return ['managed' => true, 'folders' => array_keys($clean)];
}

function saveManagedState(string $uuid, array $folderKeys): void
{
    ensureConfigDir();
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
    @file_put_contents(managedFileForPlayer($uuid), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function buildSelectionSet(array $selected): array
{
    $set = [];
    foreach (sanitizeSelectedFolders($selected) as $entry) {
        $set[selectionKey($entry['share'], $entry['folder'])] = true;
    }
    return $set;
}

function destinationPath(string $mountpoint, string $musicRoot, string $share, string $folder): string
{
    return rtrim($mountpoint, '/') . '/' . normalizeMusicRoot($musicRoot) . '/' . selectionKey($share, $folder);
}

function checkFoldersSyncStatus(
    string $uuid,
    string $share,
    array $folders,
    ?array $selectedOverride = null,
    ?string $musicRootOverride = null
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
    $musicRoot = normalizeMusicRoot($musicRootOverride ?? (string)($settings['musicRoot'] ?? 'Music'));
    $selectedSet = buildSelectionSet($selectedOverride ?? ($settings['selectedFolders'] ?? []));
    $managedState = getManagedState($uuid);
    $managedSet = array_fill_keys($managedState['folders'], true);

    $statuses = [];
    foreach ($folders as $folder) {
        if (!is_string($folder) || !isSafeRelativePath($folder)) {
            continue;
        }

        $key = selectionKey($share, $folder);
        $exists = is_dir(destinationPath($mountpoint, $musicRoot, $share, $folder));
        $isSelected = isset($selectedSet[$key]);
        $isManaged = isset($managedSet[$key]);

        if ($isSelected && $exists) {
            $statuses[$folder] = 'keep';
        } elseif ($isSelected && !$exists) {
            $statuses[$folder] = 'add';
        } elseif ($isManaged && $exists) {
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

function getSyncPreview(string $uuid, ?array $selectedOverride = null, ?string $musicRootOverride = null): array
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
    $musicRoot = normalizeMusicRoot($musicRootOverride ?? (string)($settings['musicRoot'] ?? 'Music'));
    $selected = sanitizeSelectedFolders($selected);

    $selectedSet = [];
    $selectedOut = [];
    $counts = ['keep' => 0, 'add' => 0, 'remove' => 0, 'external' => 0];

    foreach ($selected as $entry) {
        $share = $entry['share'];
        $folder = $entry['folder'];
        $key = selectionKey($share, $folder);
        $selectedSet[$key] = true;

        $exists = is_dir(destinationPath($mountpoint, $musicRoot, $share, $folder));
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

    $managedState = getManagedState($uuid);
    $removeCandidates = [];
    if ($managedState['managed']) {
        foreach ($managedState['folders'] as $managedKey) {
            if (isset($selectedSet[$managedKey])) {
                continue;
            }
            $parts = splitSelectionKey($managedKey);
            if ($parts === null) {
                continue;
            }
            $exists = is_dir(destinationPath($mountpoint, $musicRoot, $parts['share'], $parts['folder']));
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
        'musicRoot' => $musicRoot,
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

function managedFileForPlayer(string $uuid): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $uuid);
    return configDir() . '/managed-' . $safe . '.json';
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

function syncPlayer(string $uuid): array
{
    ensureConfigDir();
    $settings = loadSettings();
    $musicRoot = normalizeMusicRoot((string)($settings['musicRoot'] ?? 'Music'));
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

    $destRoot = rtrim($mountpoint, '/') . '/' . $musicRoot;
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
            foreach ($result['output'] as $line) {
                $log[] = $line;
                if (preg_match('/Number of regular files transferred:\s+([0-9]+)/', $line, $m)) {
                    $copied += (int)$m[1];
                }
            }
            if ($result['code'] !== 0) {
                $errors[] = 'rsync failed for ' . $share . '/' . $folder;
            }
        }

        $previousManaged = getManagedState($uuid)['folders'];

        $removed = 0;
        foreach ($previousManaged as $oldRelative) {
            if (!is_string($oldRelative) || !isSafeRelativePath($oldRelative)) {
                continue;
            }
            if (isset($currentManaged[$oldRelative])) {
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

        saveManagedState($uuid, array_keys($currentManaged));

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
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }

        $musicRoot = trim((string)($payload['musicRoot'] ?? 'Music'));
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $musicRoot)) {
            jsonOut(['ok' => false, 'error' => 'Invalid music root'], 400);
        }

        if (!isset($payload['selectedFolders']) || !is_array($payload['selectedFolders'])) {
            jsonOut(['ok' => false, 'error' => 'Invalid selected folders'], 400);
        }
        $cleanSelected = sanitizeSelectedFolders($payload['selectedFolders']);

        $settings = loadSettings();
        $settings['musicRoot'] = $musicRoot;
        $settings['selectedFolders'] = $cleanSelected;
        $settings['lastPlayerId'] = (string)($payload['lastPlayerId'] ?? ($settings['lastPlayerId'] ?? ''));

        if (!saveSettings($settings)) {
            jsonOut(['ok' => false, 'error' => 'Failed to save settings'], 500);
        }

        jsonOut(['ok' => true, 'settings' => $settings]);

    case 'checkSyncStatus':
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
        }
        $uuid = (string)($payload['uuid'] ?? '');
        $share = (string)($payload['share'] ?? '');
        $folders = (array)($payload['folders'] ?? []);
        $selectedOverride = isset($payload['selectedFolders']) && is_array($payload['selectedFolders'])
            ? $payload['selectedFolders']
            : null;
        $musicRootOverride = isset($payload['musicRoot']) ? (string)$payload['musicRoot'] : null;

        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        if ($share === '') {
            jsonOut(['ok' => false, 'error' => 'Missing share'], 400);
        }

        $result = checkFoldersSyncStatus($uuid, $share, $folders, $selectedOverride, $musicRootOverride);
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
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
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
        $musicRootOverride = isset($payload['musicRoot']) ? (string)$payload['musicRoot'] : null;

        $preview = getSyncPreview($uuid, $selectedOverride, $musicRootOverride);
        if (isset($preview['error'])) {
            jsonOut(['ok' => false, 'error' => $preview['error']], 400);
        }
        jsonOut(array_merge(['ok' => true], $preview));

    case 'sync':
        $uuid = (string)($_POST['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        jsonOut(syncPlayer($uuid));

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action'], 404);
}
