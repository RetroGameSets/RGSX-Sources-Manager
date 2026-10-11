<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/rgsx_catalog_db.php';

const RGSX_EXPORT_DEFAULT_URL = 'https://retrogamesets.fr/wsquashfs/rgsx_export.php';
const RGSX_WINDOWS_PLATFORM_ID = 143;
const RGSX_EXPORT_EXTENSIONS = [
    '7z', 'wsquashfs', 'zip', 'txt', 'rar', 'keys',
];

function rgsx_sync_detail_messages(array $counts): array
{
    $messages = [];
    foreach ([
        'added_games' => 'ADDED',
        'updated_games' => 'UPDATED',
        'deleted_games' => 'DELETED',
    ] as $key => $action) {
        foreach ($counts[$key] ?? [] as $game) {
            if (!is_array($game)) {
                throw new RuntimeException('Synchronization returned an invalid game change detail.');
            }
            $details = json_encode(
                $game,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($details)) {
                throw new RuntimeException('Unable to encode a game change detail for the log.');
            }
            $messages[] = $action . ' ' . $details;
        }
    }
    return $messages;
}

function rgsx_sync_append_log(array $messages, string $filename = 'sync_wsquashfs_export.log'): void
{
    if ($messages === []) {
        throw new InvalidArgumentException('At least one synchronization log entry is required.');
    }
    if (!preg_match('/\A[a-zA-Z0-9._-]+\.log\z/', $filename)) {
        throw new InvalidArgumentException('Invalid synchronization log filename.');
    }
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (is_link($directory)) {
        throw new RuntimeException('The synchronization log directory must not be a symbolic link.');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the synchronization log directory.');
    }
    if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($directory, 0700)) {
        throw new RuntimeException('Unable to secure the synchronization log directory.');
    }

    $denyPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_link($denyPath)) {
        throw new RuntimeException('The synchronization log access-control file must not be a symbolic link.');
    }
    if (!is_file($denyPath) && @file_put_contents($denyPath, "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to protect the synchronization logs from web access.');
    }
    $denyContents = @file_get_contents($denyPath);
    if (!is_string($denyContents) || trim($denyContents) !== 'Require all denied') {
        throw new RuntimeException('The synchronization log access-control file is invalid.');
    }
    if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($denyPath, 0600)) {
        throw new RuntimeException('Unable to secure the synchronization log access-control file.');
    }

    $logPath = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_link($logPath)) {
        throw new RuntimeException('The synchronization log file must not be a symbolic link.');
    }
    $previousUmask = umask(0077);
    try {
        $handle = @fopen($logPath, 'ab');
    } finally {
        umask($previousUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Unable to open the synchronization log file.');
    }

    try {
        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($logPath, 0600)) {
            throw new RuntimeException('Unable to secure the synchronization log file.');
        }
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the synchronization log file.');
        }
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($messages as $message) {
            if (!is_string($message)) {
                throw new InvalidArgumentException('Synchronization log entries must be strings.');
            }
            $entry = '[' . $timestamp . '] ' . $message . PHP_EOL;
            $offset = 0;
            while ($offset < strlen($entry)) {
                $written = fwrite($handle, substr($entry, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write the synchronization log entry.');
                }
                $offset += $written;
            }
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush the synchronization log entry.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function rgsx_sync_export_html(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'RGSX-Catalog-Scheduled-Sync/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($body)) {
        throw new RuntimeException('Export request failed: ' . ($error !== '' ? $error : 'empty response'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Export returned HTTP ' . $status . '.');
    }
    if (!str_contains(strtolower($body), '</tbody>')
        || !str_contains(strtolower($body), '</table>')
        || !str_contains(strtolower($body), '</html>')) {
        throw new RuntimeException('Export response is incomplete or is not the expected HTML export.');
    }

    return $body;
}

/**
 * @return array<int,array{0:string,1:string,2:string,3:string}>
 */
function rgsx_sync_parse_export(string $html): array
{
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('The PHP DOM extension is required.');
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument('1.0', 'UTF-8');
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Unable to parse export HTML.');
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
    }

    $rows = [];
    $seen = [];
    foreach ($document->getElementsByTagName('a') as $anchor) {
        if (!$anchor->hasAttribute('data-rgsx-url')) {
            continue;
        }

        $name = trim($anchor->textContent);
        $url = trim($anchor->getAttribute('data-rgsx-url'));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || $url === '' || !in_array($extension, RGSX_EXPORT_EXTENSIONS, true)) {
            continue;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('Export contains an invalid URL for ' . $name . '.');
        }

        $rowNode = $anchor->parentNode;
        while ($rowNode instanceof DOMElement && strtolower($rowNode->tagName) !== 'tr') {
            $rowNode = $rowNode->parentNode;
        }
        if (!$rowNode instanceof DOMElement) {
            throw new RuntimeException('Export row is missing for ' . $name . '.');
        }

        $cells = [];
        foreach ($rowNode->getElementsByTagName('td') as $cell) {
            $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
        }
        if (count($cells) < 3) {
            throw new RuntimeException('Export row is incomplete for ' . $name . '.');
        }

        $updatedAt = $cells[2];
        rgsx_catalog_db_import_updated_at($updatedAt, rgsx_catalog_db_now());
        $row = [$name, $url, $cells[1], $updatedAt];
        $identity = strtolower($name);
        $seen[$identity] = $row;
    }

    foreach ($seen as $row) {
        $rows[] = $row;
    }
    if (!$rows) {
        throw new RuntimeException('Export contains no recognized game entries; refusing to remove all games from windows.');
    }
    return $rows;
}

function rgsx_sync_target_platform_id(): int
{
    $platform = rgsx_catalog_db_platform_by_id(RGSX_WINDOWS_PLATFORM_ID);
    if ($platform === null) {
        throw new RuntimeException('Target platform ID 143 does not exist in the catalog.');
    }
    if (strcasecmp(trim((string)$platform['platform_name']), 'Windows (1Fichier)') !== 0
        || strcasecmp(trim((string)$platform['source']), '1Fichier') !== 0) {
        throw new RuntimeException('Platform ID 143 is not Windows (1Fichier) / 1Fichier; refusing to modify it.');
    }
    return RGSX_WINDOWS_PLATFORM_ID;
}

function rgsx_sync_run(): void
{
    $url = trim((string)(getenv('RGSX_WSQUASHFS_EXPORT_URL') ?: RGSX_EXPORT_DEFAULT_URL));
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new InvalidArgumentException('RGSX_WSQUASHFS_EXPORT_URL must be a valid HTTP(S) URL.');
    }

    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx-windows-export-sync.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open the synchronization lock file.');
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Another Windows export synchronization is already running.');
    }

    try {
        $html = rgsx_sync_export_html($url);
        $rows = rgsx_sync_parse_export($html);
        $platformId = rgsx_sync_target_platform_id();

        $counts = rgsx_catalog_db_sync_platform_games($platformId, $rows);
        $message = sprintf(
            'Synchronized platform Windows (1Fichier, id %d): %d added, %d updated, %d deleted, %d unchanged.',
            $platformId,
            $counts['added'],
            $counts['updated'],
            $counts['deleted'],
            $counts['unchanged'],
        );
        printf("[%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message);
        try {
            rgsx_sync_append_log(array_merge([$message], rgsx_sync_detail_messages($counts)));
        } catch (Throwable $exception) {
            fwrite(STDERR, '[' . gmdate('Y-m-d\TH:i:s\Z') . '] Sync completed, but the log could not be written: ' . $exception->getMessage() . "\n");
            exit(2);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        rgsx_sync_run();
    } catch (Throwable $exception) {
        $message = 'Sync failed: ' . $exception->getMessage();
        try {
            rgsx_sync_append_log([$message]);
        } catch (Throwable $logException) {
            $message .= '; log write failed: ' . $logException->getMessage();
        }
        fwrite(STDERR, '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $message . "\n");
        exit(1);
    }
}
