<?php
declare(strict_types=1);

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

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
    return (bool)preg_match('/^[A-Za-z0-9._\/-]+$/', $path);
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

    $logFile = configDir() . '/logs/mount-' . date('Ymd-His') . '.log';
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

    $logFile = configDir() . '/logs/unmount-' . date('Ymd-His') . '.log';
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

function listFolders(string $share): array
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $share)) {
        return [];
    }
    $root = '/mnt/user/' . $share;
    if (!is_dir($root)) {
        return [];
    }

    $folders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $fullPath = $item->getPathname();
        $relative = ltrim(str_replace($root, '', $fullPath), '/');
        if ($relative === '') {
            continue;
        }
        if (!isSafeRelativePath($relative)) {
            continue;
        }
        $folders[] = $relative;
    }
    sort($folders);
    return $folders;
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
    $musicRoot = trim((string)($settings['musicRoot'] ?? 'Music'), '/');
    $musicRoot = $musicRoot === '' ? 'Music' : $musicRoot;
    $selected = $settings['selectedFolders'] ?? [];

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

            $src = '/mnt/user/' . $share . '/' . $folder;
            if (!is_dir($src)) {
                $errors[] = 'Missing source: ' . $src;
                continue;
            }

            $relativeDest = $share . '/' . $folder;
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

        $managedFile = managedFileForPlayer($uuid);
        $previousManaged = readJsonFile($managedFile, []);
        if (!is_array($previousManaged)) {
            $previousManaged = [];
        }

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

        file_put_contents($managedFile, json_encode(array_keys($currentManaged), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $logPath = configDir() . '/logs/sync-' . date('Ymd-His') . '.log';
        file_put_contents($logPath, implode(PHP_EOL, $log) . PHP_EOL);

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
        jsonOut(['ok' => true, 'folders' => listFolders($share)]);

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

        $selected = $payload['selectedFolders'] ?? [];
        if (!is_array($selected)) {
            jsonOut(['ok' => false, 'error' => 'Invalid selected folders'], 400);
        }

        $cleanSelected = [];
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
            $cleanSelected[] = ['share' => $share, 'folder' => $folder];
        }

        $settings = loadSettings();
        $settings['musicRoot'] = $musicRoot;
        $settings['selectedFolders'] = $cleanSelected;
        $settings['lastPlayerId'] = (string)($payload['lastPlayerId'] ?? ($settings['lastPlayerId'] ?? ''));

        if (!saveSettings($settings)) {
            jsonOut(['ok' => false, 'error' => 'Failed to save settings'], 500);
        }

        jsonOut(['ok' => true, 'settings' => $settings]);

    case 'sync':
        $uuid = (string)($_POST['uuid'] ?? '');
        if ($uuid === '') {
            jsonOut(['ok' => false, 'error' => 'Missing player uuid'], 400);
        }
        jsonOut(syncPlayer($uuid));

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action'], 404);
}
