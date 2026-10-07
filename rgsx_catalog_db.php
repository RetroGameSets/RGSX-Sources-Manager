<?php

function rgsx_manager_env_file_values(): array {
  static $values = null;
  if (is_array($values)) {
    return $values;
  }
  $values = [];
  $path = trim((string)getenv('RGSX_MANAGER_ENV_FILE'));
  if (!is_file($path) || !is_readable($path)) {
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

function rgsx_manager_setting(string $name, string $legacyName = '', string $default = ''): string {
  $runtimeValue = getenv($name);
  if ($runtimeValue !== false && trim((string)$runtimeValue) !== '') {
    return (string)$runtimeValue;
  }
  $fileValues = rgsx_manager_env_file_values();
  foreach ([$name, $legacyName] as $key) {
    if ($key !== '' && array_key_exists($key, $fileValues)) {
      return (string)$fileValues[$key];
    }
  }
  return $default;
}

function rgsx_mysql_config(): array {
  $sessionPassword = (string)($_SESSION['rgsx_manager_mysql_password'] ?? '');
  return [
    'host' => trim(rgsx_manager_setting('RGSX_MYSQL_HOST', 'DB_HOST', 'retrogamesets.fr')),
    'port' => max(1, (int)rgsx_manager_setting('RGSX_MYSQL_PORT', 'DB_PORT', '3306')),
    'database' => trim(rgsx_manager_setting('RGSX_MYSQL_DATABASE', 'DB_NAME', 'mbco1317_rgsx')),
    'user' => trim(rgsx_manager_setting('RGSX_MYSQL_USER', 'DB_USER', 'mbco1317_rgsx_admin')),
    'password' => $sessionPassword,
    'ssl_ca' => trim(rgsx_manager_setting('RGSX_MYSQL_SSL_CA', 'DB_SSL_CA')),
  ];
}

function rgsx_platform_source_from_name(mixed $platformName): string {
  $text = trim((string)$platformName);
  if ($text === '' || !str_ends_with($text, ')')) {
    return '';
  }
  $marker = strrpos($text, '(');
  if ($marker === false) {
    return '';
  }
  $aliases = [
    'archive' => 'Archive', 'archive.org' => 'Archive',
    'edgeemu' => 'EdgeEmu', 'edgeemu.net' => 'EdgeEmu',
    'lolroms' => 'LolRoms', 'torrent' => 'Torrent',
    '1fichier' => '1Fichier', 'vimms' => 'Vimms',
  ];
  return $aliases[strtolower(trim(substr($text, $marker + 1, -1)))] ?? '';
}

function rgsx_platform_display_name(mixed $platformName, mixed $source = ''): string {
  $text = trim((string)$platformName);
  $source = trim((string)$source) !== '' ? trim((string)$source) : rgsx_platform_source_from_name($text);
  return $source !== '' && str_ends_with($text, ')') ? trim(substr($text, 0, strrpos($text, '('))) : $text;
}

function rgsx_mysql_enabled(): bool {
  $config = rgsx_mysql_config();
  return $config['host'] !== ''
    && $config['database'] !== ''
    && $config['user'] !== ''
    && $config['password'] !== '';
}

function rgsx_mysql_pdo(): PDO {
  if (($GLOBALS['rgsx_catalog_db_pdo'] ?? null) instanceof PDO) {
    return $GLOBALS['rgsx_catalog_db_pdo'];
  }
  if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO MySQL is not enabled in this PHP runtime.');
  }

  $config = rgsx_mysql_config();
  $host = $config['host'];
  $port = $config['port'];
  $database = $config['database'];
  $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  $ca = $config['ssl_ca'];
  if ($ca !== '') {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
  }
  $pdo = new PDO($dsn, $config['user'], $config['password'], $options);
  $pdo->exec("SET SESSION time_zone = '+00:00'");
  $schema = [
    "CREATE TABLE IF NOT EXISTS schema_meta (meta_key VARCHAR(128) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL)",
    "CREATE TABLE IF NOT EXISTS platforms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform_name VARCHAR(255) NOT NULL UNIQUE, source VARCHAR(64) NOT NULL DEFAULT '', file_name VARCHAR(255) NOT NULL DEFAULT '', folder VARCHAR(255) NOT NULL DEFAULT '', platform_image VARCHAR(255) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS games (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform_id BIGINT UNSIGNED NOT NULL, name VARCHAR(512) NOT NULL, url VARCHAR(2048) NOT NULL DEFAULT '', size VARCHAR(64) NOT NULL DEFAULT '', size_bytes BIGINT NULL, display_name VARCHAR(512) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0, metadata_json LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_games_platform_name_url (platform_id, name(190), url(190)), KEY idx_games_platform_sort (platform_id, sort_order, id), CONSTRAINT fk_games_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS game_assets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, game_id BIGINT UNSIGNED NOT NULL, asset_type VARCHAR(64) NOT NULL, url VARCHAR(2048) NOT NULL DEFAULT '', local_path VARCHAR(1024) NOT NULL DEFAULT '', metadata_json LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, CONSTRAINT fk_game_assets_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS platform_assets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform_id BIGINT UNSIGNED NOT NULL, asset_type VARCHAR(64) NOT NULL, file_name VARCHAR(255) NOT NULL DEFAULT '', mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream', data LONGBLOB NOT NULL, metadata_json LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_platform_assets_type (platform_id, asset_type), CONSTRAINT fk_platform_assets_platform FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS download_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform VARCHAR(255) NOT NULL DEFAULT '', game_name VARCHAR(512) NOT NULL DEFAULT '', status VARCHAR(64) NOT NULL DEFAULT '', url VARCHAR(2048) NOT NULL DEFAULT '', progress DOUBLE NOT NULL DEFAULT 0, timestamp VARCHAR(64) NOT NULL DEFAULT '', message TEXT NOT NULL, task_id VARCHAR(255) NOT NULL DEFAULT '', metadata_json LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)",
    "CREATE TABLE IF NOT EXISTS downloaded_games (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, platform VARCHAR(255) NOT NULL, game_name VARCHAR(512) NOT NULL, metadata_json LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_downloaded_games (platform, game_name(190)))",
    "CREATE TABLE IF NOT EXISTS catalog_changes (sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(32) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, platform_id BIGINT UNSIGNED NULL, operation VARCHAR(16) NOT NULL, changed_at VARCHAR(32) NOT NULL, KEY idx_catalog_changes_sequence (sequence)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];
  foreach ($schema as $statement) {
    $pdo->exec($statement);
  }
  $triggerStatements = [
    'trg_catalog_platform_insert' => "CREATE TRIGGER trg_catalog_platform_insert AFTER INSERT ON platforms FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform', NEW.id, NEW.id, 'upsert', NEW.updated_at)",
    'trg_catalog_platform_update' => "CREATE TRIGGER trg_catalog_platform_update AFTER UPDATE ON platforms FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform', NEW.id, NEW.id, 'upsert', NEW.updated_at)",
    'trg_catalog_platform_delete' => "CREATE TRIGGER trg_catalog_platform_delete AFTER DELETE ON platforms FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform', OLD.id, OLD.id, 'delete', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%SZ'))",
    'trg_catalog_game_insert' => "CREATE TRIGGER trg_catalog_game_insert AFTER INSERT ON games FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('game', NEW.id, NEW.platform_id, 'upsert', NEW.updated_at)",
    'trg_catalog_game_update' => "CREATE TRIGGER trg_catalog_game_update AFTER UPDATE ON games FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('game', NEW.id, NEW.platform_id, 'upsert', NEW.updated_at)",
    'trg_catalog_game_delete' => "CREATE TRIGGER trg_catalog_game_delete AFTER DELETE ON games FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('game', OLD.id, OLD.platform_id, 'delete', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%SZ'))",
    'trg_catalog_platform_asset_insert' => "CREATE TRIGGER trg_catalog_platform_asset_insert AFTER INSERT ON platform_assets FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform_asset', NEW.id, NEW.platform_id, 'upsert', NEW.updated_at)",
    'trg_catalog_platform_asset_update' => "CREATE TRIGGER trg_catalog_platform_asset_update AFTER UPDATE ON platform_assets FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform_asset', NEW.id, NEW.platform_id, 'upsert', NEW.updated_at)",
    'trg_catalog_platform_asset_delete' => "CREATE TRIGGER trg_catalog_platform_asset_delete AFTER DELETE ON platform_assets FOR EACH ROW INSERT INTO catalog_changes(entity_type, entity_id, platform_id, operation, changed_at) VALUES('platform_asset', OLD.id, OLD.platform_id, 'delete', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%SZ'))",
  ];
  $triggerExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
  foreach ($triggerStatements as $triggerName => $statement) {
    $triggerExists->execute([$triggerName]);
    if (!(int)$triggerExists->fetchColumn()) {
      $pdo->exec($statement);
    }
  }
  $pdo->exec("INSERT INTO schema_meta(meta_key, meta_value) VALUES('schema_version', '4') ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
  $GLOBALS['rgsx_catalog_db_pdo'] = $pdo;
  return $pdo;
}

function rgsx_mysql_display_uri(): string {
  $config = rgsx_mysql_config();
  return 'mysql://' . $config['host'] . '/' . $config['database'];
}

function rgsx_catalog_db_available(): bool {
  return rgsx_mysql_enabled()
    && class_exists('PDO')
    && in_array('mysql', PDO::getAvailableDrivers(), true);
}

function rgsx_catalog_db(): PDO {
  if (!rgsx_mysql_enabled()) {
    throw new RuntimeException('MariaDB centrale non configurée pour RGSX Sources Manager.');
  }
  return rgsx_mysql_pdo();
}

function rgsx_catalog_db_now(): string {
  return gmdate('Y-m-d\\TH:i:s\\Z');
}

function rgsx_catalog_db_platforms(): array {
  $rows = rgsx_catalog_db()->query('SELECT id, platform_name, source, file_name, folder, platform_image, sort_order, created_at, updated_at FROM platforms ORDER BY sort_order, id')->fetchAll();
  return is_array($rows) ? $rows : [];
}

function rgsx_catalog_db_platform_summary(string $search = '', int $limit = 200, int $offset = 0): array {
  $limit = max(1, min($limit, 1000));
  $offset = max(0, $offset);
  $sql = 'SELECT p.id, p.platform_name, p.source, p.file_name, p.folder, p.platform_image, p.sort_order, p.updated_at, COUNT(g.id) AS game_count
          FROM platforms p LEFT JOIN games g ON g.platform_id = p.id';
  $params = [];
  if ($search !== '') {
    $sql .= ' WHERE p.platform_name LIKE ? OR p.source LIKE ? OR p.folder LIKE ?';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }
  $sql .= ' GROUP BY p.id ORDER BY p.sort_order, p.id LIMIT ? OFFSET ?';
  $stmt = rgsx_catalog_db()->prepare($sql);
  foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
  }
  $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
  $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function rgsx_catalog_db_platform_count(string $search = ''): int {
  if ($search === '') {
    return (int)rgsx_catalog_db()->query('SELECT COUNT(*) FROM platforms')->fetchColumn();
  }
  $stmt = rgsx_catalog_db()->prepare('SELECT COUNT(*) FROM platforms WHERE platform_name LIKE ? OR source LIKE ? OR folder LIKE ?');
  $like = '%' . $search . '%';
  $stmt->execute([$like, $like, $like]);
  return (int)$stmt->fetchColumn();
}

function rgsx_catalog_db_platform_by_id(int $platformId): ?array {
  $stmt = rgsx_catalog_db()->prepare('SELECT id, platform_name, source, file_name, folder, platform_image, sort_order, created_at, updated_at FROM platforms WHERE id = ?');
  $stmt->execute([$platformId]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function rgsx_catalog_db_games_count_by_platform(int $platformId, string $search = ''): int {
  if ($search === '') {
    $stmt = rgsx_catalog_db()->prepare('SELECT COUNT(*) FROM games WHERE platform_id = ?');
    $stmt->execute([$platformId]);
  } else {
    $stmt = rgsx_catalog_db()->prepare('SELECT COUNT(*) FROM games WHERE platform_id = ? AND (name LIKE ? OR url LIKE ?)');
    $like = '%' . $search . '%';
    $stmt->execute([$platformId, $like, $like]);
  }
  return (int)$stmt->fetchColumn();
}

function rgsx_catalog_db_games_page(int $platformId, string $search = '', int $limit = 100, int $offset = 0, string $sort = 'name', string $direction = 'asc'): array {
  $limit = max(1, min($limit, 500));
  $offset = max(0, $offset);
  $sortColumns = [
    'name' => 'LOWER(name)',
    'url' => 'LOWER(url)',
    'size' => 'size_bytes',
    'updated_at' => 'updated_at',
  ];
  $sortExpression = $sortColumns[$sort] ?? $sortColumns['name'];
  $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
  $sql = 'SELECT id, platform_id, name, url, size, size_bytes, updated_at FROM games WHERE platform_id = ?';
  $params = [$platformId];
  if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR url LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
  }
  $sql .= ' ORDER BY ' . $sortExpression . ' ' . $direction . ', id ASC LIMIT ? OFFSET ?';
  $stmt = rgsx_catalog_db()->prepare($sql);
  foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
  }
  $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
  $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function rgsx_catalog_db_create_or_update_platform(array $platform, ?int $platformId = null): int {
  $name = trim((string)($platform['platform_name'] ?? ''));
  $folder = trim((string)($platform['folder'] ?? ''));
  if ($name === '' || $folder === '') {
    throw new InvalidArgumentException('Platform name and folder are required.');
  }
  $image = trim((string)($platform['platform_image'] ?? ''));
  $source = trim((string)($platform['source'] ?? ''));
  $fileName = trim((string)($platform['file_name'] ?? ''));
  if ($fileName === '') {
    $fileName = $name . '.json';
  }
  $now = rgsx_catalog_db_now();
  $pdo = rgsx_catalog_db();
  if ($platformId !== null) {
    $stmt = $pdo->prepare('UPDATE platforms SET platform_name = ?, source = ?, file_name = ?, folder = ?, platform_image = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$name, $source, $fileName, $folder, $image, $now, $platformId]);
    return $platformId;
  }
  $stmt = $pdo->prepare('INSERT INTO platforms(platform_name, source, file_name, folder, platform_image, sort_order, created_at, updated_at) VALUES(?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) + 1 FROM platforms), 0), ?, ?) ON DUPLICATE KEY UPDATE source=VALUES(source), file_name=VALUES(file_name), folder=VALUES(folder), platform_image=VALUES(platform_image), updated_at=VALUES(updated_at)');
  $stmt->execute([$name, $source, $fileName, $folder, $image, $now, $now]);
  return rgsx_catalog_db_find_platform_id_by_name($name) ?? 0;
}

function rgsx_catalog_db_delete_platform_by_id(int $platformId): void {
  rgsx_catalog_db()->prepare('DELETE FROM platforms WHERE id = ?')->execute([$platformId]);
}

function rgsx_catalog_db_update_game(int $gameId, string $name, string $url, string $size): void {
  $name = trim($name);
  if ($name === '') {
    throw new InvalidArgumentException('Game name cannot be empty.');
  }
  $now = rgsx_catalog_db_now();
  rgsx_catalog_db()->prepare('UPDATE games SET name = ?, url = ?, size = ?, size_bytes = ?, display_name = ?, updated_at = ? WHERE id = ?')->execute([$name, trim($url), trim($size), rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), $now, $gameId]);
}

function rgsx_catalog_db_delete_game(int $gameId): void {
  rgsx_catalog_db()->prepare('DELETE FROM games WHERE id = ?')->execute([$gameId]);
}

function rgsx_catalog_db_add_game(int $platformId, string $name, string $url, string $size): int {
  $name = trim($name);
  $url = trim($url);
  $size = trim($size);
  if ($name === '') {
    throw new InvalidArgumentException('Game name cannot be empty.');
  }
  $now = rgsx_catalog_db_now();
  $stmt = rgsx_catalog_db()->prepare('INSERT INTO games(platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) + 1 FROM games WHERE platform_id = ?), 0), \'{}\', ?, ?) ON DUPLICATE KEY UPDATE size=VALUES(size), size_bytes=VALUES(size_bytes), updated_at=VALUES(updated_at)');
  $stmt->execute([$platformId, $name, $url, $size, rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), $platformId, $now, $now]);
  $id = rgsx_catalog_db()->prepare('SELECT id FROM games WHERE platform_id = ? AND name = ? AND url = ?');
  $id->execute([$platformId, $name, $url]);
  return (int)$id->fetchColumn();
}

function rgsx_mysql_merge_games_bulk(PDO $pdo, int $platformId, array $rows, string $mode, string $now): int {
  $incoming = [];
  $nameKeys = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $name = trim((string)($row[0] ?? ''));
    if ($name === '') {
      continue;
    }
    $url = trim((string)($row[1] ?? ''));
    $size = trim((string)($row[2] ?? ''));
    $nameKey = strtolower($name);
    $incoming[] = [
      'name' => $name,
      'url' => $url,
      'size' => $size,
      'size_bytes' => rgsx_catalog_db_size_bytes($size),
      'display_name' => pathinfo($name, PATHINFO_FILENAME),
      'name_key' => $nameKey,
    ];
    $nameKeys[$nameKey] = true;
  }
  if (!$incoming) {
    return 0;
  }

  $existingByExact = [];
  $existingByName = [];
  foreach (array_chunk(array_keys($nameKeys), 400) as $nameChunk) {
    $placeholders = implode(',', array_fill(0, count($nameChunk), '?'));
    $statement = $pdo->prepare('SELECT id, name, url FROM games WHERE platform_id = ? AND LOWER(name) IN (' . $placeholders . ') ORDER BY id');
    $statement->execute(array_merge([$platformId], $nameChunk));
    while ($existing = $statement->fetch(PDO::FETCH_ASSOC)) {
      $existingNameKey = strtolower((string)$existing['name']);
      $existingRow = [
        'id' => (int)$existing['id'],
        'name_key' => $existingNameKey,
        'url_key' => strtolower((string)$existing['url']),
      ];
      $existingByExact[$existingNameKey . "\0" . $existingRow['url_key']] = $existingRow;
      if (!isset($existingByName[$existingNameKey])) {
        $existingByName[$existingNameKey] = $existingRow;
      }
    }
  }

  $updates = [];
  $inserts = [];
  $pendingByExact = [];
  $pendingByName = [];
  $changed = 0;
  foreach ($incoming as $game) {
    $exactKey = $game['name_key'] . "\0" . strtolower($game['url']);
    $match = $existingByExact[$exactKey] ?? null;
    if ($match === null && $mode !== 'append') {
      $match = $existingByName[$game['name_key']] ?? null;
    }
    if ($match !== null) {
      if ($mode === 'append') {
        continue;
      }
      $updates[$match['id']] = array_merge($game, ['id' => $match['id']]);
      $existingByName[$game['name_key']] = $match;
      $existingByExact[$exactKey] = $match;
      $changed++;
      continue;
    }

    $pendingIndex = $pendingByExact[$exactKey] ?? null;
    if ($pendingIndex === null && $mode !== 'append') {
      $pendingIndex = $pendingByName[$game['name_key']] ?? null;
    }
    if ($pendingIndex !== null) {
      if ($mode !== 'append') {
        $inserts[$pendingIndex] = $game;
      }
      $changed++;
      continue;
    }
    $pendingIndex = count($inserts);
    $inserts[] = $game;
    $pendingByExact[$exactKey] = $pendingIndex;
    if ($mode !== 'append') {
      $pendingByName[$game['name_key']] = $pendingIndex;
    }
    $changed++;
  }

  if (!$updates && !$inserts) {
    return $changed;
  }

  $pdo->beginTransaction();
  try {
    if ($updates) {
      $updateRows = array_values($updates);
      $caseExpressions = [];
      $updateParameters = [];
      foreach (['name', 'url', 'size', 'size_bytes', 'display_name'] as $column) {
        $caseParts = ['CASE id'];
        foreach ($updateRows as $updateRow) {
          $caseParts[] = 'WHEN ? THEN ?';
          $updateParameters[] = $updateRow['id'];
          $updateParameters[] = $updateRow[$column];
        }
        $caseExpressions[$column] = implode(' ', $caseParts) . ' END';
      }
      $idPlaceholders = implode(',', array_fill(0, count($updateRows), '?'));
      foreach ($updateRows as $updateRow) {
        $updateParameters[] = $updateRow['id'];
      }
      $updateParameters[] = $now;
      $updateSql = 'UPDATE games SET name = ' . $caseExpressions['name'] . ', url = ' . $caseExpressions['url'] . ', size = ' . $caseExpressions['size'] . ', size_bytes = ' . $caseExpressions['size_bytes'] . ', display_name = ' . $caseExpressions['display_name'] . ', updated_at = ? WHERE id IN (' . $idPlaceholders . ')';
      $pdo->prepare($updateSql)->execute($updateParameters);
    }

    if ($inserts) {
      $sortStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM games WHERE platform_id = ?');
      $sortStatement->execute([$platformId]);
      $sortOrder = (int)$sortStatement->fetchColumn() + 1;
      foreach (array_chunk($inserts, 250) as $insertChunk) {
        $valueParts = [];
        $insertParameters = [];
        foreach ($insertChunk as $game) {
          $valueParts[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
          array_push($insertParameters, $platformId, $game['name'], $game['url'], $game['size'], $game['size_bytes'], $game['display_name'], $sortOrder++, '{}', $now, $now);
        }
        $insertSql = 'INSERT INTO games(platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at) VALUES ' . implode(',', $valueParts) . ' ON DUPLICATE KEY UPDATE size = VALUES(size), size_bytes = VALUES(size_bytes), updated_at = VALUES(updated_at)';
        $pdo->prepare($insertSql)->execute($insertParameters);
      }
    }
    $pdo->commit();
    return $changed;
  } catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
  }
}

function rgsx_catalog_db_merge_games(int $platformId, array $rows, string $mode = 'upsert'): int {
  $pdo = rgsx_catalog_db();
  $now = rgsx_catalog_db_now();
  if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    return rgsx_mysql_merge_games_bulk($pdo, $platformId, $rows, $mode === 'append' ? 'append' : 'upsert', $now);
  }
  $changed = 0;
  $pdo->beginTransaction();
  try {
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $name = trim((string)($row[0] ?? ''));
      if ($name === '') {
        continue;
      }
      $url = trim((string)($row[1] ?? ''));
      $size = trim((string)($row[2] ?? ''));
      $findExact = $pdo->prepare('SELECT id FROM games WHERE platform_id = ? AND name = ? AND url = ? LIMIT 1');
      $findExact->execute([$platformId, $name, $url]);
      $exactId = $findExact->fetchColumn();
      if ($exactId !== false) {
        if ($mode === 'append') {
          continue;
        }
        $update = $pdo->prepare('UPDATE games SET name = ?, url = ?, size = ?, size_bytes = ?, display_name = ?, updated_at = ? WHERE id = ?');
        $update->execute([$name, $url, $size, rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), $now, (int)$exactId]);
        $changed++;
        continue;
      }
      if ($mode !== 'append') {
        $findName = $pdo->prepare('SELECT id FROM games WHERE platform_id = ? AND lower(name) = lower(?) ORDER BY id LIMIT 1');
        $findName->execute([$platformId, $name]);
        $existing = $findName->fetchColumn();
        if ($existing !== false && $existing !== null) {
          $update = $pdo->prepare('UPDATE games SET name = ?, url = ?, size = ?, size_bytes = ?, display_name = ?, updated_at = ? WHERE id = ?');
          $update->execute([$name, $url, $size, rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), $now, (int)$existing]);
          $changed++;
          continue;
        }
      }
      $insert = $pdo->prepare('INSERT INTO games(platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, COALESCE((SELECT MAX(sort_order) + 1 FROM games WHERE platform_id = ?), 0), \'{}\', ?, ?) ON DUPLICATE KEY UPDATE size=VALUES(size), size_bytes=VALUES(size_bytes), updated_at=VALUES(updated_at)');
      $insert->execute([$platformId, $name, $url, $size, rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), $platformId, $now, $now]);
      $changed++;
    }
    $pdo->commit();
    return $changed;
  } catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
  }
}

function rgsx_catalog_db_games_map(): array {
  $stmt = rgsx_catalog_db()->query('SELECT p.file_name, p.platform_name, g.name, g.url, g.size FROM games g JOIN platforms p ON p.id = g.platform_id ORDER BY p.sort_order, p.id, g.sort_order, g.id');
  $map = [];
  while ($row = $stmt->fetch()) {
    $fileName = trim((string)($row['file_name'] ?? ''));
    if ($fileName === '') {
      $fileName = (string)$row['platform_name'] . '.json';
    }
    $map[$fileName][] = [
      (string)($row['name'] ?? ''),
      (string)($row['url'] ?? ''),
      (string)($row['size'] ?? ''),
    ];
  }
  return $map;
}

function rgsx_catalog_db_size_bytes(string $size): ?int {
  $size = trim($size);
  if ($size === '') {
    return null;
  }
  if (!preg_match('/(\\d+(?:[.,]\\d+)?)\\s*([KMGTPE]?)/i', $size, $matches)) {
    return null;
  }
  $number = (float)str_replace(',', '.', $matches[1]);
  $unit = strtoupper($matches[2] ?? '');
  $units = ['' => 1, 'K' => 1024, 'M' => 1024 * 1024, 'G' => 1024 * 1024 * 1024, 'T' => 1024 * 1024 * 1024 * 1024, 'P' => 1024 * 1024 * 1024 * 1024 * 1024, 'E' => 1024 * 1024 * 1024 * 1024 * 1024 * 1024];
  return (int)($number * ($units[$unit] ?? 1));
}

function rgsx_catalog_db_find_platform_id(string $fileName): ?int {
  $pdo = rgsx_catalog_db();
  $stmt = $pdo->prepare('SELECT id FROM platforms WHERE file_name = ? OR platform_name = ? LIMIT 1');
  $platformName = preg_replace('/\\.json$/i', '', basename($fileName));
  $stmt->execute([$fileName, $platformName]);
  $value = $stmt->fetchColumn();
  return $value === false ? null : (int)$value;
}

function rgsx_catalog_db_find_platform_id_by_name(string $platformName): ?int {
  $stmt = rgsx_catalog_db()->prepare('SELECT id FROM platforms WHERE platform_name = ? LIMIT 1');
  $stmt->execute([$platformName]);
  $value = $stmt->fetchColumn();
  return $value === false ? null : (int)$value;
}

function rgsx_catalog_db_image_mime_type(string $fileName): string {
  $map = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
  ];
  return $map[strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

function rgsx_catalog_db_upsert_platform_image(int $platformId, string $fileName, string $data, string $mimeType = ''): void {
  $now = rgsx_catalog_db_now();
  $stmt = rgsx_catalog_db()->prepare(
    "INSERT INTO platform_assets(platform_id, asset_type, file_name, mime_type, data, metadata_json, created_at, updated_at)
     VALUES(?, 'platform_image', ?, ?, ?, '{}', ?, ?)
     ON DUPLICATE KEY UPDATE
       file_name=VALUES(file_name), mime_type=VALUES(mime_type), data=VALUES(data), updated_at=VALUES(updated_at)"
  );
  $stmt->bindValue(1, $platformId, PDO::PARAM_INT);
  $stmt->bindValue(2, $fileName, PDO::PARAM_STR);
  $stmt->bindValue(3, $mimeType !== '' ? $mimeType : rgsx_catalog_db_image_mime_type($fileName), PDO::PARAM_STR);
  $stmt->bindValue(4, $data, PDO::PARAM_LOB);
  $stmt->bindValue(5, $now, PDO::PARAM_STR);
  $stmt->bindValue(6, $now, PDO::PARAM_STR);
  $stmt->execute();
}

function rgsx_catalog_db_load_session_images(): array {
  $images = [];
  $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx_imgs_' . session_id();
  if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
  $stmt = rgsx_catalog_db()->query("SELECT file_name, mime_type, data FROM platform_assets WHERE asset_type = 'platform_image' ORDER BY id");
  while ($row = $stmt->fetch()) {
    $fileName = basename((string)($row['file_name'] ?? ''));
    if ($fileName === '' || !is_string($row['data'] ?? null)) { continue; }
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($path)) { @file_put_contents($path, $row['data']); }
    if (is_file($path)) {
      $images[$fileName] = ['name' => $fileName, 'tmp' => $path, 'type' => (string)($row['mime_type'] ?? '')];
    }
  }
  return array_values($images);
}

function rgsx_catalog_db_sync_session_images(array $systems): void {
  $images = [];
  foreach (($_SESSION['images'] ?? []) as $image) {
    $name = basename(trim((string)($image['name'] ?? '')));
    $path = (string)($image['tmp'] ?? '');
    if ($name !== '' && $path !== '' && is_file($path)) { $images[$name] = $image; }
  }
  foreach ($systems as $system) {
    if (!is_array($system)) { continue; }
    $platformName = trim((string)($system['platform_name'] ?? ''));
    $fileName = basename(trim((string)($system['platform_image'] ?? '')));
    if ($platformName === '' || $fileName === '' || !isset($images[$fileName])) { continue; }
    $platformId = rgsx_catalog_db_find_platform_id_by_name($platformName);
    $data = @file_get_contents((string)$images[$fileName]['tmp']);
    if ($platformId !== null && is_string($data) && $data !== '') {
      rgsx_catalog_db_upsert_platform_image($platformId, $fileName, $data, (string)($images[$fileName]['type'] ?? ''));
    }
  }
}

function rgsx_catalog_db_upsert_platform(array $platform, int $sortOrder = 0): int {
  $pdo = rgsx_catalog_db();
  $name = trim((string)($platform['platform_name'] ?? ''));
  if ($name === '') {
    throw new InvalidArgumentException('Platform name cannot be empty.');
  }
  $fileName = trim((string)($platform['file_name'] ?? ''));
  if ($fileName === '') {
    $fileName = $name . '.json';
  }
  $now = rgsx_catalog_db_now();
  $stmt = $pdo->prepare("INSERT INTO platforms(platform_name, file_name, folder, platform_image, sort_order, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name), folder=VALUES(folder), platform_image=VALUES(platform_image), sort_order=VALUES(sort_order), updated_at=VALUES(updated_at)");
  $stmt->execute([$name, $fileName, (string)($platform['folder'] ?? ''), (string)($platform['platform_image'] ?? ''), $sortOrder, $now, $now]);
  $id = rgsx_catalog_db()->prepare('SELECT id FROM platforms WHERE platform_name = ?');
  $id->execute([$name]);
  return (int)$id->fetchColumn();
}

function rgsx_catalog_db_replace_games(string $fileName, array $rows): int {
  $pdo = rgsx_catalog_db();
  $platformId = rgsx_catalog_db_find_platform_id($fileName);
  if ($platformId === null) {
    $platformName = preg_replace('/\\.json$/i', '', basename($fileName));
    $platformId = rgsx_catalog_db_upsert_platform(['platform_name' => $platformName, 'file_name' => basename($fileName)], 0);
  }
  $now = rgsx_catalog_db_now();
  $pdo->beginTransaction();
  try {
    $delete = $pdo->prepare('DELETE FROM games WHERE platform_id = ?');
    $delete->execute([$platformId]);
    $insert = $pdo->prepare('INSERT INTO games(platform_id, name, url, size, size_bytes, display_name, sort_order, metadata_json, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $count = 0;
    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        continue;
      }
      $name = trim((string)($row[0] ?? ''));
      if ($name === '') {
        continue;
      }
      $url = trim((string)($row[1] ?? ''));
      $size = trim((string)($row[2] ?? ''));
      $insert->execute([$platformId, $name, $url, $size, rgsx_catalog_db_size_bytes($size), pathinfo($name, PATHINFO_FILENAME), (int)$index, '{}', $now, $now]);
      $count++;
    }
    $pdo->prepare('UPDATE platforms SET updated_at = ? WHERE id = ?')->execute([$now, $platformId]);
    $pdo->commit();
    return $count;
  } catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
  }
}

function rgsx_catalog_db_upsert_session_systems(array $systems): void {
  foreach (array_values($systems) as $index => $system) {
    if (is_array($system)) {
      rgsx_catalog_db_upsert_platform($system, $index);
    }
  }
}

function rgsx_catalog_db_sync_catalog(array $systems, array $gamesMap): void {
  rgsx_catalog_db_upsert_session_systems($systems);
  foreach ($gamesMap as $fileName => $rows) {
    rgsx_catalog_db_replace_games((string)$fileName, is_array($rows) ? $rows : []);
  }
}

function rgsx_catalog_db_delete_games_file(string $fileName): void {
  $platformId = rgsx_catalog_db_find_platform_id($fileName);
  if ($platformId !== null) {
    rgsx_catalog_db()->prepare('DELETE FROM games WHERE platform_id = ?')->execute([$platformId]);
  }
}

function rgsx_catalog_db_delete_platform(string $fileName): void {
  $platformId = rgsx_catalog_db_find_platform_id($fileName);
  if ($platformId !== null) {
    rgsx_catalog_db()->prepare('DELETE FROM platforms WHERE id = ?')->execute([$platformId]);
  }
}

function rgsx_catalog_db_sync_after_action(string $action, array $post, array $systems, array $gamesMap): void {
  if (!rgsx_catalog_db_available()) {
    return;
  }
  $fullCatalogActions = ['import_data_zip', 'systems_upload', 'games_upload'];
  if (in_array($action, $fullCatalogActions, true)) {
    rgsx_catalog_db_sync_catalog($systems, $gamesMap);
    rgsx_catalog_db_sync_session_images($systems);
    return;
  }
  if (in_array($action, ['attach_scrape_to_platform', 'attach_all_scrapes_to_platform', 'games_add_row', 'games_update_row', 'games_delete_row', 'games_clear_platform'], true)) {
    $fileName = trim((string)($post['platform_file'] ?? $post['games_file'] ?? ''));
    if ($fileName !== '') {
      rgsx_catalog_db_replace_games($fileName, is_array($gamesMap[$fileName] ?? null) ? $gamesMap[$fileName] : []);
    }
    rgsx_catalog_db_upsert_session_systems($systems);
    rgsx_catalog_db_sync_session_images($systems);
    return;
  }
  if ($action === 'games_clear_all') {
    rgsx_catalog_db()->exec('DELETE FROM games');
    return;
  }
  if ($action === 'games_delete_file') {
    rgsx_catalog_db_delete_games_file(trim((string)($post['games_file'] ?? '')));
    return;
  }
  if (in_array($action, ['systems_add', 'systems_update', 'systems_update_with_rename'], true)) {
    $oldName = trim((string)($post['old_platform_name'] ?? ''));
    $newName = trim((string)($post['platform_name'] ?? ''));
    if ($action === 'systems_update_with_rename' && $oldName !== '' && $newName !== '' && $oldName !== $newName) {
      $pdo = rgsx_catalog_db();
      $stmt = $pdo->prepare('UPDATE platforms SET platform_name = ?, file_name = ?, updated_at = ? WHERE platform_name = ?');
      $stmt->execute([$newName, $newName . '.json', rgsx_catalog_db_now(), $oldName]);
    }
    rgsx_catalog_db_upsert_session_systems($systems);
    rgsx_catalog_db_sync_session_images($systems);
    return;
  }
  if ($action === 'platform_delete_complete') {
    rgsx_catalog_db_delete_platform(trim((string)($post['platform_file'] ?? '')));
    return;
  }
}
