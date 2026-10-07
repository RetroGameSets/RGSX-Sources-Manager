<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function catalog_api_response(int $status, array $payload): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function catalog_api_env_values(): array {
  static $values = null;
  if (is_array($values)) {
    return $values;
  }
  $values = [];
  $path = trim((string)getenv('RGSX_CATALOG_API_ENV_FILE'));
  if ($path === '' || !is_file($path) || !is_readable($path)) {
    return $values;
  }
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }
    if (str_starts_with($line, 'export ')) {
      $line = trim(substr($line, 7));
    }
    $separator = strpos($line, '=');
    if ($separator === false) {
      continue;
    }
    $key = trim(substr($line, 0, $separator));
    $value = trim(substr($line, $separator + 1));
    if ($key === '') {
      continue;
    }
    if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
      $value = substr($value, 1, -1);
    }
    $values[$key] = $value;
  }
  return $values;
}

function catalog_api_setting(string $name, string $fallbackName = '', string $default = ''): string {
  $runtime = getenv($name);
  if ($runtime !== false && trim((string)$runtime) !== '') {
    return trim((string)$runtime);
  }
  $values = catalog_api_env_values();
  foreach ([$name, $fallbackName] as $key) {
    if ($key !== '' && isset($values[$key]) && trim((string)$values[$key]) !== '') {
      return trim((string)$values[$key]);
    }
  }
  return $default;
}

function catalog_api_rate_limit(): void {
  $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $rateDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx-catalog-api-rate';
  if (!is_dir($rateDirectory) && !@mkdir($rateDirectory, 0700, true) && !is_dir($rateDirectory)) {
    error_log('RGSX catalog API: unable to create rate-limit directory');
    return;
  }
  $path = $rateDirectory . DIRECTORY_SEPARATOR . hash('sha256', $clientIp) . '.json';
  $handle = @fopen($path, 'c+');
  if ($handle === false) {
    error_log('RGSX catalog API: unable to open rate-limit state');
    return;
  }
  try {
    if (!flock($handle, LOCK_EX)) {
      return;
    }
    $raw = stream_get_contents($handle);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    $now = time();
    if (!is_array($state) || (int)($state['window'] ?? 0) !== intdiv($now, 60)) {
      $state = ['window' => intdiv($now, 60), 'count' => 0];
    }
    $state['count'] = (int)$state['count'] + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    if ($state['count'] > 600) {
      header('Retry-After: 60');
      catalog_api_response(429, ['success' => false, 'error' => 'rate_limited']);
    }
  } finally {
    fclose($handle);
  }
}

function catalog_api_db(): PDO {
  if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO MySQL is not enabled.');
  }
  $host = catalog_api_setting('RGSX_CATALOG_DB_HOST', '', 'localhost');
  $port = max(1, min(65535, (int)catalog_api_setting('RGSX_CATALOG_DB_PORT', '', '3306')));
  $database = catalog_api_setting('RGSX_CATALOG_DB_NAME');
  $user = catalog_api_setting('RGSX_CATALOG_DB_USER');
  $password = catalog_api_setting('RGSX_CATALOG_DB_PASSWORD');
  $sslCa = catalog_api_setting('RGSX_CATALOG_DB_SSL_CA');
  if ($database === '' || $user === '' || $password === '') {
    throw new RuntimeException('Read-only catalog database credentials are not configured.');
  }
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
  ];
  if ($sslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
      $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
  }
  $pdo = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $user,
    $password,
    $options
  );
  $pdo->exec("SET SESSION time_zone = '+00:00'");
  return $pdo;
}

function catalog_api_int(string $key, int $default, int $minimum, int $maximum): int {
  $raw = $_GET[$key] ?? null;
  if ($raw === null || $raw === '') {
    return $default;
  }
  if (!is_scalar($raw) || !preg_match('/^\d+$/', (string)$raw)) {
    catalog_api_response(400, ['success' => false, 'error' => 'invalid_' . $key]);
  }
  return max($minimum, min($maximum, (int)$raw));
}

function catalog_api_entity(string $entity): array {
  $definitions = [
    'platforms' => [
      'table' => 'platforms',
      'select' => 'id, platform_name, source, file_name, folder, platform_image, sort_order, created_at, updated_at',
    ],
    'games' => [
      'table' => 'games',
      'select' => 'id, platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at',
    ],
    'platform_assets' => [
      'table' => 'platform_assets',
      'select' => 'id, platform_id, asset_type, file_name, mime_type, data, metadata_json, created_at, updated_at',
    ],
  ];
  if (!isset($definitions[$entity])) {
    catalog_api_response(400, ['success' => false, 'error' => 'invalid_entity']);
  }
  return $definitions[$entity];
}

function catalog_api_encode_row(string $entity, array $row): array {
  if ($entity === 'platform_assets') {
    $blob = $row['data'] ?? '';
    if (is_resource($blob)) {
      $blob = stream_get_contents($blob);
    }
    $blob = is_string($blob) ? $blob : '';
    if (strlen($blob) > 4 * 1024 * 1024) {
      catalog_api_response(413, ['success' => false, 'error' => 'platform_asset_too_large']);
    }
    unset($row['data']);
    $row['data_base64'] = base64_encode($blob);
  }
  return $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  catalog_api_response(405, ['success' => false, 'error' => 'method_not_allowed']);
}
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$isHttps) {
  catalog_api_response(426, ['success' => false, 'error' => 'https_required']);
}
header('Strict-Transport-Security: max-age=31536000');

catalog_api_rate_limit();
$action = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
if (!in_array($action, ['manifest', 'snapshot', 'changes'], true)) {
  catalog_api_response(404, ['success' => false, 'error' => 'unknown_action']);
}

try {
  $pdo = catalog_api_db();
  if ($action === 'manifest') {
    $sequence = (int)$pdo->query('SELECT COALESCE(MAX(sequence), 0) FROM catalog_changes')->fetchColumn();
    $platforms = (int)$pdo->query('SELECT COUNT(*) FROM platforms')->fetchColumn();
    $games = (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn();
    catalog_api_response(200, [
      'success' => true,
      'sequence' => $sequence,
      'platforms' => $platforms,
      'games' => $games,
    ]);
  }

  if ($action === 'snapshot') {
    $entity = is_string($_GET['entity'] ?? null) ? trim($_GET['entity']) : '';
    $definition = catalog_api_entity($entity);
    $after = catalog_api_int('after', 0, 0, PHP_INT_MAX);
    $maximumLimit = $entity === 'platform_assets' ? 4 : 2000;
    $limit = catalog_api_int('limit', min(1000, $maximumLimit), 1, $maximumLimit);
    $sql = 'SELECT ' . $definition['select'] . ' FROM ' . $definition['table'] . ' WHERE id > ? ORDER BY id ASC LIMIT ?';
    $statement = $pdo->prepare($sql);
    $statement->bindValue(1, $after, PDO::PARAM_INT);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->execute();
    $items = [];
    $nextAfter = $after;
    while ($row = $statement->fetch()) {
      $nextAfter = (int)$row['id'];
      $items[] = catalog_api_encode_row($entity, $row);
    }
    catalog_api_response(200, [
      'success' => true,
      'entity' => $entity,
      'items' => $items,
      'next_after' => $nextAfter,
      'has_more' => count($items) === $limit,
    ]);
  }

  $since = catalog_api_int('since', 0, 0, PHP_INT_MAX);
  $limit = catalog_api_int('limit', 100, 1, 500);
  $latestSequence = (int)$pdo->query('SELECT COALESCE(MAX(sequence), 0) FROM catalog_changes')->fetchColumn();
  $statement = $pdo->prepare(
    'SELECT sequence, entity_type, entity_id, platform_id, operation, changed_at '
    . 'FROM catalog_changes WHERE sequence > ? ORDER BY sequence ASC LIMIT ?'
  );
  $statement->bindValue(1, $since, PDO::PARAM_INT);
  $statement->bindValue(2, $limit + 1, PDO::PARAM_INT);
  $statement->execute();
  $changes = $statement->fetchAll();
  $hasMore = count($changes) > $limit;
  if ($hasMore) {
    array_pop($changes);
  }
  $entityTables = ['platform' => 'platforms', 'game' => 'games', 'platform_asset' => 'platform_assets'];
  $entityColumns = [
    'platform' => 'id, platform_name, source, file_name, folder, platform_image, sort_order, created_at, updated_at',
    'game' => 'id, platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at',
    'platform_asset' => 'id, platform_id, asset_type, file_name, mime_type, data, metadata_json, created_at, updated_at',
  ];
  $responseChanges = [];
  $responseBytes = 0;
  foreach ($changes as $change) {
    $change['sequence'] = (int)$change['sequence'];
    $change['entity_id'] = (int)$change['entity_id'];
    $change['platform_id'] = $change['platform_id'] === null ? null : (int)$change['platform_id'];
    $change['data'] = null;
    $type = (string)$change['entity_type'];
    if (($change['operation'] ?? '') === 'upsert' && isset($entityTables[$type])) {
      $entityStatement = $pdo->prepare('SELECT ' . $entityColumns[$type] . ' FROM ' . $entityTables[$type] . ' WHERE id = ?');
      $entityStatement->execute([$change['entity_id']]);
      $data = $entityStatement->fetch();
      if (is_array($data)) {
        $entity = $type === 'platform_asset' ? 'platform_assets' : ($type === 'game' ? 'games' : 'platforms');
        $change['data'] = catalog_api_encode_row($entity, $data);
      }
    }
    $encodedChange = json_encode($change, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $changeBytes = is_string($encodedChange) ? strlen($encodedChange) : 0;
    if ($responseBytes + $changeBytes > 24 * 1024 * 1024) {
      $hasMore = true;
      break;
    }
    $responseBytes += $changeBytes;
    $responseChanges[] = $change;
  }
  catalog_api_response(200, [
    'success' => true,
    'sequence' => $latestSequence,
    'changes' => $responseChanges,
    'has_more' => $hasMore,
  ]);
} catch (Throwable $exception) {
  error_log('RGSX catalog API failure: ' . get_class($exception) . ': ' . $exception->getMessage());
  catalog_api_response(503, ['success' => false, 'error' => 'catalog_unavailable']);
}