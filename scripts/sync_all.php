<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/sync_wsquashfs_export.php';
require_once __DIR__ . '/sync_torrent_feed.php';

function rgsx_sync_combined_step(string $label, callable $operation, string $logFilename): bool
{
    printf("[%s] Starting %s synchronization.\n", gmdate('Y-m-d\TH:i:s\Z'), $label);
    try {
        $operation();
        return true;
    } catch (Throwable $exception) {
        $message = $label . ' sync failed: ' . $exception->getMessage();
        try {
            rgsx_sync_append_log([$message], $logFilename);
        } catch (Throwable $logException) {
            $message .= '; log write failed: ' . $logException->getMessage();
        }
        fwrite(STDERR, '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $message . "\n");
        return false;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $oneFichierSucceeded = rgsx_sync_combined_step(
        '1Fichier',
        'rgsx_sync_run',
        'sync_wsquashfs_export.log'
    );
    $torrentSucceeded = rgsx_sync_combined_step(
        'Torrent',
        'rgsx_torrent_sync_run',
        'sync_torrent_feed.log'
    );

    if (!$oneFichierSucceeded || !$torrentSucceeded) {
        fwrite(STDERR, '[' . gmdate('Y-m-d\TH:i:s\Z') . "] Combined synchronization finished with errors.\n");
        exit(1);
    }

    printf("[%s] Combined synchronization completed (1Fichier, then Torrent).\n", gmdate('Y-m-d\TH:i:s\Z'));
}
