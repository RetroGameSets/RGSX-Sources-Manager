<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once dirname(__DIR__) . '/rgsx_catalog_db.php';
require_once __DIR__ . '/sync_wsquashfs_export.php';

const RGSX_TORRENT_FEED_DEFAULT_URL = 'https://retrogamesets.fr/thomsonito_torrents/rgsx_feed.php?metadata=1';
const RGSX_TORRENT_PLATFORM_ID = 144;

function rgsx_torrent_sync_fetch(string $url): string
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
        CURLOPT_USERAGENT => 'RGSX-Catalog-Torrent-Sync/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($body)) {
        throw new RuntimeException('Torrent feed request failed: ' . ($error !== '' ? $error : 'empty response'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Torrent feed returned HTTP ' . $status . '.');
    }
    return $body;
}

function rgsx_torrent_sync_is_list(array $value): bool
{
    $expectedIndex = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expectedIndex) {
            return false;
        }
        $expectedIndex++;
    }
    return true;
}

/**
 * @return array<int,array{0:string,1:string,2:string,3:string}>
 */
function rgsx_torrent_sync_parse_feed(string $json): array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Torrent feed returned invalid JSON.', 0, $exception);
    }
    if (!is_array($decoded) || !rgsx_torrent_sync_is_list($decoded)) {
        throw new RuntimeException('Torrent feed must be a JSON array.');
    }
    if ($decoded === []) {
        throw new RuntimeException('Torrent feed is empty; refusing to remove all games from the platform.');
    }

    $rows = [];
    $seen = [];
    foreach ($decoded as $index => $row) {
        if (!is_array($row) || !rgsx_torrent_sync_is_list($row) || count($row) < 4
            || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)
            || !is_string($row[2] ?? null) || !is_string($row[3] ?? null)) {
            throw new RuntimeException('Torrent feed row ' . ($index + 1) . ' is missing name, URL, size, or modification date.');
        }

        $name = trim($row[0]);
        $url = trim($row[1]);
        $size = trim($row[2]);
        $modifiedAt = trim($row[3]);
        if ($name === '' || $url === '' || $modifiedAt === '') {
            throw new RuntimeException('Torrent feed row ' . ($index + 1) . ' has an empty name, URL, or modification date.');
        }
        if (!str_starts_with(strtolower($url), 'rgsx+torrent://')) {
            throw new RuntimeException('Torrent feed row ' . ($index + 1) . ' is not an RGSX torrent link.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'rgsx+torrent'
            || strtolower((string)($parts['host'] ?? '')) !== 'download') {
            throw new RuntimeException('Torrent feed row ' . ($index + 1) . ' has a malformed RGSX torrent URL.');
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        $source = $query['source'] ?? '';
        if (!is_string($source) || !filter_var($source, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], true)
            || !ctype_digit((string)($query['index'] ?? ''))
            || (int)$query['index'] < 1
            || !is_string($query['path'] ?? null) || $query['path'] === ''
            || !ctype_digit((string)($query['size'] ?? ''))) {
            throw new RuntimeException('Torrent feed row ' . ($index + 1) . ' has invalid RGSX torrent parameters.');
        }
        rgsx_catalog_db_import_updated_at($modifiedAt, '');

        $key = strtolower($name);
        if (isset($seen[$key])) {
            throw new RuntimeException('Torrent feed contains duplicate game name: ' . $name . '.');
        }
        $seen[$key] = true;
        $rows[] = [$name, $url, $size, $modifiedAt];
    }

    return $rows;
}

function rgsx_torrent_sync_target_platform_id(): int
{
    $platform = rgsx_catalog_db_platform_by_id(RGSX_TORRENT_PLATFORM_ID);
    if ($platform === null) {
        throw new RuntimeException('Target torrent platform ID 144 does not exist in the catalog.');
    }
    if (strcasecmp(trim((string)$platform['platform_name']), 'Windows (Torrent)') !== 0
        || strcasecmp(trim((string)$platform['source']), 'Torrent') !== 0) {
        throw new RuntimeException('Platform ID 144 is not Windows (Torrent) / Torrent; refusing to modify it.');
    }
    return RGSX_TORRENT_PLATFORM_ID;
}

function rgsx_torrent_sync_run(): void
{
    $url = trim((string)(getenv('RGSX_TORRENT_FEED_URL') ?: RGSX_TORRENT_FEED_DEFAULT_URL));
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new InvalidArgumentException('RGSX_TORRENT_FEED_URL must be a valid HTTP(S) URL.');
    }

    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx-torrent-feed-sync.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open the torrent synchronization lock file.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Another torrent feed synchronization is already running.');
    }

    try {
        $rows = rgsx_torrent_sync_parse_feed(rgsx_torrent_sync_fetch($url));
        $platformId = rgsx_torrent_sync_target_platform_id();
        $counts = rgsx_catalog_db_sync_platform_games($platformId, $rows);
        $message = sprintf(
            'Synchronized platform Windows (Torrent, id %d): %d added, %d updated, %d deleted, %d unchanged.',
            $platformId,
            $counts['added'],
            $counts['updated'],
            $counts['deleted'],
            $counts['unchanged'],
        );
        printf("[%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $message);
        try {
            rgsx_sync_append_log(array_merge([$message], rgsx_sync_detail_messages($counts)), 'sync_torrent_feed.log');
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
        rgsx_torrent_sync_run();
    } catch (Throwable $exception) {
        $message = 'Torrent sync failed: ' . $exception->getMessage();
        try {
            rgsx_sync_append_log([$message], 'sync_torrent_feed.log');
        } catch (Throwable $logException) {
            $message .= '; log write failed: ' . $logException->getMessage();
        }
        fwrite(STDERR, '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $message . "\n");
        exit(1);
    }
}
