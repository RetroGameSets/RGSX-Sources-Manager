<?php

declare(strict_types=1);

define('RGSX_SOURCES_MANAGER_LIBRARY', true);
require_once __DIR__ . '/rgsx_sources_manager.php';

function rgsx_db_h(mixed $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rgsx_db_storage_platform_name(string $name, string $source): string {
  $name = trim($name);
  $source = trim($source);
  if ($name === '' || $source === '') {
    return $name;
  }
  $existingSource = rgsx_platform_source_from_name($name);
  if ($existingSource !== '') {
    $name = rgsx_platform_display_name($name, $existingSource);
  }
  return $name . ' (' . $source . ')';
}

function rgsx_db_platform_label(array $platform): string {
  $name = rgsx_platform_display_name($platform['platform_name'] ?? '', $platform['source'] ?? '');
  $source = trim((string)($platform['source'] ?? ''));
  return $source !== '' ? $name . ' [' . $source . ']' : $name;
}
function rgsx_db_flash(string $message = '', string $error = ''): void { 
  if ($message !== '') {
    $_SESSION['rgsx_db_flash_message'] = $message;
  }
  if ($error !== '') {
    $_SESSION['rgsx_db_flash_error'] = $error;
  }
}

function rgsx_db_redirect(string $tab = 'database', array $params = []): never {
  $params = array_merge(['tab' => $tab], $params);
  $query = http_build_query($params);
  header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? 'rgsx_database_manager.php') . ($query !== '' ? '?' . $query : ''));
  exit;
}

function rgsx_db_csrf_token(): string {
  if (!isset($_SESSION['rgsx_manager_csrf']) || !is_string($_SESSION['rgsx_manager_csrf'])) {
    $_SESSION['rgsx_manager_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['rgsx_manager_csrf'];
}

function rgsx_db_csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . rgsx_db_h(rgsx_db_csrf_token()) . '">';
}

function rgsx_db_store_image_upload(string $field): ?array {
  $upload = $_FILES[$field] ?? null;
  if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Le transfert de l’image a échoué.');
  }
  $name = basename((string)($upload['name'] ?? 'platform.png'));
  $tmp = (string)($upload['tmp_name'] ?? '');
  $data = $tmp !== '' ? @file_get_contents($tmp) : false;
  if ($data === false || $data === '') {
    throw new RuntimeException('L’image sélectionnée est vide ou illisible.');
  }
  return [
    'name' => $name,
    'data' => $data,
    'mime' => (string)($upload['type'] ?? guess_mime_from_ext($name)),
  ];
}

function rgsx_db_read_links_upload(): array {
  $upload = $_FILES['urls_file'] ?? null;
  if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return [];
  }
  if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Le fichier de liens n’a pas pu être lu.');
  }
  $contents = @file_get_contents((string)($upload['tmp_name'] ?? ''));
  if ($contents === false) {
    throw new RuntimeException('Le fichier de liens n’a pas pu être lu.');
  }
  return array_values(array_filter(array_map('trim', preg_split('/\R+/', $contents) ?: [])));
}

function rgsx_db_read_json_upload(): ?array {
  $upload = $_FILES['games_json_file'] ?? null;
  if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Le fichier JSON n’a pas pu être lu.');
  }
  $contents = @file_get_contents((string)($upload['tmp_name'] ?? ''));
  if ($contents === false) {
    throw new RuntimeException('Le fichier JSON n’a pas pu être lu.');
  }
  if (str_starts_with($contents, "\xEF\xBB\xBF")) {
    $contents = substr($contents, 3);
  }
  $decoded = json_decode($contents, true);
  if (!is_array($decoded)) {
    throw new RuntimeException('Le fichier JSON est invalide : ' . json_last_error_msg());
  }
  $sourceRows = is_array($decoded['games'] ?? null) ? $decoded['games'] : $decoded;
  $rows = [];
  foreach ($sourceRows as $sourceRow) {
    if (!is_array($sourceRow)) {
      continue;
    }
    $name = trim((string)($sourceRow[0] ?? $sourceRow['name'] ?? $sourceRow['title'] ?? ''));
    $url = trim((string)($sourceRow[1] ?? $sourceRow['url'] ?? $sourceRow['link'] ?? ''));
    $size = trim((string)($sourceRow[2] ?? $sourceRow['size'] ?? ''));
    if ($name !== '') {
      $rows[] = [$name, $url, $size];
    }
  }
  if (!$rows) {
    throw new RuntimeException('Le fichier JSON ne contient aucune ligne de jeu exploitable.');
  }
  return [
    'label' => 'Fichier JSON : ' . basename((string)($upload['name'] ?? 'jeux.json')),
    'rows' => $rows,
    'status' => 'ok',
  ];
}

function rgsx_db_scrape_extensions(string $raw): array {
  $default = '7z,bin,chd,cso,cue,exe,iso,nca,nes,nsp,nro,nsz,rom,rvz,sfc,smc,squashfs,torrent,wia,wsquashfs,xci,zip';
  $raw = trim($raw) !== '' ? $raw : $default;
  return array_values(array_filter(array_unique(array_map(static function(string $extension): string {
    return strtolower(trim($extension, " .\t\r\n"));
  }, preg_split('/[,;\s]+/', $raw) ?: []))));
}

function rgsx_db_scrape(array $post): array {
  $validExtensions = rgsx_db_scrape_extensions((string)($post['extensions'] ?? ''));
  $urls = trim((string)($post['urls'] ?? ''));
  $inputs = $urls !== '' ? array_values(array_filter(array_map('trim', preg_split('/\R+|,/', $urls) ?: []))) : [];
  $inputs = array_merge($inputs, rgsx_db_read_links_upload());
  $results = [];
  $directRows = [];
  $jsonResult = rgsx_db_read_json_upload();
  if ($jsonResult !== null) {
    $results[] = $jsonResult;
  }
  $scrapePassword = trim((string)($post['scrape_password'] ?? ''));
  $cookies = trim((string)($post['scrape_cookies'] ?? ''));
  $headers = $cookies !== '' ? ['Cookie: ' . $cookies] : [];
  if (!empty($post['remember_cookies']) && $cookies !== '') {
    $_SESSION['scrape_cookies'] = $cookies;
  }

  $torrentUpload = $_FILES['torrent_file'] ?? null;
  if (is_array($torrentUpload) && (int)($torrentUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $torrentName = basename((string)($torrentUpload['name'] ?? 'source.torrent'));
    $torrentBytes = @file_get_contents((string)($torrentUpload['tmp_name'] ?? ''));
    if ($torrentBytes === false || $torrentBytes === '') {
      throw new RuntimeException('Le fichier torrent est vide ou illisible.');
    }
    $torrentDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx_torrents_' . session_id();
    if (!is_dir($torrentDir)) {
      @mkdir($torrentDir, 0700, true);
    }
    $safeTorrentName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $torrentName);
    $torrentPath = $torrentDir . DIRECTORY_SEPARATOR . $safeTorrentName;
    @file_put_contents($torrentPath, $torrentBytes);
    $_SESSION['torrents'][] = ['name' => $safeTorrentName, 'tmp' => $torrentPath];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/data/rgsx_database_manager.php';
    $torrentUrl = $scheme . '://' . $host . $script . '?serve_torrent=' . rawurlencode($safeTorrentName);
    $entries = rgsx_extract_torrent_entries_from_bytes($torrentBytes, $torrentUrl);
    $rows = rgsx_torrent_entries_to_rows($entries, $torrentUrl, $validExtensions);
    if ($rows) {
      $results[] = ['label' => 'Fichier torrent : ' . $torrentName, 'rows' => $rows, 'status' => 'ok'];
    }
  }

  foreach ($inputs as $input) {
    $piped = parse_piped_source_row($input, $validExtensions);
    if (is_array($piped)) {
      $directRows[] = $piped;
      continue;
    }
    if (is_url($input) && is_torrent_url($input)) {
      try {
        $entries = rgsx_fetch_torrent_entries_php($input);
        $rows = rgsx_torrent_entries_to_rows($entries, $input, $validExtensions);
        if ($rows) {
          $results[] = ['label' => $input, 'rows' => $rows, 'status' => 'ok'];
        }
      } catch (Throwable $exception) {
        $results[] = ['label' => $input, 'rows' => [], 'status' => $exception->getMessage()];
      }
      continue;
    }
    $inputPath = parse_url($input, PHP_URL_PATH);
    $inputExtension = is_string($inputPath) ? strtolower((string)pathinfo($inputPath, PATHINFO_EXTENSION)) : '';
    if ($inputExtension !== '' && in_array($inputExtension, $validExtensions, true)) {
      $direct = parse_direct_source_url_row($input, $validExtensions, $validExtensions);
      if (is_array($direct)) {
        $directRows[] = $direct;
        continue;
      }
    }
    $isHtml = (bool)is_html_block($input);
    $normalized = $isHtml ? $input : normalize_archiveorg_scrape_url(normalize_url_like($input));
    $isUrl = is_url($normalized);
    if (!$isUrl && !$isHtml) {
      continue;
    }
    $label = build_scrape_input_label($normalized, count($results), $isHtml);
    try {
      if ($isUrl) {
        $parsedUrl = parse_url($normalized);
        $host = strtolower((string)($parsedUrl['host'] ?? ''));
        $path = (string)($parsedUrl['path'] ?? '');
        if ($scrapePassword === '' && strpos($host, '1fichier.com') !== false && str_starts_with($path, '/dir/')) {
          $scrapePassword = resolve_scrape_password_for_url($normalized);
        }
        if ($scrapePassword !== '' && strpos($host, '1fichier.com') !== false && str_starts_with($path, '/dir/')) {
          $response = http_fetch_1fichier_with_password($normalized, $scrapePassword, 45);
        } else {
          $response = http_fetch($normalized, 45, $headers);
        }
        $html = (string)($response['body'] ?? '');
        $status = (int)($response['status'] ?? 0);
        if ($html === '') {
          $results[] = ['label' => $label, 'rows' => [], 'status' => (string)($response['error'] ?? ('HTTP ' . $status))];
          continue;
        }
        $rows = parse_auto($html, $label, true, (string)($response['effective_url'] ?? $normalized), $validExtensions);
      } else {
        $status = 200;
        $rows = parse_auto($normalized, $label, false, '', $validExtensions);
      }
      $rows = is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row) && trim((string)($row[0] ?? '')) !== '')) : [];
      if ($rows) {
        $results[] = ['label' => $label, 'rows' => $rows, 'status' => 'ok'];
      } else {
        $results[] = ['label' => $label, 'rows' => [], 'status' => 'Aucun fichier reconnu'];
      }
    } catch (Throwable $exception) {
      $results[] = ['label' => $label, 'rows' => [], 'status' => $exception->getMessage()];
    }
  }

  if ($directRows) {
    array_unshift($results, ['label' => 'Liens directs', 'rows' => $directRows, 'status' => 'ok']);
  }
  $results = group_vimm_results($results);
  $_SESSION['rgsx_db_last_scrape'] = $results;
  $total = 0;
  foreach ($results as $result) {
    $total += count(is_array($result['rows'] ?? null) ? $result['rows'] : []);
  }
  return [$results, $total];
}

function rgsx_db_merge_result(int $platformId, int $resultIndex, string $mode): int {
  $results = $_SESSION['rgsx_db_last_scrape'] ?? [];
  $rows = $results[$resultIndex]['rows'] ?? [];
  if (!is_array($rows)) {
    throw new RuntimeException('Résultat de scrape introuvable.');
  }
  return rgsx_catalog_db_merge_games($platformId, $rows, $mode === 'append' ? 'append' : 'upsert');
}

function rgsx_db_merge_all(int $platformId, string $mode): int {
  $results = $_SESSION['rgsx_db_last_scrape'] ?? [];
  $rows = [];
  foreach ($results as $result) {
    if (is_array($result['rows'] ?? null)) {
      $rows = array_merge($rows, $result['rows']);
    }
  }
  return rgsx_catalog_db_merge_games($platformId, $rows, $mode === 'append' ? 'append' : 'upsert');
}

function rgsx_db_process_action(string $action): void {
  switch ($action) {
    case 'platform_save':
      $platformId = (int)($_POST['platform_id'] ?? 0);
      $current = $platformId > 0 ? rgsx_catalog_db_platform_by_id($platformId) : null;
      $imageName = trim((string)($_POST['platform_image'] ?? ($current['platform_image'] ?? '')));
      $selectedImage = trim((string)($_POST['platform_image_existing'] ?? ''));
      if ($selectedImage !== '') {
        $imageName = basename($selectedImage);
      }
      $image = rgsx_db_store_image_upload('platform_image_file');
      if (is_array($image)) {
        $imageName = $image['name'];
      }
      $requestedDisplayName = trim((string)($_POST['platform_name'] ?? ''));
      $requestedSource = trim((string)($_POST['source'] ?? ($current['source'] ?? '')));
      $requestedName = rgsx_db_storage_platform_name($requestedDisplayName, $requestedSource);
      $fileName = $current['file_name'] ?? '';
      if ($current && strcasecmp((string)($current['platform_name'] ?? ''), $requestedName) !== 0) {
        $fileName = '';
      }
      $savedId = rgsx_catalog_db_create_or_update_platform([
        'platform_name' => $requestedName,
        'source' => $requestedSource,
        'folder' => $_POST['folder'] ?? '',
        'platform_image' => $imageName,
        'file_name' => $fileName,
      ], $platformId > 0 ? $platformId : null);
      if (is_array($image)) {
        rgsx_catalog_db_upsert_platform_image($savedId, $image['name'], $image['data'], $image['mime']);
      }
      rgsx_db_flash($platformId > 0 ? 'Plateforme mise à jour.' : 'Plateforme créée.');
      rgsx_db_redirect('platforms');
      break;
    case 'platform_delete':
      rgsx_catalog_db_delete_platform_by_id((int)($_POST['platform_id'] ?? 0));
      rgsx_db_flash('Plateforme et ses jeux supprimés.');
      rgsx_db_redirect('platforms');
      break;
    case 'game_add':
      rgsx_catalog_db_add_game((int)($_POST['platform_id'] ?? 0), (string)($_POST['game_name'] ?? ''), (string)($_POST['game_url'] ?? ''), (string)($_POST['game_size'] ?? ''));
      rgsx_db_flash('Jeu ajouté ou mis à jour.');
      rgsx_db_redirect('games', ['platform_id' => (int)($_POST['platform_id'] ?? 0)]);
      break;
    case 'game_update':
      rgsx_catalog_db_update_game((int)($_POST['game_id'] ?? 0), (string)($_POST['game_name'] ?? ''), (string)($_POST['game_url'] ?? ''), (string)($_POST['game_size'] ?? ''));
      rgsx_db_flash('Jeu mis à jour.');
      rgsx_db_redirect('games', ['platform_id' => (int)($_POST['platform_id'] ?? 0)]);
      break;
    case 'game_delete':
      rgsx_catalog_db_delete_game((int)($_POST['game_id'] ?? 0));
      rgsx_db_flash('Jeu supprimé.');
      rgsx_db_redirect('games', ['platform_id' => (int)($_POST['platform_id'] ?? 0)]);
      break;
    case 'game_clear':
      rgsx_catalog_db()->prepare('DELETE FROM games WHERE platform_id = ?')->execute([(int)($_POST['platform_id'] ?? 0)]);
      rgsx_db_flash('Jeux de la plateforme supprimés.');
      rgsx_db_redirect('games', ['platform_id' => (int)($_POST['platform_id'] ?? 0)]);
      break;
    case 'scrape':
      [, $total] = rgsx_db_scrape($_POST);
      rgsx_db_flash('Scrape terminé : ' . $total . ' entrée(s) détectée(s).');
      rgsx_db_redirect('scrape');
      break;
    case 'scrape_merge':
      $count = rgsx_db_merge_result((int)($_POST['platform_id'] ?? 0), (int)($_POST['result_index'] ?? -1), (string)($_POST['merge_mode'] ?? 'upsert'));
      rgsx_db_flash($count . ' entrée(s) ajoutée(s) ou mise(s) à jour.');
      rgsx_db_redirect('scrape');
      break;
    case 'scrape_merge_all':
      $count = rgsx_db_merge_all((int)($_POST['platform_id'] ?? 0), (string)($_POST['merge_mode'] ?? 'upsert'));
      rgsx_db_flash($count . ' entrée(s) ajoutée(s) ou mise(s) à jour.');
      rgsx_db_redirect('scrape');
      break;
    case 'scrape_clear':
      unset($_SESSION['rgsx_db_last_scrape']);
      rgsx_db_flash('Résultats de scrape effacés.');
      rgsx_db_redirect('scrape');
      break;
  }
}

function rgsx_db_login_page(string $error = ''): never {
  $config = rgsx_mysql_config();
  $safeError = $error !== '' ? '<div class="alert alert-danger">' . rgsx_db_h($error) . '</div>' : '';
  echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion RGSX Manager</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f4f7f5}.login{max-width:480px;margin:12vh auto}.panel{background:#fff;border:1px solid #d7e2df;border-radius:14px;box-shadow:0 12px 35px rgba(23,33,43,.08)}</style></head><body><main class="login px-3"><section class="panel p-4"><div class="text-uppercase small fw-semibold text-secondary">RGSX / catalogue central</div><h1 class="h3 mb-2">Connexion MariaDB</h1><p class="text-secondary">Mot de passe du compte administrateur du Manager.</p>' . $safeError . '<form method="post">' . rgsx_db_csrf_field() . '<input type="hidden" name="action" value="manager_login"><label class="form-label" for="db_password">Mot de passe</label><input class="form-control mb-3" id="db_password" name="db_password" type="password" autocomplete="current-password" required autofocus><button class="btn btn-primary w-100">Se connecter</button></form><div class="small text-secondary mt-3">Base : <code>' . rgsx_db_h($config['database']) . '</code> · Utilisateur : <code>' . rgsx_db_h($config['user']) . '</code></div></section></main></body></html>';
  exit;
}

function rgsx_db_authenticate_manager(): void {
  if (($_POST['action'] ?? '') === 'manager_logout') {
    unset($_SESSION['rgsx_manager_mysql_password']);
    $GLOBALS['rgsx_catalog_db_pdo'] = null;
    session_regenerate_id(true);
    rgsx_db_redirect('database');
  }
  if (rgsx_mysql_enabled()) {
    return;
  }
  if (($_POST['action'] ?? '') === 'manager_login') {
    $password = (string)($_POST['db_password'] ?? '');
    if ($password !== '') {
      $_SESSION['rgsx_manager_mysql_password'] = $password;
      try {
        rgsx_mysql_pdo()->query('SELECT 1');
        session_regenerate_id(true);
        rgsx_db_redirect('database');
      } catch (Throwable $exception) {
        $config = rgsx_mysql_config();
        rgsx_debug_log('manager_login_failed', [
          'type' => get_class($exception),
          'code' => (string)$exception->getCode(),
          'message' => $exception->getMessage(),
          'host' => $config['host'],
          'database' => $config['database'],
          'user' => $config['user'],
        ]);
        unset($_SESSION['rgsx_manager_mysql_password']);
        $GLOBALS['rgsx_catalog_db_pdo'] = null;
        rgsx_db_login_page('Connexion refusée. Vérifie le serveur, le compte et le mot de passe MariaDB.');
      }
    }
    rgsx_db_login_page('Le mot de passe est obligatoire.');
  }
  rgsx_db_login_page();
}

$csrfToken = rgsx_db_csrf_token();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $submittedToken = $_POST['csrf_token'] ?? '';
  if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid request token. Reload the page and try again.';
    exit;
  }
}

rgsx_db_authenticate_manager();

if (isset($_GET['preview_db_image'])) {
  try {
    $stmt = rgsx_catalog_db()->prepare("SELECT file_name, mime_type, data FROM platform_assets WHERE platform_id = ? AND asset_type = 'platform_image'");
    $stmt->execute([(int)$_GET['preview_db_image']]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
      http_response_code(404);
      exit;
    }
    header('Content-Type: ' . ((string)$row['mime_type'] ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');
    echo $row['data'];
  } catch (Throwable) {
    http_response_code(404);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    rgsx_db_process_action((string)($_POST['action'] ?? ''));
  } catch (Throwable $exception) {
    rgsx_db_flash('', 'Erreur : ' . $exception->getMessage());
    rgsx_db_redirect((string)($_POST['tab'] ?? 'database'));
  }
}

$message = (string)($_SESSION['rgsx_db_flash_message'] ?? '');
$error = (string)($_SESSION['rgsx_db_flash_error'] ?? '');
unset($_SESSION['rgsx_db_flash_message'], $_SESSION['rgsx_db_flash_error']);
$activeTab = (string)($_GET['tab'] ?? 'database');
if (!in_array($activeTab, ['database', 'platforms', 'games', 'scrape'], true)) {
  $activeTab = 'database';
}
$dbReady = false;
$mysqlMode = true;
$dbPath = rgsx_mysql_display_uri();
$dbError = '';
$platformsAll = [];
try {
  rgsx_catalog_db();
  $dbReady = true;
  $platformsAll = rgsx_catalog_db_platform_summary('', 1000, 0);
} catch (Throwable $exception) {
  $dbError = $exception->getMessage();
}

$platformSearch = trim((string)($_GET['platform_search'] ?? ''));
$platformPage = max(1, (int)($_GET['platform_page'] ?? 1));
$platformPerPage = 50;
$platformTotal = $dbReady ? rgsx_catalog_db_platform_count($platformSearch) : 0;
$platformPages = max(1, (int)ceil($platformTotal / $platformPerPage));
$platformPage = min($platformPage, $platformPages);
$platformRows = $dbReady ? rgsx_catalog_db_platform_summary($platformSearch, $platformPerPage, ($platformPage - 1) * $platformPerPage) : [];
$selectedPlatformId = (int)($_GET['platform_id'] ?? ($platformsAll[0]['id'] ?? 0));
$selectedPlatform = $dbReady && $selectedPlatformId > 0 ? rgsx_catalog_db_platform_by_id($selectedPlatformId) : null;
$gameSearch = trim((string)($_GET['game_search'] ?? ''));
$gamePage = max(1, (int)($_GET['game_page'] ?? 1));
$gameSort = (string)($_GET['game_sort'] ?? ($_SESSION['rgsx_db_game_sort'] ?? 'name'));
$gameSortOptions = ['name' => 'Nom', 'url' => 'URL', 'size' => 'Taille', 'updated_at' => 'Mis à jour'];
if (!array_key_exists($gameSort, $gameSortOptions)) {
  $gameSort = 'name';
}
$gameDirection = strtolower((string)($_GET['game_order'] ?? ($_SESSION['rgsx_db_game_order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
$_SESSION['rgsx_db_game_sort'] = $gameSort;
$_SESSION['rgsx_db_game_order'] = $gameDirection;
$gamePerPage = 50;
$gameTotal = $selectedPlatform ? rgsx_catalog_db_games_count_by_platform($selectedPlatformId, $gameSearch) : 0;
$gamePages = max(1, (int)ceil($gameTotal / $gamePerPage));
$gamePage = min($gamePage, $gamePages);
$gameRows = $selectedPlatform ? rgsx_catalog_db_games_page($selectedPlatformId, $gameSearch, $gamePerPage, ($gamePage - 1) * $gamePerPage, $gameSort, $gameDirection) : [];
$gameSortUrl = static function (string $sort) use ($selectedPlatformId, $gameSearch, $gameSort, $gameDirection): string {
  $direction = $sort === $gameSort && $gameDirection === 'asc' ? 'desc' : 'asc';
  return '?' . http_build_query([
    'tab' => 'games',
    'platform_id' => $selectedPlatformId,
    'game_search' => $gameSearch,
    'game_sort' => $sort,
    'game_order' => $direction,
  ]);
};
$lastScrape = is_array($_SESSION['rgsx_db_last_scrape'] ?? null) ? $_SESSION['rgsx_db_last_scrape'] : [];
$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088') . ($_SERVER['SCRIPT_NAME'] ?? '/data/rgsx_database_manager.php');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RGSX MariaDB Sources Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root { --rgsx-ink:#17212b; --rgsx-blue:#176b87; --rgsx-mint:#d8f3e8; --rgsx-paper:#f4f7f5; }
    body { background: radial-gradient(circle at top right, #e2f4ef 0, transparent 32rem), var(--rgsx-paper); color:var(--rgsx-ink); }
    .shell { max-width: 1500px; }
    .brand { letter-spacing:.04em; }
    .status-bar, .panel { border:1px solid #d7e2df; box-shadow:0 12px 35px rgba(23,33,43,.06); }
    .status-bar { background:#fff; border-radius:14px; }
    .panel { background:rgba(255,255,255,.92); border-radius:14px; }
    .nav-pills .nav-link { color:#42606a; }
    .nav-pills .nav-link.active { background:var(--rgsx-blue); }
    .stat { border-left:4px solid #65b89b; background:#f7fbf9; }
    .url-cell { max-width:440px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .table > :not(caption) > * > * { vertical-align:middle; }
    .result-card { border:1px solid #d8e5e1; border-radius:12px; background:#fff; }
    .result-rows { max-height:260px; overflow:auto; }
    .sticky-tools { position:sticky; top:12px; z-index:5; }
  </style>
</head>
<body>
<main class="container-fluid shell py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <div class="text-uppercase small fw-semibold text-secondary">RGSX / catalogue central</div>
      <h1 class="brand h2 mb-1">MariaDB Sources Manager</h1>
      <p class="text-secondary mb-0">Modifie directement le catalogue actif et fusionne les nouveaux résultats de scraping.</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="tab" value="<?php echo rgsx_db_h($activeTab); ?>">
      <select name="lang" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Langue">
        <option value="fr">FR</option><option value="en">EN</option>
      </select>
    </form>
  </div>

  <section class="status-bar p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="d-flex align-items-center gap-2">
        <span class="badge <?php echo $dbReady ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo $dbReady ? 'BASE OUVERTE' : 'BASE INDISPONIBLE'; ?></span>
        <strong><?php echo rgsx_db_h(basename($dbPath)); ?></strong>
        <span class="text-secondary small"><?php echo rgsx_db_h($dbPath); ?></span>
      </div>
      <?php if ($mysqlMode): ?><form method="post" class="m-0"><input type="hidden" name="action" value="manager_logout"><button class="btn btn-sm btn-outline-secondary">Se déconnecter</button></form><?php endif; ?>
      <?php if ($dbReady): ?>
        <div class="d-flex flex-wrap gap-2 small">
          <span class="stat px-3 py-2">Plateformes <strong><?php echo count($platformsAll); ?></strong></span>
          <span class="stat px-3 py-2">Jeux <strong><?php echo array_sum(array_map(static fn(array $row): int => (int)$row['game_count'], $platformsAll)); ?></strong></span>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($message !== ''): ?><div class="alert alert-success"><?php echo rgsx_db_h($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo rgsx_db_h($error); ?></div><?php endif; ?>
  <?php if ($dbError !== '' && $error === ''): ?><div class="alert alert-warning">Base non ouverte : <?php echo rgsx_db_h($dbError); ?></div><?php endif; ?>

  <nav class="nav nav-pills gap-2 mb-3">
    <?php foreach (['database' => 'Base', 'platforms' => 'Plateformes', 'games' => 'Jeux', 'scrape' => 'Scraper'] as $tab => $label): ?>
      <a class="nav-link <?php echo $activeTab === $tab ? 'active' : ''; ?>" href="?tab=<?php echo $tab; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($activeTab === 'database'): ?>
    <section class="panel p-4">
      <h2 class="h4">Base MariaDB centrale</h2>
      <p class="text-secondary">Le Manager est connecté à MariaDB. Les plateformes, jeux et images sont modifiés directement dans la base centrale.</p>
      <dl class="row mb-0">
        <dt class="col-sm-3">Serveur</dt><dd class="col-sm-9"><code><?php echo rgsx_db_h(rgsx_mysql_config()['host']); ?></code></dd>
        <dt class="col-sm-3">Base</dt><dd class="col-sm-9"><code><?php echo rgsx_db_h(rgsx_mysql_config()['database']); ?></code></dd>
        <dt class="col-sm-3">Utilisateur</dt><dd class="col-sm-9"><code><?php echo rgsx_db_h(rgsx_mysql_config()['user']); ?></code></dd>
      </dl>
    </section>
  <?php endif; ?>

  <?php if ($activeTab === 'platforms'): ?>
    <section class="panel p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="h4 mb-1">Plateformes</h2><div class="text-secondary small">Métadonnées, images et compteurs lus directement depuis MariaDB.</div></div>
        <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="platforms"><input class="form-control" name="platform_search" value="<?php echo rgsx_db_h($platformSearch); ?>" placeholder="Rechercher..."><button class="btn btn-outline-secondary">Filtrer</button></form>
      </div>
      <div class="panel p-3 mb-4" style="box-shadow:none;background:#f8fbfa">
        <h3 class="h6">Ajouter une plateforme</h3>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="platform_save"><input type="hidden" name="tab" value="platforms">
          <div class="col-lg-3"><label class="form-label small">Nom</label><input class="form-control" name="platform_name" required></div>
          <div class="col-lg-2"><label class="form-label small">Source</label><input class="form-control" name="source" placeholder="Archive, Vimms..."></div>
          <div class="col-lg-2"><label class="form-label small">Dossier</label><input class="form-control" name="folder" required></div>
          <div class="col-lg-3"><label class="form-label small">Image plateforme</label><input class="form-control" type="file" name="platform_image_file" accept="image/*"></div>
          <div class="col-lg-2"><label class="form-label small">Nom image existante</label><input class="form-control" name="platform_image"></div>
          <div class="col-lg-2"><button class="btn btn-primary w-100">Ajouter</button></div>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>Plateforme</th><th>Source</th><th>Dossier</th><th>Image</th><th class="text-end">Jeux</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($platformRows as $row): ?>
            <tr>
              <td><strong><?php echo rgsx_db_h(rgsx_platform_display_name($row['platform_name'], $row['source'] ?? '')); ?></strong><div class="small text-secondary">ID <?php echo (int)$row['id']; ?></div></td>
              <td><?php echo rgsx_db_h($row['source'] ?? ''); ?></td>
              <td><?php echo rgsx_db_h($row['folder']); ?></td>
              <td><?php if ((string)$row['platform_image'] !== ''): ?><a href="?preview_db_image=<?php echo (int)$row['id']; ?>" target="_blank"><?php echo rgsx_db_h($row['platform_image']); ?></a><?php else: ?>-<?php endif; ?></td>
              <td class="text-end"><a href="?tab=games&platform_id=<?php echo (int)$row['id']; ?>" class="badge text-bg-light text-decoration-none"><?php echo (int)$row['game_count']; ?></a></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="?tab=games&platform_id=<?php echo (int)$row['id']; ?>">Jeux</a> <button class="btn btn-sm btn-outline-secondary" type="button" onclick="document.getElementById('edit-<?php echo (int)$row['id']; ?>').classList.toggle('d-none')">Modifier</button> <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette plateforme et ses jeux ?')"><input type="hidden" name="action" value="platform_delete"><input type="hidden" name="tab" value="platforms"><input type="hidden" name="platform_id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-sm btn-outline-danger">Supprimer</button></form></td>
            </tr>
            <tr id="edit-<?php echo (int)$row['id']; ?>" class="d-none"><td colspan="6">
              <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end bg-light p-3 rounded">
                <input type="hidden" name="action" value="platform_save"><input type="hidden" name="tab" value="platforms"><input type="hidden" name="platform_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="platform_image" value="<?php echo rgsx_db_h($row['platform_image']); ?>">
                <div class="col-lg-3"><label class="form-label small">Nom</label><input class="form-control" name="platform_name" value="<?php echo rgsx_db_h(rgsx_platform_display_name($row['platform_name'], $row['source'] ?? '')); ?>" required></div>
                <div class="col-lg-2"><label class="form-label small">Source</label><input class="form-control" name="source" value="<?php echo rgsx_db_h($row['source'] ?? ''); ?>"></div>
                <div class="col-lg-2"><label class="form-label small">Dossier</label><input class="form-control" name="folder" value="<?php echo rgsx_db_h($row['folder']); ?>" required></div>
                <div class="col-lg-3"><label class="form-label small">Remplacer l’image</label><input class="form-control" type="file" name="platform_image_file" accept="image/*"></div>
                <div class="col-lg-2"><label class="form-label small">Choisir image existante</label><input class="form-control" name="platform_image_existing" placeholder="Nom de fichier"></div>
                <div class="col-lg-2"><button class="btn btn-success w-100">Enregistrer</button></div>
              </form>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center"><span class="small text-secondary"><?php echo $platformTotal ? (($platformPage - 1) * $platformPerPage + 1) . ' à ' . min($platformPage * $platformPerPage, $platformTotal) . ' sur ' . $platformTotal : 'Aucune plateforme'; ?></span><div class="btn-group"><a class="btn btn-sm btn-outline-secondary <?php echo $platformPage <= 1 ? 'disabled' : ''; ?>" href="?tab=platforms&platform_search=<?php echo urlencode($platformSearch); ?>&platform_page=<?php echo max(1, $platformPage - 1); ?>">Précédent</a><span class="btn btn-sm btn-secondary">Page <?php echo $platformPage; ?>/<?php echo $platformPages; ?></span><a class="btn btn-sm btn-outline-secondary <?php echo $platformPage >= $platformPages ? 'disabled' : ''; ?>" href="?tab=platforms&platform_search=<?php echo urlencode($platformSearch); ?>&platform_page=<?php echo min($platformPages, $platformPage + 1); ?>">Suivant</a></div></div>
    </section>
  <?php endif; ?>

  <?php if ($activeTab === 'games'): ?>
    <section class="panel p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h4 mb-1">Jeux</h2><div class="text-secondary small">Ajout, mise à jour et suppression d’une ligne sans recharger tout le catalogue.</div></div><form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="games"><select class="form-select" name="platform_id" onchange="this.form.submit()"><option value="">Choisir une plateforme</option><?php foreach ($platformsAll as $platform): ?><option value="<?php echo (int)$platform['id']; ?>" <?php echo $selectedPlatformId === (int)$platform['id'] ? 'selected' : ''; ?>><?php echo rgsx_db_h(rgsx_db_platform_label($platform)); ?> (<?php echo (int)$platform['game_count']; ?>)</option><?php endforeach; ?></select><input class="form-control" name="game_search" value="<?php echo rgsx_db_h($gameSearch); ?>" placeholder="Rechercher"><button class="btn btn-outline-secondary">Filtrer</button></form></div>
      <?php if ($selectedPlatform): ?>
        <form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="tab" value="games"><input type="hidden" name="platform_id" value="<?php echo $selectedPlatformId; ?>"><input type="hidden" name="game_search" value="<?php echo rgsx_db_h($gameSearch); ?>"><div class="col-sm-4 col-md-3"><label class="form-label small mb-1" for="game-sort">Trier par</label><select class="form-select form-select-sm" id="game-sort" name="game_sort"><?php foreach ($gameSortOptions as $sortKey => $sortLabel): ?><option value="<?php echo rgsx_db_h($sortKey); ?>" <?php echo $gameSort === $sortKey ? 'selected' : ''; ?>><?php echo rgsx_db_h($sortLabel); ?></option><?php endforeach; ?></select></div><div class="col-sm-4 col-md-3"><label class="form-label small mb-1" for="game-order">Ordre</label><select class="form-select form-select-sm" id="game-order" name="game_order"><option value="asc" <?php echo $gameDirection === 'asc' ? 'selected' : ''; ?>>Croissant</option><option value="desc" <?php echo $gameDirection === 'desc' ? 'selected' : ''; ?>>Décroissant</option></select></div><div class="col-sm-4 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Trier</button></div></form>
        <div class="panel p-3 mb-3" style="box-shadow:none;background:#f8fbfa"><div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h6 mb-0">Ajouter dans <?php echo rgsx_db_h(rgsx_db_platform_label($selectedPlatform)); ?></h3><form method="post" onsubmit="return confirm('Supprimer tous les jeux de cette plateforme ?')"><input type="hidden" name="action" value="game_clear"><input type="hidden" name="tab" value="games"><input type="hidden" name="platform_id" value="<?php echo $selectedPlatformId; ?>"><button class="btn btn-sm btn-outline-danger">Vider la plateforme</button></form></div><form method="post" class="row g-2 align-items-end"><input type="hidden" name="action" value="game_add"><input type="hidden" name="tab" value="games"><input type="hidden" name="platform_id" value="<?php echo $selectedPlatformId; ?>"><div class="col-lg-4"><label class="form-label small">Nom</label><input class="form-control" name="game_name" required></div><div class="col-lg-5"><label class="form-label small">URL</label><input class="form-control" name="game_url"></div><div class="col-lg-2"><label class="form-label small">Taille</label><input class="form-control" name="game_size"></div><div class="col-lg-1"><button class="btn btn-primary w-100">Ajouter</button></div></form></div>
        <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Nom</th><th>URL</th><th>Taille</th><th>Mis à jour</th><th></th></tr></thead><tbody><?php foreach ($gameRows as $game): ?><tr><td><?php echo rgsx_db_h($game['name']); ?></td><td class="url-cell" title="<?php echo rgsx_db_h($game['url']); ?>"><?php echo rgsx_db_h($game['url']); ?></td><td><?php echo rgsx_db_h($game['size']); ?></td><td class="small text-secondary"><?php echo rgsx_db_h($game['updated_at']); ?></td><td class="text-end"><details><summary class="btn btn-sm btn-outline-secondary">Modifier</summary><form method="post" class="row g-2 mt-2 text-start"><input type="hidden" name="action" value="game_update"><input type="hidden" name="tab" value="games"><input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>"><input type="hidden" name="platform_id" value="<?php echo $selectedPlatformId; ?>"><div class="col-12"><input class="form-control form-control-sm" name="game_name" value="<?php echo rgsx_db_h($game['name']); ?>" required></div><div class="col-12"><input class="form-control form-control-sm" name="game_url" value="<?php echo rgsx_db_h($game['url']); ?>"></div><div class="col-8"><input class="form-control form-control-sm" name="game_size" value="<?php echo rgsx_db_h($game['size']); ?>"></div><div class="col-4"><button class="btn btn-sm btn-success w-100">Sauver</button></div></form></details> <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette ligne ?')"><input type="hidden" name="action" value="game_delete"><input type="hidden" name="tab" value="games"><input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>"><input type="hidden" name="platform_id" value="<?php echo $selectedPlatformId; ?>"><button class="btn btn-sm btn-outline-danger">Supprimer</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="d-flex justify-content-between align-items-center"><span class="small text-secondary"><?php echo $gameTotal ? (($gamePage - 1) * $gamePerPage + 1) . ' à ' . min($gamePage * $gamePerPage, $gameTotal) . ' sur ' . $gameTotal : 'Aucun jeu'; ?></span><div class="btn-group"><a class="btn btn-sm btn-outline-secondary <?php echo $gamePage <= 1 ? 'disabled' : ''; ?>" href="?tab=games&platform_id=<?php echo $selectedPlatformId; ?>&game_search=<?php echo urlencode($gameSearch); ?>&game_page=<?php echo max(1, $gamePage - 1); ?>">Précédent</a><span class="btn btn-sm btn-secondary">Page <?php echo $gamePage; ?>/<?php echo $gamePages; ?></span><a class="btn btn-sm btn-outline-secondary <?php echo $gamePage >= $gamePages ? 'disabled' : ''; ?>" href="?tab=games&platform_id=<?php echo $selectedPlatformId; ?>&game_search=<?php echo urlencode($gameSearch); ?>&game_page=<?php echo min($gamePages, $gamePage + 1); ?>">Suivant</a></div></div>
      <?php else: ?><div class="alert alert-info">Ouvre une base contenant des plateformes pour commencer.</div><?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($activeTab === 'scrape'): ?>
    <section class="panel p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h4 mb-1">Scraper et fusionner</h2><div class="text-secondary">Les résultats restent en attente jusqu’à ce que tu choisisses leur plateforme cible.</div></div><?php if ($lastScrape): ?><form method="post"><input type="hidden" name="action" value="scrape_clear"><input type="hidden" name="tab" value="scrape"><button class="btn btn-sm btn-outline-secondary">Effacer les résultats</button></form><?php endif; ?></div>
      <form method="post" enctype="multipart/form-data" class="row g-3 mb-4"><input type="hidden" name="action" value="scrape"><input type="hidden" name="tab" value="scrape"><div class="col-lg-8"><label class="form-label">URLs, HTML ou lignes nom|title_id|url</label><textarea class="form-control" name="urls" rows="7" placeholder="Une URL par ligne"></textarea><div class="form-text">Les pages Archive.org, 1fichier, Myrient, EdgeEmu, Vimm et les torrents restent pris en charge.</div></div><div class="col-lg-4"><label class="form-label">Fichier JSON de jeux</label><input type="file" class="form-control mb-3" name="games_json_file" accept=".json,application/json"><label class="form-label">Fichier de liens</label><input type="file" class="form-control mb-3" name="urls_file" accept=".txt,.csv,.list,text/plain"><label class="form-label">Fichier torrent local</label><input type="file" class="form-control mb-3" name="torrent_file" accept=".torrent,application/x-bittorrent"><label class="form-label">Extensions à garder</label><input class="form-control mb-3" name="extensions" value="7z,bin,chd,cso,cue,exe,iso,nca,nes,nsp,nro,rom,rvz,sfc,smc,squashfs,wia,wsquashfs,xci,zip"><label class="form-label">Mot de passe 1fichier</label><input class="form-control mb-3" type="password" name="scrape_password"><label class="form-label">Cookies optionnels</label><textarea class="form-control" name="scrape_cookies" rows="2"><?php echo rgsx_db_h($_SESSION['scrape_cookies'] ?? ''); ?></textarea><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remember_cookies" id="remember-cookies"><label class="form-check-label" for="remember-cookies">Mémoriser pour cette session</label></div></div><div class="col-12"><button class="btn btn-primary">Lancer le scrape</button></div></form>
      <?php if ($lastScrape): ?>
        <div class="d-flex flex-wrap gap-2 mb-3"><?php $scrapeTotal = 0; foreach ($lastScrape as $result) { $scrapeTotal += count($result['rows'] ?? []); } ?><span class="badge text-bg-light"><?php echo count($lastScrape); ?> source(s)</span><span class="badge text-bg-success"><?php echo $scrapeTotal; ?> entrée(s)</span></div>
        <div class="result-card p-3 mb-3"><form method="post" class="row g-2 align-items-end"><input type="hidden" name="action" value="scrape_merge_all"><input type="hidden" name="tab" value="scrape"><div class="col-lg-5"><label class="form-label small">Fusionner tous les résultats dans</label><select class="form-select" name="platform_id" required><option value="">Choisir une plateforme</option><?php foreach ($platformsAll as $platform): ?><option value="<?php echo (int)$platform['id']; ?>"><?php echo rgsx_db_h(rgsx_db_platform_label($platform)); ?></option><?php endforeach; ?></select></div><div class="col-lg-3"><label class="form-label small">Mode</label><select class="form-select" name="merge_mode"><option value="upsert">Ajouter et mettre à jour</option><option value="append">Ajouter uniquement les nouveaux</option></select></div><div class="col-lg-4"><button class="btn btn-success w-100">Fusionner tous les résultats</button></div></form></div>
        <?php foreach ($lastScrape as $index => $result): ?><article class="result-card p-3 mb-3"><div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?php echo rgsx_db_h($result['label'] ?? 'Source'); ?></strong><div class="small text-secondary"><?php echo count($result['rows'] ?? []); ?> entrée(s) · <?php echo rgsx_db_h($result['status'] ?? ''); ?></div></div><form method="post" class="d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="action" value="scrape_merge"><input type="hidden" name="tab" value="scrape"><input type="hidden" name="result_index" value="<?php echo (int)$index; ?>"><select class="form-select form-select-sm" name="platform_id" required><option value="">Plateforme cible</option><?php foreach ($platformsAll as $platform): ?><option value="<?php echo (int)$platform['id']; ?>"><?php echo rgsx_db_h(rgsx_db_platform_label($platform)); ?></option><?php endforeach; ?></select><select class="form-select form-select-sm" name="merge_mode"><option value="upsert">Ajouter / mettre à jour</option><option value="append">Ajouter seulement</option></select><button class="btn btn-sm btn-success">Fusionner</button></form></div><div class="result-rows mt-3"><table class="table table-sm mb-0"><thead><tr><th>Nom</th><th>URL</th><th>Taille</th></tr></thead><tbody><?php foreach (array_slice($result['rows'] ?? [], 0, 50) as $row): ?><tr><td><?php echo rgsx_db_h($row[0] ?? ''); ?></td><td class="url-cell" title="<?php echo rgsx_db_h($row[1] ?? ''); ?>"><?php echo rgsx_db_h($row[1] ?? ''); ?></td><td><?php echo rgsx_db_h($row[2] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table><?php if (count($result['rows'] ?? []) > 50): ?><div class="small text-secondary p-2">Aperçu limité aux 50 premières entrées.</div><?php endif; ?></div></article><?php endforeach; ?>
      <?php else: ?><div class="alert alert-light border">Aucun résultat en attente. Lance un scrape pour remplir cette zone.</div><?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<script>
  const managerCsrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  document.querySelectorAll('form[method="post"]').forEach((form) => {
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value = managerCsrfToken;
    form.appendChild(token);
  });
</script>
</body>
</html>
