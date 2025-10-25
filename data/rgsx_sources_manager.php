<?php
session_start();

// Debug logging - Track all requests
$debugLog = '[' . date('Y-m-d H:i:s') . '] Request: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
if (!empty($_GET)) $debugLog .= ' GET: ' . json_encode($_GET);
if (!empty($_POST['action'])) $debugLog .= ' POST action: ' . $_POST['action'];
$debugLog .= "\n";
file_put_contents(__DIR__ . '/assets/debug.log', $debugLog, FILE_APPEND);

// Force local base URL for preview images
$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                 (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$currentHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088';
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '/rgsx_sources_manager.php';
$baseUrl = $currentScheme . '://' . $currentHost . $currentScript;

// ---------------- Internationalisation (i18n) ---------------
// Available languages
$availableLangs = ['fr','en'];
$lang = $_POST['lang'] ?? $_GET['lang'] ?? ($_SESSION['lang'] ?? 'fr');
if (!in_array($lang, $availableLangs, true)) { $lang = 'fr'; }
$_SESSION['lang'] = $lang;
$langFile = __DIR__ . '/assets/lang/' . $lang . '.json';
$LANG = [];
if (is_file($langFile)) {
  $raw = @file_get_contents($langFile);
  $arr = json_decode($raw, true);
  if (is_array($arr)) { $LANG = $arr; }
}
// Translation helper
function t(string $key, string $fallback = ''): string {
  global $LANG; return isset($LANG[$key]) && $LANG[$key] !== '' ? $LANG[$key] : ($fallback !== '' ? $fallback : $key);
}

// Unified RGSX Sources Manager
// - Scrape (1fichier / Myrient / Archive.org) to create platform game JSONs
// - Create/Edit systems_list.json (from scratch or upload)
// - Create/Edit per-platform games JSON
// - Package ZIP: systems_list.json + images/ + games/

// -------------- Utilities & Shared -----------------
function is_url($str) { return filter_var($str, FILTER_VALIDATE_URL); }
function is_html_block($str) { return preg_match('/<(tr|table|html|body|pre)[\s>]/i', $str); }
function format_bytes($bytes) {
    if (!is_numeric($bytes)) return $bytes;
    $bytes = (float)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB','MB','GB','TB','PB'];
    $i = -1;
    do { $bytes /= 1024; $i++; } while ($bytes >= 1024 && $i < count($units)-1);
    return sprintf('%.2f %s', $bytes, $units[$i]);
}

function parse_file_size_to_bytes($sizeStr) {
    if (!$sizeStr || trim($sizeStr) === '') return 0;
    
    $sizeStr = trim($sizeStr);
    
    // Pattern plus large pour capturer différents formats
    // Exemples: "1.5MB", "942.1K", "1,234 GB", "500 bytes", "1.0M"
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*([KMGTPE]?)([BI]?)/i', $sizeStr, $matches)) {
        $number = (float)str_replace(',', '.', $matches[1]);
        $unit = strtoupper($matches[2]); // K, M, G, T, P, E
        
        $multiplier = 1;
        switch ($unit) {
            case 'K': $multiplier = 1024; break;
            case 'M': $multiplier = 1024 * 1024; break;
            case 'G': $multiplier = 1024 * 1024 * 1024; break;
            case 'T': $multiplier = 1024 * 1024 * 1024 * 1024; break;
            case 'P': $multiplier = 1024 * 1024 * 1024 * 1024 * 1024; break;
            case 'E': $multiplier = 1024 * 1024 * 1024 * 1024 * 1024 * 1024; break;
        }
        
        return (int)($number * $multiplier);
    }
    
    // Pattern pour capturer juste les nombres (bytes)
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $sizeStr, $matches)) {
        return (int)((float)str_replace(',', '.', $matches[1]));
    }
    
    return 0;
}

function calculate_total_size($rows) {
    $totalBytes = 0;
    $debugSizes = [];
    
    foreach ($rows as $row) {
        // $row[2] contient la taille (nom, url, taille)
        if (isset($row[2]) && !empty($row[2])) {
            $sizeStr = $row[2];
            $bytes = parse_file_size_to_bytes($sizeStr);
            $totalBytes += $bytes;
            
            // Garder quelques exemples pour debug
            if (count($debugSizes) < 3) {
                $debugSizes[] = "$sizeStr → $bytes bytes";
            }
        }
    }
    
    $result = format_bytes($totalBytes);
    
    // Debug temporaire : afficher quelques exemples si la taille totale est 0
    if ($totalBytes == 0 && !empty($debugSizes)) {
        $result .= ' (debug: ' . implode(', ', $debugSizes) . ')';
    }
    
    return $result;
}

// Normalize URL-like strings: add percent-encoding for spaces and query values
function normalize_url_like(string $u): string {
  $u = trim($u);
  if ($u === '') return $u;
  // If already valid, return as-is
  if (filter_var($u, FILTER_VALIDATE_URL)) return $u;
  $parts = @parse_url($u);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    // Simple fallback: encode spaces only
    return str_replace(' ', '%20', $u);
  }
  $scheme = $parts['scheme'] . '://';
  $auth = '';
  if (isset($parts['user'])) { $auth .= $parts['user']; if (isset($parts['pass'])) { $auth .= ':' . $parts['pass']; } $auth .= '@'; }
  $host = $parts['host'] ?? '';
  $port = isset($parts['port']) ? (':' . $parts['port']) : '';
  // Encode each path segment (preserving slashes)
  $path = '';
  if (isset($parts['path'])) {
    $segs = explode('/', $parts['path']);
    foreach ($segs as &$seg) { $seg = rawurlencode($seg); }
    unset($seg);
    $path = implode('/', $segs);
    // Preserve leading slash if present
    if (isset($parts['path'][0]) && $parts['path'][0] === '/' && (!isset($path[0]) || $path[0] !== '/')) { $path = '/' . $path; }
  }
  // Encode query parameters with RFC3986 (spaces -> %20)
  $query = '';
  if (isset($parts['query'])) {
    parse_str($parts['query'], $qarr);
    $query = http_build_query($qarr, '', '&', PHP_QUERY_RFC3986);
  }
  $frag = isset($parts['fragment']) ? ('#' . rawurlencode($parts['fragment'])) : '';
  $rebuilt = $scheme . $auth . $host . $port . $path . ($query !== '' ? ('?' . $query) : '') . $frag;
  if (filter_var($rebuilt, FILTER_VALIDATE_URL)) return $rebuilt;
  // Last resort: replace spaces
  return str_replace(' ', '%20', $u);
}

// Robust HTTP fetch (cURL with fallback to file_get_contents) + debug info
function http_fetch(string $url, int $timeout = 30): array {
  $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
  $host = parse_url($url, PHP_URL_HOST) ?: '';
  $isArchive = stripos($host, 'archive.org') !== false;
  // Try cURL if available
  if (function_exists('curl_init')) {
    $try = function($targetUrl, $verifyPeer) use ($timeout, $ua, $isArchive) {
      $ch = curl_init($targetUrl);
      $headers = [
          'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language: en-US,en;q=0.8',
          'Cache-Control: no-cache',
          'Connection: keep-alive',
          'Pragma: no-cache',
          'Upgrade-Insecure-Requests: 1'
      ];
      if ($isArchive) { $headers[] = 'Referer: https://archive.org/'; $headers[] = 'Origin: https://archive.org'; }
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '', // allow gzip/deflate
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => $verifyPeer,
      ]);
      $resp = curl_exec($ch);
      $err = $resp === false ? curl_error($ch) : null;
      $errno = curl_errno($ch);
      $info = curl_getinfo($ch);
      curl_close($ch);
      if ($resp === false) {
        return [false, 0, '', $err, $info];
      }
      $hsz = (int)($info['header_size'] ?? 0);
      $headers = $hsz > 0 ? substr($resp, 0, $hsz) : '';
      $body = $hsz > 0 ? substr($resp, $hsz) : $resp;
      $code = (int)($info['http_code'] ?? 0);
      $ok = ($code >= 200 && $code < 300) && $body !== '';
      return [$ok, $code, $body, null, $info, $headers];
    };
    // First with SSL verify
    [$ok, $code, $body, $err, $info] = $try($url, true);
    if (!$ok && $code === 0) { // network/ssl error, retry without verify
      [$ok2, $code2, $body2, $err2, $info2] = $try($url, false);
      return [
        'ok' => $ok2,
        'status' => $code2,
        'body' => $body2,
        'error' => $ok2 ? null : ($err2 ?: 'cURL error'),
        'effective_url' => $info2['url'] ?? $url
      ];
    }
    // If Archive.org edge host returns 403, try same path on https://archive.org
    if (!$ok && $code === 403 && preg_match('#^ia\d+\.(?:us\.)?archive\.org$#i', $host)) {
      $alt = preg_replace('#^https?://[^/]+#i', 'https://archive.org', $url);
      if (is_string($alt) && $alt !== $url) {
        [$okAlt, $codeAlt, $bodyAlt, $errAlt, $infoAlt] = $try($alt, true);
        if ($okAlt) {
          return [
            'ok' => true,
            'status' => $codeAlt,
            'body' => $bodyAlt,
            'error' => null,
            'effective_url' => $infoAlt['url'] ?? $alt
          ];
        }
      }
      // Try zipview.php as a fallback for browsing ZIP contents
      $q = parse_url($url, PHP_URL_QUERY);
      if (is_string($q)) {
        parse_str($q, $qarr);
        if (!empty($qarr['archive'])) {
          $zip = $qarr['archive'];
          $alt2 = 'https://archive.org/zipview.php?zip=' . rawurlencode($zip);
          [$okAlt2, $codeAlt2, $bodyAlt2, $errAlt2, $infoAlt2] = $try($alt2, true);
          if ($okAlt2) {
            return [
              'ok' => true,
              'status' => $codeAlt2,
              'body' => $bodyAlt2,
              'error' => null,
              'effective_url' => $infoAlt2['url'] ?? $alt2
            ];
          }
        }
      }
    }
    return [
      'ok' => $ok,
      'status' => $code,
      'body' => $body,
      'error' => $ok ? null : ($err ?: 'HTTP ' . $code),
      'effective_url' => $info['url'] ?? $url
    ];
  }
  // Fallback: file_get_contents
  $extraHeaders = "Accept: text/html\r\nAccept-Language: en-US,en;q=0.8\r\nCache-Control: no-cache\r\nUpgrade-Insecure-Requests: 1\r\nAccept-Encoding: gzip, deflate\r\n";
  if ($isArchive) { $extraHeaders .= "Referer: https://archive.org/\r\nOrigin: https://archive.org\r\n"; }
  $ctx = stream_context_create(['http' => [
    'user_agent' => $ua,
    'timeout' => $timeout,
    'header' => $extraHeaders,
    'ignore_errors' => true
  ]]);
  $body = @file_get_contents($url, false, $ctx);
  $status = 0; $error = null;
  if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header)) {
    // Parse HTTP status
    foreach ($http_response_header as $hline) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $m)) { $status = (int)$m[1]; break; }
    }
    // Detect gzip content
    $isGzip = false;
    foreach ($http_response_header as $hline) {
      if (stripos($hline, 'Content-Encoding:') === 0 && stripos($hline, 'gzip') !== false) { $isGzip = true; break; }
    }
    if ($body !== false && $body !== '' && $isGzip) {
      $decoded = @gzdecode($body);
      if ($decoded !== false) { $body = $decoded; }
    }
  }
  if ($body === false) {
    $error = 'file_get_contents failed';
    $body = '';
  }
  // On 403 from ia*.archive.org, attempt retry via https://archive.org host
  if ($status === 403 && preg_match('#^ia\d+\.(?:us\.)?archive\.org$#i', $host)) {
    $alt = preg_replace('#^https?://[^/]+#i', 'https://archive.org', $url);
    if (is_string($alt) && $alt !== $url) {
      $body2 = @file_get_contents($alt, false, $ctx);
      $status2 = 0; $isGzip2 = false;
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hline) {
          if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $m)) { $status2 = (int)$m[1]; }
          if (stripos($hline, 'Content-Encoding:') === 0 && stripos($hline, 'gzip') !== false) { $isGzip2 = true; }
        }
      }
      if ($body2 !== false && $isGzip2) { $dec2 = @gzdecode($body2); if ($dec2 !== false) { $body2 = $dec2; } }
      if ($body2 !== false && ($status2 >= 200 && $status2 < 300)) {
        return [
          'ok' => true,
          'status' => $status2,
          'body' => $body2,
          'error' => null,
          'effective_url' => $alt
        ];
      }
    }
    // Try zipview.php on archive.org as last resort
    $q = parse_url($url, PHP_URL_QUERY);
    if (is_string($q)) {
      parse_str($q, $qarr);
      if (!empty($qarr['archive'])) {
        $zip = $qarr['archive'];
        $alt3 = 'https://archive.org/zipview.php?zip=' . rawurlencode($zip);
        $body3 = @file_get_contents($alt3, false, $ctx);
        $status3 = 0; $isGzip3 = false;
        if (isset($http_response_header) && is_array($http_response_header)) {
          foreach ($http_response_header as $hline) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $m)) { $status3 = (int)$m[1]; }
            if (stripos($hline, 'Content-Encoding:') === 0 && stripos($hline, 'gzip') !== false) { $isGzip3 = true; }
          }
        }
        if ($body3 !== false && $isGzip3) { $dec3 = @gzdecode($body3); if ($dec3 !== false) { $body3 = $dec3; } }
        if ($body3 !== false && ($status3 >= 200 && $status3 < 300)) {
          return [
            'ok' => true,
            'status' => $status3,
            'body' => $body3,
            'error' => null,
            'effective_url' => $alt3
          ];
        }
      }
    }
  }
  return [
    'ok' => ($status >= 200 && $status < 300) && $body !== '',
    'status' => $status,
    'body' => $body,
    'error' => $error,
    'effective_url' => $url
  ];
}

// 1fichier: attempt to unlock a password-protected directory by posting the 'pass' field
function http_fetch_1fichier_with_password(string $url, string $password, int $timeout = 30): array {
  $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
  $headersBase = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
    'Cache-Control: no-cache',
    'Connection: keep-alive',
    'Upgrade-Insecure-Requests: 1'
  ];
  if (function_exists('curl_init')) {
    $cookieFile = tempnam(sys_get_temp_dir(), 'rgsx_cf_');
    $cleanup = function() use ($cookieFile) { if ($cookieFile && is_file($cookieFile)) { @unlink($cookieFile); } };
    $mk = function($method, $postFields = null) use ($url, $timeout, $ua, $headersBase, $cookieFile) {
      $ch = curl_init($url);
      $headers = $headersBase;
      if ($method === 'POST') { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
      
      // Pour le POST, désactiver FOLLOWLOCATION pour voir la redirection
      $followLocation = ($method !== 'POST');
      
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $followLocation,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => true,
      ]);
      if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&'));
        curl_setopt($ch, CURLOPT_REFERER, $url);
      }
      $resp = curl_exec($ch);
      $err = $resp === false ? curl_error($ch) : null;
      $info = curl_getinfo($ch);
      curl_close($ch);
      if ($resp === false) return [false, 0, '', $err, $info, ''];
      $hsz = (int)($info['header_size'] ?? 0);
      $headersTxt = $hsz > 0 ? substr($resp, 0, $hsz) : '';
      $body = $hsz > 0 ? substr($resp, $hsz) : $resp;
      $code = (int)($info['http_code'] ?? 0);
      $ok = ($code >= 200 && $code < 300) && $body !== '';
      return [$ok, $code, $body, $err, $info, $headersTxt];
    };
    // Prime cookies with GET
    [$ok1, $code1, $body1] = $mk('GET');
    // POST password
    [$ok2, $code2, $body2, $err2, $info2] = $mk('POST', ['pass' => $password]);
    // Petit délai pour laisser le serveur traiter le cookie
    usleep(500000); // 0.5 secondes
    // Then GET listing again (important: after POST, server may set cookie)
    [$ok3, $code3, $body3, $err3, $info3] = $mk('GET');
    $cleanup();
    
    // Vérifier si on a toujours le formulaire de mot de passe
    $hasPasswordForm = stripos($body3, 'protégé par mot de passe') !== false || 
                       stripos($body3, 'name="pass"') !== false ||
                       stripos($body3, 'password protected') !== false;
    
    $ok = $ok3 || $ok2 || $ok1;
    $code = $ok3 ? $code3 : ($ok2 ? $code2 : $code1);
    $body = $ok3 ? $body3 : ($ok2 ? $body2 : $body1);
    $info = $ok3 ? $info3 : $info2;
    
    // Si on a toujours le formulaire, essayer le body du POST directement
    if ($hasPasswordForm && $ok2 && $body2 !== '') {
      $hasPasswordForm2 = stripos($body2, 'protégé par mot de passe') !== false || 
                         stripos($body2, 'name="pass"') !== false;
      if (!$hasPasswordForm2) {
        $body = $body2;
        $code = $code2;
        $info = $info2;
      }
    }
    
    return [
      'ok' => $ok,
      'status' => $code,
      'body' => $body,
      'error' => $ok ? null : ($err3 ?: $err2 ?: 'HTTP ' . $code),
      'effective_url' => $info['url'] ?? $url,
      'debug_password_form' => $hasPasswordForm ? 'still present' : 'unlocked'
    ];
  }
  // Fallback without cURL: attempt a POST then use the response body
  $opts = [
    'http' => [
      'method' => 'POST',
      'header' => implode("\r\n", array_merge($headersBase, ['Content-Type: application/x-www-form-urlencoded'])) . "\r\n",
      'content' => http_build_query(['pass' => $password], '', '&'),
      'timeout' => $timeout,
      'ignore_errors' => true,
      'user_agent' => $ua,
    ]
  ];
  $ctx = stream_context_create($opts);
  $body = @file_get_contents($url, false, $ctx);
  $status = 0; if (isset($http_response_header)) { foreach ($http_response_header as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; break; } } }
  return [
    'ok' => ($status >= 200 && $status < 300) && $body !== '',
    'status' => $status,
    'body' => $body ?: '',
    'error' => null,
    'effective_url' => $url
  ];
}

function slugify_folder(string $name): string {
  // Best-effort ASCII transliteration, then slugify to [a-z0-9-]
  $s = $name;
  if (function_exists('iconv')) {
    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($conv !== false) { $s = $conv; }
  }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  return $s !== '' ? $s : 'system';
}

function get_session_images_dir(): string {
  $base = sys_get_temp_dir();
  $dir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rgsx_imgs_' . session_id();
  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }
  return $dir;
}

function persist_upload_to_session_dir(string $tmp, string $originalName): ?string {
  if (!is_readable($tmp)) return null;
  $destDir = get_session_images_dir();
  $safeName = preg_replace('/[^\w\-.]+/u', '_', $originalName);
  $dest = $destDir . DIRECTORY_SEPARATOR . $safeName;
  // If already exists, add suffix
  if (file_exists($dest)) {
    $pi = pathinfo($safeName);
    $base = $pi['filename'] ?? 'img';
    $ext = isset($pi['extension']) && $pi['extension'] !== '' ? ('.' . $pi['extension']) : '';
    $n = 1;
    do { $dest = $destDir . DIRECTORY_SEPARATOR . $base . '_' . $n . $ext; $n++; } while (file_exists($dest));
  }
  // Try move, fallback to copy
  if (@move_uploaded_file($tmp, $dest) || @rename($tmp, $dest) || @copy($tmp, $dest)) {
    return $dest;
  }
  return null;
}

function guess_mime_from_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $map = [
    'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
    'bmp'=>'image/bmp','svg'=>'image/svg+xml'
  ];
  return $map[$ext] ?? 'application/octet-stream';
}

// Resolve relative/absolute href against a base URL
function resolve_url(string $base, string $href): string {
  if ($href === '') return $base;
  if (preg_match('#^https?://#i', $href)) return $href;
  if (strpos($href, '//') === 0) return 'https:' . $href;
  $bp = @parse_url($base);
  if (!$bp || empty($bp['scheme']) || empty($bp['host'])) {
    // Best-effort concat
    if ($href[0] === '/') return $href;
    return rtrim($base, '/') . '/' . ltrim($href, '/');
  }
  $scheme = $bp['scheme']; $host = $bp['host']; $port = isset($bp['port']) ? (':' . $bp['port']) : '';
  $basePath = $bp['path'] ?? '/';
  if ($href[0] === '/') {
    $path = $href;
  } else {
    // Join with base directory
    $dir = substr($basePath, 0, strrpos($basePath, '/') !== false ? strrpos($basePath, '/') + 1 : 0);
    $path = $dir . $href;
  }
  // Normalize /./ and /../
  $parts = [];
  foreach (explode('/', $path) as $seg) {
    if ($seg === '' || $seg === '.') continue;
    if ($seg === '..') { array_pop($parts); continue; }
    $parts[] = $seg;
  }
  $pathNorm = '/' . implode('/', $parts);
  return $scheme . '://' . $host . $port . $pathNorm;
}

// HTTP range fetch (binary-safe), returns [ok,status,body,error,headers]
function http_fetch_range(string $url, string $rangeSpec, int $timeout = 30): array {
  $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
  $isArchive = stripos((parse_url($url, PHP_URL_HOST) ?: ''), 'archive.org') !== false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $headers = [
      'Accept: */*',
      'Cache-Control: no-cache',
      'Connection: keep-alive',
      'Range: bytes=' . $rangeSpec,
    ];
    if ($isArchive) { $headers[] = 'Referer: https://archive.org/'; $headers[] = 'Origin: https://archive.org'; }
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => $ua,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    $err = $resp === false ? curl_error($ch) : null;
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($resp === false) return ['ok'=>false,'status'=>0,'body'=>'','error'=>$err,'headers'=>[]];
    $hsz = (int)($info['header_size'] ?? 0);
    $headersTxt = $hsz > 0 ? substr($resp, 0, $hsz) : '';
    $body = $hsz > 0 ? substr($resp, $hsz) : $resp;
    $code = (int)($info['http_code'] ?? 0);
    return ['ok'=>($code>=200&&$code<300)&&$body!=='','status'=>$code,'body'=>$body,'error'=>null,'headers'=>explode("\r\n", trim($headersTxt))];
  }
  $hdr = "Range: bytes=$rangeSpec\r\nAccept: */*\r\n";
  if ($isArchive) { $hdr .= "Referer: https://archive.org/\r\nOrigin: https://archive.org\r\n"; }
  $ctx = stream_context_create(['http' => [
    'timeout' => $timeout,
    'user_agent' => $ua,
    'header' => $hdr,
    'ignore_errors' => true
  ]]);
  $body = @file_get_contents($url, false, $ctx);
  $status = 0; $headers = isset($http_response_header) ? $http_response_header : [];
  foreach ($headers as $hline) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hline, $m)) { $status = (int)$m[1]; break; } }
  return ['ok'=>($status>=200&&$status<300)&&$body!=='','status'=>$status,'body'=>$body?:'','error'=>null,'headers'=>$headers];
}

function archiveorg_zip_url_from_view_archive(string $viewUrl): ?array {
  $q = parse_url($viewUrl, PHP_URL_QUERY);
  if (!is_string($q)) return null;
  parse_str($q, $qarr);
  if (empty($qarr['archive'])) return null;
  // archive param is like /26/items/ITEM/path/to/file.zip
  $archivePath = $qarr['archive'];
  if ($archivePath[0] !== '/') $archivePath = '/' . $archivePath;
  // Derive item id
  if (!preg_match('#^/(?:\d+|download)/items/([^/]+)/(.+)$#', $archivePath, $m)) return null;
  $item = $m[1];
  $rel = $m[2];
  $downloadUrl = 'https://archive.org/download/' . rawurlencode($item) . '/' . str_replace('%2F','/', rawurlencode($rel));
  return ['item'=>$item, 'rel'=>$rel, 'download'=>$downloadUrl, 'archiveParam'=>$archivePath];
}

function list_zip_entries_via_http_range(string $zipUrl, int $timeout = 30): array {
  // Fetch last 256KB to find EOCD
  $tail = http_fetch_range($zipUrl, '-262144', $timeout);
  if (!$tail['ok'] || $tail['body'] === '') return [];
  $headers = $tail['headers'];
  $contentRange = '';
  foreach ($headers as $h) { if (stripos($h, 'Content-Range:') === 0) { $contentRange = trim(substr($h, strpos($h, ':')+1)); break; } }
  $totalLen = null; $rangeStart = null;
  if ($contentRange && preg_match('#bytes\s+(\d+)-(\d+)/(\d+)#i', $contentRange, $m)) {
    $rangeStart = (int)$m[1]; $totalLen = (int)$m[3];
  }
  $data = $tail['body'];
  $eocdSig = "\x50\x4b\x05\x06";
  $pos = strrpos($data, $eocdSig);
  if ($pos === false) return [];
  $eocd = substr($data, $pos);
  // Need at least 22 bytes minimal EOCD
  if (strlen($eocd) < 22) return [];
  $u = unpack('vdisk/vcdDisk/ventriesDisk/ventriesTotal/VcdSize/VcdOffset/vcomLen', substr($eocd, 4, 18));
  if (!$u) return [];
  $cdSize = $u['cdSize']; $cdOffset = $u['cdOffset']; $comLen = $u['comLen'];
  // Compute absolute byte offset of central directory
  if ($totalLen === null || $rangeStart === null) return [];
  $eocdAbsolute = $rangeStart + $pos;
  // EOCD ends at eocdAbsolute + (len EOCD header inc 22 + comment)
  $cdAbsolute = $cdOffset; // per spec, absolute from file start
  // Fetch the central directory region
  $cdEnd = $cdAbsolute + $cdSize - 1;
  $cdResp = http_fetch_range($zipUrl, $cdAbsolute . '-' . $cdEnd, $timeout);
  if (!$cdResp['ok']) return [];
  $cd = $cdResp['body'];
  $entries = [];
  $i = 0; $cdLen = strlen($cd);
  while ($i + 46 <= $cdLen) {
    if (substr($cd, $i, 4) !== "\x50\x4b\x01\x02") { $i++; continue; }
    $hdr = substr($cd, $i, 46);
    $h = unpack('vverMade/vverNeed/vgp/vcomp/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/veLen/vcLen/vdisk/vint/Vext/Vrel', substr($hdr, 4));
    if (!$h) break;
    $nlen = $h['nlen']; $eLen = $h['eLen']; $cLen = $h['cLen'];
    $name = substr($cd, $i + 46, $nlen);
    $i += 46 + $nlen + $eLen + $cLen;
    if ($name === '' || substr($name, -1) === '/') continue; // skip folders
    $entries[] = [
      'name' => $name,
      'usize' => (int)$h['usize'],
      'csize' => (int)$h['csize']
    ];
  }
  return $entries;
}

// Serve uploaded image preview from session by name
if (isset($_GET['preview_image'])) {
  $req = (string)$_GET['preview_image'];
  $name = basename($req); // basic sanitization
  $images = $_SESSION['images'] ?? [];
  foreach ($images as $im) {
    if (isset($im['name']) && $im['name'] === $name && !empty($im['tmp']) && is_readable($im['tmp'])) {
      $type = $im['type'] ?? null;
      if (!$type) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
        $type = $map[$ext] ?? 'application/octet-stream';
      }
      $path = $im['tmp'];
      $mtime = @filemtime($path) ?: time();
      $size  = @filesize($path) ?: 0;
      $etag  = 'W/"' . md5($name.'|'.$mtime.'|'.$size) . '"';
      // Conditional
      if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
          (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)) {
        header('HTTP/1.1 304 Not Modified');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=604800, immutable');
        exit;
      }
      header('Content-Type: ' . $type);
      header('Content-Length: ' . $size);
      header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
      header('ETag: ' . $etag);
      header('Cache-Control: public, max-age=604800, immutable');
      readfile($path);
      exit;
    }
  }
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'Not found';
  exit;
}

// Render a single platform games table (deferred load to keep initial DOM light)
if (isset($_GET['render_games_table'])) {
  header('Content-Type: text/html; charset=UTF-8');
  $file = (string)($_GET['file'] ?? '');
  $file = trim($file);
  $page = max(1, (int)($_GET['page'] ?? 1));
  $perPage = 100;
  $rows = $_SESSION['platform_games'][$file] ?? null;
  if ($rows === null || !is_array($rows)) {
    http_response_code(404);
    // Debug temporaire
    $availableFiles = array_keys($_SESSION['platform_games'] ?? []);
    echo '<div class="text-muted">Aucune donnée pour ' . htmlspecialchars($file, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . '<br>';
    echo 'Fichiers disponibles: ' . implode(', ', $availableFiles) . '</div>';
    exit;
  }
  $total = count($rows);
  $pages = max(1, (int)ceil($total / $perPage));
  $page = max(1, min($page, $pages));
  $offset = ($page - 1) * $perPage;
  $paginated = array_slice($rows, $offset, $perPage);
  
  // Pagination controls
  if ($pages > 1) {
    echo '<div class="mb-2 d-flex justify-content-between align-items-center">';
  echo '<small class="text-muted">' . sprintf(t('table.systems.summary','Lignes %d-%d sur %d'), ($offset + 1), min($offset + $perPage, $total), $total) . '</small>';
    echo '<div class="btn-group btn-group-sm" role="group">';
    if ($page > 1) {
      echo '<button class="btn btn-outline-secondary" onclick="loadGamesPage(\'' . htmlspecialchars($file, ENT_QUOTES) . '\', ' . ($page-1) . ', this)">« Préc</button>';
    } else {
      echo '<button class="btn btn-outline-secondary disabled">« Préc</button>';
    }
    echo '<span class="btn btn-secondary disabled">Page ' . $page . '/' . $pages . '</span>';
    if ($page < $pages) {
      echo '<button class="btn btn-outline-secondary" onclick="loadGamesPage(\'' . htmlspecialchars($file, ENT_QUOTES) . '\', ' . ($page+1) . ', this)">Suiv »</button>';
    } else {
      echo '<button class="btn btn-outline-secondary disabled">Suiv »</button>';
    }
    echo '</div></div>';
  }
  
  echo '<div class="table-responsive">';
  echo '<table class="table table-sm table-striped align-middle" style="table-layout:fixed; width:100%;">';
  // Définir des largeurs fixes pour éviter le débordement horizontal
    echo '<colgroup>'
    . '<col style="width:55px;">'
    . '<col style="width:20%;">'
    . '<col style="width:49%;">'
    . '<col style="width:90px;">'
    . '<col style="width:150px;">'
    . '</colgroup>';
  echo '<thead><tr><th>#</th><th>' . t('label.game_name','Nom') . '</th><th>' . t('label.url','URL') . '</th><th>' . t('label.size','Taille') . '</th><th></th></tr></thead><tbody>';
  foreach ($paginated as $i => $r) {
    $idx = $offset + $i;
    $name = htmlspecialchars((string)($r[0] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    // URL avec troncature au milieu (préserver début et fin pour différencier les longues URL identiques en prefixe)
    $rawUrl = (string)($r[1] ?? '');
    $size = htmlspecialchars((string)($r[2] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $displayUrl = $rawUrl;
    $maxLen = 70;        // longueur totale déclenchant la troncature
    $keepStart = 35;     // caractères au début conservés
    $keepEnd = 25;       // caractères à la fin conservés
    if (strlen($rawUrl) > $maxLen && ($keepStart + $keepEnd + 1) < strlen($rawUrl)) {
      $displayUrl = substr($rawUrl, 0, $keepStart) . '…' . substr($rawUrl, -$keepEnd);
    }
    $urlFullEsc = htmlspecialchars($rawUrl, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    $urlDisplayEsc = htmlspecialchars($displayUrl, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    echo '<tr>';
    echo '<td>' . ($idx+1) . '</td>';
  // Nom tronqué si trop long
  echo '<td class="text-nowrap games-name" style="overflow:hidden; text-overflow:ellipsis; max-width:100%;" title="' . $name . '">' . $name . '</td>';
  // Cellule URL tronquée avec ellipsis + title pour affichage complet au survol
  echo '<td class="games-url" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%;" title="' . $urlFullEsc . '">' . $urlDisplayEsc . '</td>';
    echo '<td class="text-nowrap">' . $size . '</td>';
    echo '<td class="text-nowrap">';
    echo '<div class="d-flex flex-nowrap gap-1">';
      // Bouton Modifier
  echo '<button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleGameEditRow(' . $idx . ')">' . t('btn.modify','Modifier') . '</button>';
      echo '<form method="post" class="m-0">';
      echo '<input type="hidden" name="action" value="games_delete_row">';
      echo '<input type="hidden" name="active_tab" value="tab-systems">';
      echo '<input type="hidden" name="games_file" value="' . htmlspecialchars($file, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . '">';
      echo '<input type="hidden" name="row_index" value="' . $idx . '">';
  echo '<button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'' . addslashes(t('confirm.delete_row','Supprimer cette ligne ?')) . '\');">' . t('btn.delete','Supprimer') . '</button>';
      echo '</form>';
    echo '</div>';
    echo '</td>';
    echo '</tr>';
    // Ligne édition masquée
    echo '<tr id="game-edit-row-' . $idx . '" class="d-none">';
    echo '<td colspan="5">';
    echo '<form method="post" class="row g-2 align-items-end">';
    echo '<input type="hidden" name="action" value="games_update_row">';
    echo '<input type="hidden" name="active_tab" value="tab-systems">';
    echo '<input type="hidden" name="games_file" value="' . htmlspecialchars($file, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<input type="hidden" name="row_index" value="' . $idx . '">';
  echo '<div class="col-md-4"><label class="form-label">' . t('label.game_name','Nom/Archive') . '</label><input class="form-control form-control-sm" name="game_name" value="' . $name . '"></div>';
  echo '<div class="col-md-6"><label class="form-label">' . t('label.url','URL') . '</label><input class="form-control form-control-sm" name="game_url" value="' . $urlFullEsc . '"></div>';
  echo '<div class="col-md-2"><label class="form-label">' . t('label.size','Taille') . '</label><input class="form-control form-control-sm" name="game_size" value="' . $size . '"></div>';
  echo '<div class="col-12 text-end"><button class="btn btn-sm btn-primary">' . t('btn.save','Enregistrer') . '</button> <button type="button" class="btn btn-sm btn-secondary" onclick="toggleGameEditRow(' . $idx . ')">' . t('btn.cancel','Annuler') . '</button></div>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
  exit;
}

// -------------- Scraper (copied behavior) ----------
function parse_1fichier_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
  $rows = $dom->getElementsByTagName('tr');
  $result = [];
  foreach ($rows as $tr) {
    $tds = $tr->getElementsByTagName('td');
    if ($tds->length < 2) continue;
    $td0 = $tds->item(0);
    if (!$td0) continue;
    $cls = (string)$td0->getAttribute('class');
    if (stripos($cls, 'file-obj') === false) continue; // specific to provided markup
    $a = $td0->getElementsByTagName('a')->item(0);
    if (!$a) continue;
    $fileName = trim($a->textContent);
    if ($fileName === '' || substr($fileName, -1) === '/') continue;
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $validExtensions)) continue;
    $href = $a->getAttribute('href');
    $fullUrl = $isUrl ? resolve_url($urlOrFragment, $href) : $href;
    // size in next td
    $sizeTd = $tds->item(1);
    $fileSize = '';
    if ($sizeTd) {
      $txt = trim($sizeTd->textContent);
      if ($txt !== '') { $fileSize = $txt; }
    }
    $result[] = [$fileName, $fullUrl, $fileSize];
  }
  if ($result) return $result;
  
  // Nouvelle approche : chercher des liens avec data-href ou href contenant 1fichier.com
  $links = $dom->getElementsByTagName('a');
  foreach ($links as $a) {
    $href = $a->getAttribute('href');
    $dataHref = $a->getAttribute('data-href');
    $actualHref = $dataHref ?: $href;
    if ($actualHref === '') continue;
    
    $fileName = trim($a->textContent);
    if ($fileName === '' || substr($fileName, -1) === '/') continue;
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $validExtensions)) continue;
    
    $fullUrl = $isUrl ? resolve_url($urlOrFragment, $actualHref) : $actualHref;
    
    // Chercher la taille dans les éléments siblings ou parent
    $size = '';
    $parent = $a->parentNode;
    if ($parent) {
      $nextSib = $parent->nextSibling;
      while ($nextSib) {
        if ($nextSib->nodeType === XML_ELEMENT_NODE) {
          $txt = trim($nextSib->textContent);
          if (preg_match('/\b\d+(?:[.,]\d+)?\s*[KMGTP]?[Bo]\b/i', $txt)) {
            $size = $txt;
            break;
          }
        }
        $nextSib = $nextSib->nextSibling;
      }
    }
    
    $result[] = [$fileName, $fullUrl, $size];
  }
  if ($result) return $result;
  
  // Fallback to heuristics if structure differs
  $rows = $dom->getElementsByTagName('tr');
  foreach ($rows as $tr) {
    $tds = $tr->getElementsByTagName('td');
    if ($tds->length === 0) continue;
    $a = null;
    foreach ($tds as $td) { $a = $td->getElementsByTagName('a')->item(0); if ($a) break; }
    if (!$a) continue;
    $name = trim($a->textContent);
    if ($name === '' || substr($name, -1) === '/') continue;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $validExtensions)) continue;
    $href = $a->getAttribute('href');
    $full = $isUrl ? resolve_url($urlOrFragment, $href) : $href;
    $size = '';
    foreach ($tds as $i => $td) {
      $txt = trim($td->textContent);
      if ($txt === '' || $i === 0) continue;
      if (preg_match('/\b\d+(?:[.,]\d+)?\s*[KMGTP]?B\b/i', $txt) || preg_match('/\b\d+(?:[.,]\d+)?\s*[KMGTP]o\b/i', $txt)) { $size = $txt; break; }
    }
    $result[] = [$name, $full, $size];
  }
  return $result;
}
function parse_classic_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
    $links = $dom->getElementsByTagName('tr');
    $result = [];
    foreach ($links as $link) {
        $tdLink = $link->getElementsByTagName('td')->item(0);
        $tdSize = $link->getElementsByTagName('td')->item(1);
        if ($tdLink && $tdLink->getAttribute('class') === 'link') {
            $a = $tdLink->getElementsByTagName('a')->item(0);
            if ($a) {
                $fileName = $a->textContent;
                $href = $a->getAttribute('href');
                $fileSize = $tdSize ? trim($tdSize->textContent) : '';
                if ($fileName === "Parent directory/") continue;
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (in_array($extension, $validExtensions)) {
                    $fullUrl = $isUrl ? (rtrim($urlOrFragment, '/') . '/' . ltrim($href, '/')) : $href;
                    $result[] = [$fileName, $fullUrl, $fileSize];
                }
            }
        }
    }
    return $result;
}
function parse_generic_links($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
  $out = [];
  foreach ($dom->getElementsByTagName('a') as $a) {
    $text = trim($a->textContent);
    $href = $a->getAttribute('href');
    if ($href === '') continue;
    if ($text !== '' && stripos($text, 'Parent directory') !== false) continue;
    // Determine candidate filename: prefer link text when it contains an extension, otherwise use href basename
    $name = $text;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $validExtensions)) {
      $path = parse_url($href, PHP_URL_PATH);
      $base = $path ? basename($path) : '';
      if ($base !== '') {
        $base = urldecode($base);
        $ext2 = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext2, $validExtensions)) { $name = $base; $ext = $ext2; }
      }
    }
    if ($ext === '' || !in_array($ext, $validExtensions)) continue;
    if ($name === '' || substr($name, -1) === '/') continue; // skip directories
    $full = $isUrl ? resolve_url($urlOrFragment, $href) : $href;
    $out[] = [$name, $full, ''];
  }
  return $out;
}
function parse_archiveorg_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
    $tables = $dom->getElementsByTagName('table');
    $result = [];
    foreach ($tables as $table) {
        if ($table->getAttribute('class') === 'directory-listing-table') {
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $tds = $tr->getElementsByTagName('td');
                if ($tds->length < 3) continue;
                $firstTd = $tds->item(0);
                $link = $firstTd->getElementsByTagName('a')->item(0);
                if (!$link) continue;
                $fileName = trim($link->textContent);
                if (stripos($fileName, 'Go to parent directory') !== false) continue;
                $href = $link->getAttribute('href');
                $fileSize = trim($tds->item(2)->textContent);
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($extension, $validExtensions)) continue;
                if ($isUrl && !preg_match('/^https?:\/\//', $href)) {
                    $base = rtrim($urlOrFragment, '/') . '/';
                    $fullUrl = $base . ltrim($href, '/');
                } else {
                    $fullUrl = $href;
                }
                $result[] = [$fileName, $fullUrl, $fileSize];
            }
        }
    }
    return $result;
}
function parse_archiveorg_pre($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
    $links = [];
    foreach ($dom->getElementsByTagName('pre') as $pre) {
        foreach ($pre->getElementsByTagName('a') as $a) {
            $fileName = $a->textContent;
            $href = $a->getAttribute('href');
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($extension, $validExtensions)) continue;
            if ($fileName === "Go to parent directory") continue;
            if (preg_match('/^https?:\/\//', $href)) {
                $fullUrl = $href;
            } else {
                $fullUrl = rtrim($urlOrFragment, '/') . '/' . ltrim($href, '/');
            }
            $links[] = [$fileName, $fullUrl, ''];
        }
    }
    return $links;
}
function parse_archiveorg_archext($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
  $results = [];
  foreach ($dom->getElementsByTagName('table') as $table) {
    if ($table->getAttribute('class') !== 'archext') continue;
    // Prendre les tr du tbody si présent, sinon du table
    $trs = [];
    foreach ($table->childNodes as $child) {
      if ($child->nodeName === 'tbody') {
        foreach ($child->childNodes as $tr) {
          if ($tr->nodeName === 'tr') $trs[] = $tr;
        }
      }
    }
    if (!$trs) {
      foreach ($table->childNodes as $tr) {
        if ($tr->nodeName === 'tr') $trs[] = $tr;
      }
    }
    foreach ($trs as $tr) {
      // Sauter les lignes d'en-tête (premier enfant <th>)
      $firstChild = $tr->firstChild;
      if ($firstChild && $firstChild->nodeName === 'th') continue;
      $tds = $tr->getElementsByTagName('td');
      if ($tds->length < 1) continue;
      $firstTd = $tds->item(0);
      $a = $firstTd->getElementsByTagName('a')->item(0);
      if (!$a) continue;
      $fileName = trim($a->textContent);
      if ($fileName === '' || substr($fileName, -1) === '/') continue;
      if (stripos($fileName, 'Go to parent directory') !== false) continue;
      $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
      if (!in_array($extension, $validExtensions)) continue;
      $href = $a->getAttribute('href');
      if (strpos($href, '//') === 0) {
        $fullUrl = 'https:' . $href;
      } elseif ($isUrl && !preg_match('/^https?:\/\//', $href)) {
        $fullUrl = rtrim($urlOrFragment, '/') . '/' . ltrim($href, '/');
      } else { $fullUrl = $href; }
      $size = '';
      if ($tds->length >= 4) {
        $sizeCandidate = trim($tds->item(3)->textContent);
        if ($sizeCandidate !== '' && is_numeric($sizeCandidate)) {
          $size = format_bytes($sizeCandidate);
        } else if ($sizeCandidate !== '') {
          $size = $sizeCandidate;
        }
      }
      $results[] = [$fileName, $fullUrl, $size];
    }
    if ($results) return $results;
  }
  return [];
}
function parse_archiveorg_zipview($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
  // Only attempt on zipview.php pages
  if (stripos($urlOrFragment, 'zipview.php') === false) return [];
  $results = [];
  // Strategy: iterate table rows if present, else fallback to links
  foreach ($dom->getElementsByTagName('table') as $table) {
    $trs = $table->getElementsByTagName('tr');
    foreach ($trs as $tr) {
      $tds = $tr->getElementsByTagName('td');
      if ($tds->length < 1) continue;
      $a = $tds->item(0)->getElementsByTagName('a')->item(0);
      if (!$a) continue;
      $fname = trim($a->textContent);
      if ($fname === '' || substr($fname, -1) === '/') continue;
      $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
      if (!in_array($ext, $validExtensions)) continue;
      $href = $a->getAttribute('href');
      $full = resolve_url($urlOrFragment, $href);
      $size = '';
      // try to find a size cell with KB/MB/GB or digits
      for ($i=1; $i<$tds->length; $i++) {
        $txt = trim($tds->item($i)->textContent);
        if ($txt !== '' && (preg_match('/(\d+\.?\d*\s*[KMGTP]?B)/i', $txt) || is_numeric($txt))) { $size = $txt; break; }
      }
      $results[] = [$fname, $full, $size];
    }
    if ($results) return $results;
  }
  // Fallback: scan all anchors
  foreach ($dom->getElementsByTagName('a') as $a) {
    $fname = trim($a->textContent);
    if ($fname === '' || substr($fname, -1) === '/') continue;
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    if (!in_array($ext, $validExtensions)) continue;
    $href = $a->getAttribute('href');
    if ($href === '') continue;
    $full = resolve_url($urlOrFragment, $href);
    $results[] = [$fname, $full, ''];
  }
  return $results;
}
function parse_auto($html, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html);
    $res = parse_archiveorg_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
    if ($res) return $res;
    $archExtRes = parse_archiveorg_archext($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
    if ($archExtRes) return $archExtRes;
  $zipViewRes = parse_archiveorg_zipview($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
  if ($zipViewRes) return $zipViewRes;
    $preRes = parse_archiveorg_pre($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
    if ($preRes) return $preRes;
  $has1fichier = false; $hasClassic = false;
  foreach ($dom->getElementsByTagName('td') as $td) {
    $cls = (string)$td->getAttribute('class');
    if ($cls && (stripos($cls, 'file-obj') !== false || stripos($cls, 'fichier') !== false)) $has1fichier = true;
    if ($cls === 'link' || stripos($cls, 'link') !== false) $hasClassic = true;
    if ($has1fichier && $hasClassic) break;
  }
    if ($has1fichier) return parse_1fichier_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
    if ($hasClassic)  return parse_classic_table($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
  // Last resort: generic link scan
  $g = parse_generic_links($dom, $sourceLabel, $isUrl, $urlOrFragment, $validExtensions);
  return $g;
}

// -------------- State (Session) --------------------
$_SESSION['systems_list'] = $_SESSION['systems_list'] ?? [];
$_SESSION['platform_games'] = $_SESSION['platform_games'] ?? []; // map: filename => array rows
$_SESSION['images'] = $_SESSION['images'] ?? []; // array of [name, tmp_path, type]
$_SESSION['active_tab'] = $_SESSION['active_tab'] ?? 'tab-scrape';

// -------------- Actions routing --------------------
$action = $_POST['action'] ?? '';
$activeTab = $_POST['active_tab'] ?? $_GET['active_tab'] ?? $_SESSION['active_tab'] ?? 'tab-scrape';
$message = '';
$error = '';
$allowedExtensions = [
    '40t','68k','7z','a0','a26','a52','a78','abs','actionmax','adf','adl','adm','ads','adz','app','apd','atr','atm','atx','auto','axf','b0','bat','bg1','bg2','bbc','bin','bml','boom3','bs','bsx','c','cas','cbn','ccc','cci','ccd','cdi','cdm','cdg','cdr','chd','cmd','cof','col','cqm','cqi','croft','crt','cso','csw','cue','d64','d77','d81','d88','daphne','dat','ddp','dfi','dim','dk','dms','dol','dos','dosbox','dosz','dsk','dup','dx1','dx2','easyrpg','eduke32','elf','exe','fba','fds','fig','fpt','frd','g64','gam','game','gbc','gcz','gd3','gd7','gdi','gem','gen','gg','gz','hb','hdf','hdm','hex','hfe','how','hypseus','ikemen','ima','img','int','ipf','ipk3','iso','iwd','iwd2','j64','jag','jfd','kip','lbd','lha','libretro','lnk','love','lua','lutro','lux','lx','m3u','m3u8','m5','m7','md','mdf','mds','mfi','mfm','mgw','min','msa','mugen','mx1','mx2','n64','nca','ndd','neo','nes','nib','nrg','nro','nso','nx','ogv','p','p8','pak','pb','pbp','pc','pce','pk3','png','po','prg','prx','pst','psv','pxp','rar','raze','rem','ri','rom','rp9','rpk','rpx','rsdk','rvz','sbw','sc','scummvm','sfc','sg','sgd','smc','sms','solarus','squashfs','st','sv','swf','swc','symbian','t64','t77','table','tap','tar','tfd','tgc','tic','toc','txt','u88','uae','uef','ufi','uze','v32','v64','vb','vboy','vec','vpk','vpx','wad','wav','wbfs','wia','win','windows','wine','wsquashfs','woz','ws','wsc','wua','wud','wux','xbe','xcp','xci','xdf','xex','xfd','zip','zar','zcxi','zso'
];

try {
  switch ($action) {
    case 'import_data_zip':
      // Accept either uploaded ZIP or a URL to a ZIP
      $zipTmp = '';
      if (!empty($_FILES['data_zip']['name']) && $_FILES['data_zip']['error'] === UPLOAD_ERR_OK) {
        $zipTmp = $_FILES['data_zip']['tmp_name'];
      } else {
        $url = trim($_POST['data_zip_url'] ?? '');
        if ($url) {
          $ctx = stream_context_create(['http'=>['timeout'=>45, 'user_agent'=>'Mozilla/5.0']]);
          $data = @file_get_contents($url, false, $ctx);
          $http_response = isset($http_response_header) ? implode(' | ', $http_response_header) : '';
          if ($data !== false) {
            $zipTmp = tempnam(sys_get_temp_dir(), 'rgsx_data_');
            @file_put_contents($zipTmp, $data);
          } else {
            $error = 'Téléchargement du ZIP impossible.';
            $debugLog = '['.date('Y-m-d H:i:s')."] ECHEC ZIP URL: $url\nHTTP: $http_response\n";
            file_put_contents(__DIR__ . '/assets/debug.log', $debugLog, FILE_APPEND);
          }
        } else {
          $error = 'Aucun fichier ni URL fournis.';
        }
      }
      if ($zipTmp && class_exists('ZipArchive')) {
        $za = new ZipArchive();
        if ($za->open($zipTmp) === true) {
          $addedSystems = false; $gamesCount = 0; $imagesCount = 0; $seen = [];
          for ($i=0; $i<$za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if (!$name) continue;
            $norm = strtolower(strtr($name, '\\', '/'));
            $base = basename($norm); // lowercased helper for matching only
            $baseOrig = basename(strtr($name, '\\', '/')); // original case for storage
            if (count($seen) < 30) { $seen[] = $norm; }

            // systems_list.json anywhere in the archive
            if ($base === 'systems_list.json') {
              $json = $za->getFromIndex($i);
              $arr = @json_decode($json, true);
              if (is_array($arr)) { $_SESSION['systems_list'] = $arr; $addedSystems = true; }
              continue;
            }
            // games/*.json from any nesting level (…/games/xxx.json)
            if (substr($norm, -5) === '.json' && (strpos($norm, '/games/') !== false || strpos($norm, 'games/') === 0)) {
              $json = $za->getFromIndex($i);
              $arr = @json_decode($json, true);
              if (is_array($arr)) { $_SESSION['platform_games'][$baseOrig] = $arr; $gamesCount++; }
              continue;
            }
            // images/* from any nesting level (…/images/filename)
            if (strpos($norm, '/images/') !== false || strpos($norm, 'images/') === 0) {
              if ($base === '' || $base === '.' || $base === '..' || substr($norm, -1) === '/') continue; // skip dirs
              $data = $za->getFromIndex($i);
              if ($data !== false) {
                $destDir = get_session_images_dir();
                $dest = $destDir . DIRECTORY_SEPARATOR . $baseOrig;
                @file_put_contents($dest, $data);
                $_SESSION['images'][] = ['name'=>$baseOrig, 'tmp'=>$dest, 'type'=>guess_mime_from_ext($baseOrig)];
                $imagesCount++;
              }
              continue;
            }
          }
          $za->close();
          $messageParts = [];
          if ($addedSystems) {
            // Ensure default platform_image for empty entries
            foreach ($_SESSION['systems_list'] as $k => $sys) {
              $pn = (string)($sys['platform_name'] ?? '');
              $pi = (string)($sys['platform_image'] ?? '');
              if ($pn !== '' && $pi === '') { $_SESSION['systems_list'][$k]['platform_image'] = $pn . '.png'; }
            }
            $messageParts[] = 'systems_list.json chargé';
          }
          if ($gamesCount>0) $messageParts[] = $gamesCount.' plateforme(s)';
          if ($imagesCount>0) $messageParts[] = $imagesCount.' image(s)';
          if (!empty($messageParts)) {
            $message = 'DATA ZIP importé: ' . implode(', ', $messageParts);
          } else {
            $examples = $seen ? (' Exemples trouvés: ' . implode(', ', array_slice($seen, 0, 5))) : '';
            $message = 'DATA ZIP importé: aucune donnée reconnue.' . $examples . ' (Attendu: systems_list.json, dossier games/ avec *.json, dossier images/ avec fichiers)';
          }
        } else {
          $error = 'Impossible d\'ouvrir le ZIP.';
        }
        // Clean tmp if downloaded
        if (!empty($_POST['data_zip_url']) && is_file($zipTmp)) { @unlink($zipTmp); }
      } else if ($zipTmp) {
        $error = 'Support ZIP indisponible (ZipArchive manquant).';
        if (!empty($_POST['data_zip_url']) && is_file($zipTmp)) { @unlink($zipTmp); }
      }
      break;
    // Scrape
    case 'scrape':
      $urls = trim($_POST['urls'] ?? '');
      $scrapePassword = trim($_POST['scrape_password'] ?? '');
      // By default, allow all known extensions (not only zip)
      $exts = isset($_POST['extensions']) ? array_map('strtolower', (array)$_POST['extensions']) : $allowedExtensions;
      $validExtensions = array_values(array_intersect($exts, $allowedExtensions));
      if (!$urls) { $error = 'Entrée vide.'; break; }
      if (!$validExtensions) { $error = 'Aucune extension valide.'; break; }
      $inputs = [];
      if (is_html_block($urls)) { $inputs = [$urls]; }
      else { $inputs = array_filter(array_map('trim', preg_split('/[\n,]+/', $urls))); }
      // Normalize schemeless Archive.org lines to valid URLs
      $inputs = array_map(function($line){
        if ($line === '') return $line;
        // Prepend https:// if it looks like a domain/path
        if (preg_match('#^//#', $line)) { return 'https:' . $line; }
        if (!preg_match('#^https?://#i', $line)) {
          if (preg_match('#^(?:www\.)?archive\.org/#i', $line)) return 'https://' . $line;
          if (preg_match('#^ia\d+\.(?:us\.)?archive\.org/#i', $line)) return 'https://' . $line;
        }
        return $line;
      }, $inputs);
      $scraped = [];
      $debugHtmls = [];
      // Apply URL encoding normalization to inputs (handles spaces in paths/queries)
      $inputs = array_map('normalize_url_like', $inputs);
      foreach ($inputs as $index => $input) {
        $isUrl = is_url($input);
        $isHtml = !$isUrl && is_html_block($input);
        $label = $input !== '' ? $input : ('Entrée #' . ($index+1));
        if (!$isUrl && !$isHtml) {
          // Enregistrer un debug pour les lignes ignorées
          $debugHtmls[] = [
            'label' => $label,
            'status' => 0,
            'error' => 'Entrée ignorée: ni URL valide ni bloc HTML',
            'effective_url' => '',
            'html' => substr($input, 0, 2000)
          ];
          continue;
        }
        $usedPassword = false;
        if ($isUrl) {
          // If it's a 1fichier directory URL and a password is provided, try to unlock with POST first
          $u = @parse_url($input);
          $host = strtolower($u['host'] ?? '');
          $path = $u['path'] ?? '';
          if ($scrapePassword !== '' && strpos($host, '1fichier.com') !== false && is_string($path) && strpos($path, '/dir/') === 0) {
            $resp = http_fetch_1fichier_with_password($input, $scrapePassword, 30);
            $usedPassword = true;
          } else {
            $resp = http_fetch($input, 30);
          }
          $html = (string)($resp['body'] ?? '');
        } else { $html = $input; $resp = ['ok'=>true,'status'=>200,'error'=>null,'effective_url'=>null]; }
        // Base URL for parsing (handle redirects/fallbacks)
        $baseForParse = $isUrl ? ((string)($resp['effective_url'] ?? $input) ?: $input) : '';
        $parsed = [];
        if ($html !== '') {
          $parsed = parse_auto($html, $label, $isUrl, $baseForParse, $validExtensions);
        }
        // If Archive.org 403 on view_archive and parsing failed, try ZIP central directory listing via HTTP range
        if (empty($parsed) && $isUrl && (int)($resp['status'] ?? 0) === 403 && stripos($input, 'view_archive.php') !== false) {
          $info = archiveorg_zip_url_from_view_archive($input);
          if ($info) {
            $zipEntries = list_zip_entries_via_http_range($info['download'], 30);
            if (!empty($zipEntries)) {
              foreach ($zipEntries as $ze) {
                $fname = $ze['name'];
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!in_array($ext, $validExtensions)) continue;
                $size = $ze['usize'] > 0 ? format_bytes($ze['usize']) : '';
                // Build a practical link via zipview.php fallback
                $fileUrl = 'https://archive.org/zipview.php?zip=' . rawurlencode($info['archiveParam']) . '&file=' . rawurlencode($fname);
                $parsed[] = [$fname, $fileUrl, $size];
              }
            }
          }
        }
        $scraped[] = ['label' => $label, 'rows' => $parsed];
        $dbgLabel = $label . ($usedPassword ? ' [PW]' : '');
        
        // Compter les liens dans le HTML pour debug
        $linkCount = 0;
        $tableCount = 0;
        if ($html !== '') {
          $linkCount = substr_count(strtolower($html), '<a ');
          $tableCount = substr_count(strtolower($html), '<table');
        }
        
        $debugHtmls[] = [
          'label' => $dbgLabel,
          'status' => (int)($resp['status'] ?? 0),
          'error' => (string)($resp['error'] ?? ''),
          'effective_url' => (string)($resp['effective_url'] ?? ''),
          'parsed_count' => count($parsed),
          'link_count' => $linkCount,
          'table_count' => $tableCount,
          'password_debug' => (string)($resp['debug_password_form'] ?? ''),
          'html' => substr($html, 0, 8000) // Augmenté pour mieux diagnostiquer 1fichier
        ];
      }
      $_SESSION['last_scrape'] = $scraped;
      $message = 'Scraping terminé.';
      // Toujours afficher un bloc Debug dans ce mode pour faciliter le diagnostic
      $message .= '<details><summary>Debug HTML</summary>';
      if (empty($debugHtmls)) {
        $message .= '<div class="text-muted">Aucun élément à diagnostiquer (aucune entrée reconnue).</div>';
      } else {
        foreach ($debugHtmls as $dbg) {
          $message .= '<div style="margin-bottom:6px"><b>' . htmlspecialchars($dbg['label']) . '</b>';
          if (!empty($dbg['effective_url'])) { $message .= ' <small class="text-muted">(' . htmlspecialchars($dbg['effective_url']) . ')</small>'; }
          $message .= '<br><small>HTTP ' . htmlspecialchars((string)$dbg['status']) . (!empty($dbg['error']) ? (' — ' . htmlspecialchars($dbg['error'])) : '') . '</small>';
          $message .= '<br><small class="text-info">Parsed: ' . $dbg['parsed_count'] . ' fichiers | Links: ' . $dbg['link_count'] . ' | Tables: ' . $dbg['table_count'];
          if (!empty($dbg['password_debug'])) { $message .= ' | Password: <span class="text-warning">' . htmlspecialchars($dbg['password_debug']) . '</span>'; }
          $message .= '</small>';
          $message .= '<pre style="max-height:200px;overflow:auto;font-size:11px;background:#222;color:#eee;">' . htmlspecialchars($dbg['html']) . '</pre></div>';
        }
      }
      $message .= '</details>';
      break;

    case 'attach_scrape_to_platform':
      $platform_file = trim($_POST['platform_file'] ?? '');
      $scrape_index = (int)($_POST['scrape_index'] ?? -1);
      if ($platform_file === '' || $scrape_index < 0) { $error = t('err.missing_params'); break; }
      $batFolder = trim($_POST['batocera_folder'] ?? '');
      $items = $_SESSION['last_scrape'][$scrape_index]['rows'] ?? [];
      if (!is_array($items)) $items = [];
      // Append to existing and deduplicate by name+url (case-insensitive)
      $existing = $_SESSION['platform_games'][$platform_file] ?? [];
      $merged = array_merge(is_array($existing)?$existing:[], $items);
      $seen = [];
      $unique = [];
      foreach ($merged as $r) {
        $n = (string)($r[0] ?? '');
        $u = (string)($r[1] ?? '');
        $s = (string)($r[2] ?? '');
        $k = strtolower($n) . '|' . strtolower($u);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $unique[] = [$n,$u,$s];
      }
      $_SESSION['platform_games'][$platform_file] = $unique;
      // Also attach/create an entry in systems_list using the provided name (without extension)
      $base = pathinfo($platform_file, PATHINFO_FILENAME);
      $platformName = trim($base);
      if ($platformName !== '') {
        $existsIdx = -1;
        foreach ($_SESSION['systems_list'] as $i => $sys) {
          $pn = (string)($sys['platform_name'] ?? '');
          if (strcasecmp($pn, $platformName) === 0) { $existsIdx = $i; break; }
        }
        if ($existsIdx === -1) {
          $folder = $batFolder !== '' ? $batFolder : slugify_folder($platformName);
          $_SESSION['systems_list'][] = [
            'platform_name' => $platformName,
            'folder' => $folder,
            'platform_image' => $platformName . '.png'
          ];
        }
      }
  $message = sprintf(t('msg.games.attached'), h($platform_file)) . ($platformName ? sprintf(t('misc.attached_system_added'), h($platformName)) : '');
      break;

    case 'attach_all_scrapes_to_platform':
      $platform_file = trim($_POST['platform_file'] ?? '');
      $batFolder = trim($_POST['batocera_folder'] ?? '');
      if ($platform_file === '') { $error = t('err.missing_params'); break; }
      $all = $_SESSION['last_scrape'] ?? [];
      $items = [];
      if (is_array($all)) {
        foreach ($all as $entry) {
          if (!empty($entry['rows']) && is_array($entry['rows'])) {
            $items = array_merge($items, $entry['rows']);
          }
        }
      }
      // Append to existing and deduplicate
      $existing = $_SESSION['platform_games'][$platform_file] ?? [];
      $merged = array_merge(is_array($existing)?$existing:[], $items);
      $seen = [];
      $unique = [];
      foreach ($merged as $r) {
        $n = (string)($r[0] ?? '');
        $u = (string)($r[1] ?? '');
        $s = (string)($r[2] ?? '');
        $k = strtolower($n) . '|' . strtolower($u);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $unique[] = [$n,$u,$s];
      }
      $_SESSION['platform_games'][$platform_file] = $unique;
      // Ensure systems_list has an entry for this platform
      $base = pathinfo($platform_file, PATHINFO_FILENAME);
      $platformName = trim($base);
      if ($platformName !== '') {
        $existsIdx = -1;
        foreach ($_SESSION['systems_list'] as $i => $sys) {
          $pn = (string)($sys['platform_name'] ?? '');
          if (strcasecmp($pn, $platformName) === 0) { $existsIdx = $i; break; }
        }
        if ($existsIdx === -1) {
          $folder = $batFolder !== '' ? $batFolder : slugify_folder($platformName);
          $_SESSION['systems_list'][] = [
            'platform_name' => $platformName,
            'folder' => $folder,
            'platform_image' => $platformName . '.png'
          ];
        }
      }
      $message = sprintf(t('msg.games.attached'), h($platform_file)) . ($platformName ? sprintf(t('misc.attached_system_added'), h($platformName)) : '');
      break;

    // Systems list
    case 'systems_new':
  $_SESSION['systems_list'] = [];
  $message = t('msg.systems.new');
      break;
    case 'systems_upload':
      if (isset($_FILES['systems_file']) && $_FILES['systems_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['systems_file']['tmp_name']);
        $arr = json_decode($content, true);
        if (is_array($arr)) {
          // Autopopulate platform_image by default when empty
          foreach ($arr as $k => $sys) {
            $pn = (string)($sys['platform_name'] ?? '');
            $pi = (string)($sys['platform_image'] ?? '');
            if ($pn !== '' && $pi === '') { $arr[$k]['platform_image'] = $pn . '.png'; }
          }
          $_SESSION['systems_list'] = $arr; $message = t('msg.systems.file_loaded');
        }
        else { $error = t('err.json_invalid'); }
      } else { $error = t('err.upload_failed'); }
      break;
    case 'systems_add':
      $pn = trim($_POST['platform_name'] ?? '');
      $fd = trim($_POST['folder'] ?? '');
  $pi = '';
      // Optional image upload
      if (isset($_FILES['platform_image_file']) && $_FILES['platform_image_file']['error'] === UPLOAD_ERR_OK) {
        $upName = $_FILES['platform_image_file']['name'] ?? '';
        $upTmp  = $_FILES['platform_image_file']['tmp_name'] ?? '';
        $upType = $_FILES['platform_image_file']['type'] ?? '';
        if ($upTmp && $upName) {
          $stored = persist_upload_to_session_dir($upTmp, $upName);
          if ($stored) {
            $_SESSION['images'][] = ['name' => basename($stored), 'tmp' => $stored, 'type' => $upType];
            $pi = basename($stored);
          }
        }
      }
      if ($pn && $fd) {
        if ($pi === '') { $pi = $pn . '.png'; }
        $_SESSION['systems_list'][] = ['platform_name'=>$pn,'folder'=>$fd,'platform_image'=>$pi];
        $message = t('msg.systems.added');
      } else { $error = t('err.fields_required'); }
      break;
    case 'systems_update':
      $idx = (int)($_POST['index'] ?? -1);
      if ($idx >= 0 && isset($_SESSION['systems_list'][$idx])) {
        $pn = trim($_POST['platform_name'] ?? '');
        $fd = trim($_POST['folder'] ?? '');
        // Keep existing image name by default when no new file is chosen
        $existingPi = (string)($_SESSION['systems_list'][$idx]['platform_image'] ?? '');
        $pi = $existingPi;
        // Optional image upload replacement
        if (isset($_FILES['platform_image_file']) && $_FILES['platform_image_file']['error'] === UPLOAD_ERR_OK) {
          $upName = $_FILES['platform_image_file']['name'] ?? '';
          $upTmp  = $_FILES['platform_image_file']['tmp_name'] ?? '';
          $upType = $_FILES['platform_image_file']['type'] ?? '';
          if ($upTmp && $upName) {
            $stored = persist_upload_to_session_dir($upTmp, $upName);
            if ($stored) {
              $_SESSION['images'][] = ['name' => basename($stored), 'tmp' => $stored, 'type' => $upType];
              $pi = basename($stored);
            }
          }
        }
        if ($pi === '' && $pn !== '') { $pi = $pn . '.png'; }
        $_SESSION['systems_list'][$idx] = [
          'platform_name' => $pn,
          'folder' => $fd,
          'platform_image' => $pi,
        ];
        $message = t('msg.systems.updated');
      } else { $error = t('err.index_invalid'); }
      break;
    
    case 'systems_update_with_rename':
      $idx = (int)($_POST['index'] ?? -1);
      if ($idx >= 0 && isset($_SESSION['systems_list'][$idx])) {
        $pn = trim($_POST['platform_name'] ?? '');
        $fd = trim($_POST['folder'] ?? '');
        $oldPlatformName = trim($_POST['old_platform_name'] ?? '');
        
        // Gestion du changement de nom de fichier
        if ($oldPlatformName !== '' && $pn !== '' && $oldPlatformName !== $pn) {
          $oldFile = $oldPlatformName . '.json';
          $newFile = $pn . '.json';
          
          // Si le fichier existe dans les jeux, le renommer
          if (isset($_SESSION['platform_games'][$oldFile])) {
            $_SESSION['platform_games'][$newFile] = $_SESSION['platform_games'][$oldFile];
            unset($_SESSION['platform_games'][$oldFile]);
          }
        }
        
        // Keep existing image name by default when no new file is chosen
        $existingPi = (string)($_SESSION['systems_list'][$idx]['platform_image'] ?? '');
        $pi = $existingPi;
        // Optional image upload replacement
        if (isset($_FILES['platform_image_file']) && $_FILES['platform_image_file']['error'] === UPLOAD_ERR_OK) {
          $upName = $_FILES['platform_image_file']['name'] ?? '';
          $upTmp  = $_FILES['platform_image_file']['tmp_name'] ?? '';
          $upType = $_FILES['platform_image_file']['type'] ?? '';
          if ($upTmp && $upName) {
            $stored = persist_upload_to_session_dir($upTmp, $upName);
            if ($stored) {
              $_SESSION['images'][] = ['name' => basename($stored), 'tmp' => $stored, 'type' => $upType];
              $pi = basename($stored);
            }
          }
        }
        if ($pi === '' && $pn !== '') { $pi = $pn . '.png'; }
        $_SESSION['systems_list'][$idx] = [
          'platform_name' => $pn,
          'folder' => $fd,
          'platform_image' => $pi,
        ];
        $message = t('msg.systems.updated') . ($oldPlatformName !== $pn && $oldPlatformName !== '' ? ' (Fichier renommé)' : '');
      } else { $error = t('err.index_invalid'); }
      break;
      
    case 'platform_delete_complete':
      $idx = (int)($_POST['platform_index'] ?? -1);
      $platformFile = trim($_POST['platform_file'] ?? '');
      if ($idx >= 0 && isset($_SESSION['systems_list'][$idx]) && $platformFile !== '') {
        // Supprimer de la liste des systèmes
        array_splice($_SESSION['systems_list'], $idx, 1);
        // Supprimer le fichier de jeux associé
        if (isset($_SESSION['platform_games'][$platformFile])) {
          unset($_SESSION['platform_games'][$platformFile]);
        }
        $message = 'Plateforme et ses jeux supprimés avec succès.';
      } else { $error = t('err.index_invalid'); }
      break;
      
    case 'systems_delete':
      $idx = (int)($_POST['index'] ?? -1);
  if ($idx >= 0 && isset($_SESSION['systems_list'][$idx])) { array_splice($_SESSION['systems_list'],$idx,1); $message=t('msg.systems.deleted'); }
      break;
    case 'systems_download':
      $json = json_encode(array_values($_SESSION['systems_list']), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="systems_list.json"');
      echo $json; exit;

    // Platform games editor
    case 'games_upload':
      if (isset($_FILES['platform_json'])) {
        $files = $_FILES['platform_json'];
        $isMultiple = is_array($files['name']);
        $count = $isMultiple ? count($files['name']) : 1;
  $loaded = 0; $failed = 0;
        $loadedNames = [];
        $failedDetails = [];

        $errMsg = function($code) {
          switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'Fichier trop volumineux (limites serveur).';
            case UPLOAD_ERR_PARTIAL: return 'Transfert interrompu (partiel).';
            case UPLOAD_ERR_NO_FILE: return t('err.file_not_selected');
            case UPLOAD_ERR_NO_TMP_DIR: return 'Dossier temporaire manquant sur le serveur.';
            case UPLOAD_ERR_CANT_WRITE: return 'Impossible d\'écrire sur le disque.';
            case UPLOAD_ERR_EXTENSION: return 'Transfert bloqué par une extension PHP.';
          }
          return 'Erreur inconnue.';
        };

        // Server-side cap to 20 files per selection (encourage ZIP for large batches)
        $serverCap = 20;
        if ($count > $serverCap) {
          $error = sprintf(t('err.too_many_files'), $count, $serverCap);
          $count = $serverCap;
        }

        for ($i=0; $i<$count; $i++) {
          $err = $isMultiple ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE);
          $name = $isMultiple ? ($files['name'][$i] ?? 'platform.json') : ($files['name'] ?? 'platform.json');
          $tmp  = $isMultiple ? ($files['tmp_name'][$i] ?? '') : ($files['tmp_name'] ?? '');

          if ($err !== UPLOAD_ERR_OK) {
            $failed++; $failedDetails[] = [$name, $errMsg($err)]; continue;
          }
          if (!$tmp || !is_readable($tmp)) {
            $failed++; $failedDetails[] = [$name, t('err.temp_file_missing')]; continue;
          }

          $lower = strtolower($name);
          if (substr($lower, -4) === '.zip') {
            // Handle a zip: iterate entries and import any .json files
            if (!class_exists('ZipArchive')) {
              $failed++; $failedDetails[] = [$name, 'Support ZIP indisponible (extension ZipArchive manquante).'];
            } else {
              $za = new ZipArchive();
              if ($za->open($tmp) === true) {
              for ($zi=0; $zi<$za->numFiles; $zi++) {
                $zname = $za->getNameIndex($zi);
                if (!$zname || substr(strtolower($zname), -5) !== '.json' ) continue;
                $data = $za->getFromIndex($zi);
                if ($data === false) { $failed++; $failedDetails[] = [$zname, 'Lecture ZIP échouée.']; continue; }
                $arr = json_decode($data, true);
                if (is_array($arr)) {
                  $baseOrig = basename(strtr($zname, '\\', '/'));
                  $_SESSION['platform_games'][$baseOrig] = $arr; $loaded++; $loadedNames[] = $baseOrig;
                } else {
                  $failed++; $failedDetails[] = [$zname, t('err.json_invalid')];
                }
              }
                $za->close();
              } else {
                $failed++; $failedDetails[] = [$name, 'ZIP illisible.'];
              }
            }
          } else {
            $content = @file_get_contents($tmp);
            $arr = json_decode($content, true);
            if (is_array($arr)) {
              $_SESSION['platform_games'][$name] = $arr; $loaded++; $loadedNames[] = $name;
            } else {
              $failed++; $failedDetails[] = [$name, t('err.json_invalid_or_not_array')];
            }
          }
        }

        if ($loaded > 0) {
          $preview = implode(', ', array_slice($loadedNames, 0, 5));
          $more = $loaded > 5 ? (' +'.($loaded-5).' autre(s)') : '';
          $message = 'Plateforme(s) chargée(s): ' . $loaded . ($preview ? (' ['.$preview.$more.']') : '');
        }
        if ($failed > 0) {
          $parts = [];
          foreach ($failedDetails as $fd) { $parts[] = $fd[0] . ' (' . $fd[1] . ')'; }
          $error = 'Échec(s): ' . $failed . ' — ' . implode('; ', array_slice($parts, 0, 6)) . (count($parts)>6 ? ' ...' : '');
        }

        // Warn if selection size likely exceeded PHP max_file_uploads
        $maxUploads = (int)ini_get('max_file_uploads');
        if ($isMultiple && $maxUploads > 0 && $count >= $maxUploads) {
          $warn = sprintf(t('warn.maybe_truncated'), $maxUploads);
          $message = $message ? ($message . ' — ' . $warn) : $warn;
        }
  } else { $error = t('err.upload_failed'); }
      break;
    case 'games_add_row':
      $file = $_POST['games_file'] ?? '';
      $fname = trim($_POST['game_name'] ?? '');
      $furl = trim($_POST['game_url'] ?? '');
      $fsize = trim($_POST['game_size'] ?? '');
      if ($file && isset($_SESSION['platform_games'][$file])) {
        $_SESSION['platform_games'][$file][] = [$fname,$furl,$fsize];
        $message = t('msg.games.row_added');
      } else { $error = t('err.select_valid_platform_file'); }
      break;
    case 'games_update_row':
      $file = $_POST['games_file'] ?? '';
      $idx = (int)($_POST['row_index'] ?? -1);
      $fname = trim($_POST['game_name'] ?? '');
      $furl = trim($_POST['game_url'] ?? '');
      $fsize = trim($_POST['game_size'] ?? '');
      if ($file && isset($_SESSION['platform_games'][$file]) && $idx >= 0 && isset($_SESSION['platform_games'][$file][$idx])) {
        $_SESSION['platform_games'][$file][$idx] = [$fname,$furl,$fsize];
        $message = t('msg.games.row_updated');
      } else { $error = t('err.cannot_update_row'); }
      break;
    case 'games_delete_row':
      $file = $_POST['games_file'] ?? '';
      $idx = (int)($_POST['row_index'] ?? -1);
      if ($file && isset($_SESSION['platform_games'][$file]) && $idx>=0 && isset($_SESSION['platform_games'][$file][$idx])) {
        array_splice($_SESSION['platform_games'][$file], $idx, 1);
  $message = t('msg.games.row_deleted');
      }
      break;
    case 'games_download_one':
      $file = $_POST['games_file'] ?? '';
      if ($file && isset($_SESSION['platform_games'][$file])) {
        $json = json_encode($_SESSION['platform_games'][$file], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        echo $json; exit;
  } else { $error = t('err.file_not_selected'); }
      break;

    case 'games_delete_file':
      $file = $_POST['games_file'] ?? '';
      if ($file && isset($_SESSION['platform_games'][$file])) {
        unset($_SESSION['platform_games'][$file]);
        $message = sprintf(t('msg.games.platform_deleted'), h($file));
      } else {
        $error = t('err.platform_file_missing');
      }
      break;
    case 'games_clear_all':
      $_SESSION['platform_games'] = [];
      $message = t('msg.games.all_cleared');
      break;
      
    case 'games_clear_platform':
      $file = $_POST['games_file'] ?? '';
      if ($file && isset($_SESSION['platform_games'][$file])) {
        $_SESSION['platform_games'][$file] = [];
        $message = sprintf(t('msg.games.platform_cleared'), h($file));
      } else {
        $error = t('err.file_not_selected');
      }
      break;

    // Images (kept for compatibility, UI removed)
    case 'images_upload':
      if (!empty($_FILES['images']['name'][0])) {
        $count = count($_FILES['images']['name']);
        $added = 0;
        for ($i=0; $i<$count; $i++) {
          if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
            $orig = $_FILES['images']['name'][$i];
            $tmp  = $_FILES['images']['tmp_name'][$i];
            $typ  = $_FILES['images']['type'][$i];
            $stored = persist_upload_to_session_dir($tmp, $orig);
            if ($stored) {
              $_SESSION['images'][] = ['name' => basename($stored), 'tmp' => $stored, 'type' => $typ];
              $added++;
            }
          }
        }
        $message = $added > 0 ? ("Images ajoutées: $added") : '';
      }
      break;
    case 'images_clear':
      $_SESSION['images'] = [];
      $message = 'Images supprimées.';
      break;

    // Package ZIP
    case 'build_zip':
      $zip = new ZipArchive();
      $tmpZip = tempnam(sys_get_temp_dir(), 'rgsx_zip_');
      if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) { $error = 'Impossible de créer le ZIP.'; break; }
      // systems_list.json
      $systemsJson = json_encode(array_values($_SESSION['systems_list']), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      $zip->addFromString('systems_list.json', $systemsJson);
      // games/
      foreach ($_SESSION['platform_games'] as $fname => $rows) {
        $json = json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $filename = basename($fname);
        // Ensure .json extension
        if (substr($filename, -5) !== '.json') {
          $filename .= '.json';
        }
        $zip->addFromString('games/'.$filename, $json);
      }
      // images/
      foreach ($_SESSION['images'] as $img) {
        $zip->addFile($img['tmp'], 'images/'.basename($img['name']));
      }
      $zip->close();
      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="games.zip"');
      header('Content-Length: '.filesize($tmpZip));
      readfile($tmpZip);
      @unlink($tmpZip);
      exit;
  }
} catch (Throwable $e) {
    $error = 'Erreur: ' . $e->getMessage();
}

// Persist active tab in session for next render
$_SESSION['active_tab'] = $activeTab;

// Persist pagination states
$systemsPage = (int)($_POST['systems_page'] ?? $_GET['systems_page'] ?? $_SESSION['systems_page'] ?? 1);
// Gestion du nombre d'éléments par page (plateformes)
$allowedPerPage = [10,20,25,50,100];
$systemsPerPage = (int)($_POST['systems_per_page'] ?? $_GET['systems_per_page'] ?? $_SESSION['systems_per_page'] ?? 20);
if (!in_array($systemsPerPage, $allowedPerPage, true)) { $systemsPerPage = 20; }
// Gestion du tri
$allowedSortOrders = ['original', 'alphabetical'];
$systemsSortOrder = ($_POST['systems_sort_order'] ?? $_GET['systems_sort_order'] ?? $_SESSION['systems_sort_order'] ?? 'original');
if (!in_array($systemsSortOrder, $allowedSortOrders, true)) { $systemsSortOrder = 'original'; }
$_SESSION['systems_per_page'] = $systemsPerPage;
$_SESSION['systems_page'] = $systemsPage;
$_SESSION['systems_sort_order'] = $systemsSortOrder;

// -------------- View -------------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$scraped = $_SESSION['last_scrape'] ?? [];
$systems = $_SESSION['systems_list'];

// Apply sorting
if ($systemsSortOrder === 'alphabetical') {
  $systems = $systems; // Make a copy for sorting
  usort($systems, function($a, $b) {
    $nameA = strtolower($a['platform_name'] ?? '');
    $nameB = strtolower($b['platform_name'] ?? '');
    return strcmp($nameA, $nameB);
  });
  // Update the session with sorted systems to maintain order in JSON export
  $_SESSION['systems_list'] = $systems;
}

$systemsTotal = count($systems);
$systemsPages = max(1, (int)ceil($systemsTotal / $systemsPerPage));
$systemsPage = max(1, min($systemsPage, $systemsPages));
$systemsOffset = ($systemsPage - 1) * $systemsPerPage;
$systemsPaginated = array_slice($systems, $systemsOffset, $systemsPerPage);
$gamesMap = $_SESSION['platform_games'];
// Debug temporaire
if (empty($gamesMap)) {
  $message = "No loaded sources or games.";
}
$images = $_SESSION['images'];
$imagesByName = [];
foreach ($images as $im) { if (!empty($im['name'])) { $imagesByName[$im['name']] = true; } }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RGSX Sources Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" type="image/x-icon" href="assets/favicon_rgsx.ico">
  <style>
    body { padding-bottom: 40px; }
    pre { background:#0f1722; color:#cfe1f0; padding:12px; border-radius:8px; max-height: 360px; overflow:auto; }
    .small-muted { font-size: 12px; color:#6c757d; }
    .pill { display:inline-block; padding:2px 8px; border:1px solid #dee2e6; border-radius:999px; font-size:12px; }
    .loading-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 2000; background: rgba(12, 14, 20, 0.45); backdrop-filter: blur(4px); }
    .loading-overlay.active { display: flex; }
    .loading-box { display:flex; flex-direction:column; align-items:center; gap:10px; color:#e9eef6; }
  </style>
  <script>
    // Base URL for local requests
    const baseUrl = '<?php echo addslashes($baseUrl); ?>';
    
    // Show image in modal
    function showImageModal(imageName) {
      const modal = new bootstrap.Modal(document.getElementById('imageModal'));
      const img = document.getElementById('modalImage');
      const loading = document.getElementById('imageLoading');
      const error = document.getElementById('imageError');
      const modalTitle = document.getElementById('imageModalLabel');
      
      // Reset state
      img.style.display = 'none';
      loading.classList.remove('d-none');
      error.classList.add('d-none');
      modalTitle.textContent = imageName;
      
      // Show modal
      modal.show();
      
      // Load image
      const imageUrl = baseUrl + '?preview_image=' + encodeURIComponent(imageName);
      const tempImg = new Image();
      tempImg.onload = function() {
        img.src = imageUrl;
        img.style.display = 'block';
        loading.classList.add('d-none');
      };
      tempImg.onerror = function() {
        loading.classList.add('d-none');
        error.classList.remove('d-none');
      };
      tempImg.src = imageUrl;
    }
    


    // Overlay helpers
    function showOverlay(){
      const overlay = document.getElementById('loadingOverlay');
      if (overlay) overlay.classList.add('active');
    }
    function hideOverlay(){
      const overlay = document.getElementById('loadingOverlay');
      if (overlay) overlay.classList.remove('active');
    }
    function showOverlayAndSubmit(form){
      if (!(form instanceof HTMLFormElement)) return;
      showOverlay();
      // small delay to allow paint before navigation
      setTimeout(() => { try { form.submit(); } catch(e){} }, 60);
    }
  </script>
  </head>
<body class="container py-3">
  <div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
      <div class="spinner-border text-light" role="status" aria-hidden="true"></div>
      <div>Chargement…</div>
    </div>
  </div>
  <h1 class="mb-3">RGSX Sources Manager</h1>
  <p class="small-muted"><?php echo t('desc.main', 'Une seule page pour : scraper des jeux, éditer les plateformes et générer un ZIP (systems_list.json, images/, games/).'); ?></p>

  <div class="d-flex justify-content-end mb-2">
    <form method="get" class="d-inline">
      <input type="hidden" name="active_tab" value="<?php echo h($activeTab); ?>">
      <select name="lang" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Language selector">
        <?php foreach ($availableLangs as $lc): ?>
          <option value="<?php echo $lc; ?>" <?php echo $lc===$lang?'selected':''; ?>><?php echo strtoupper($lc); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="card p-3 mb-3">
    <div class="d-flex flex-column flex-md-row gap-2 align-items-md-end">
      <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <input type="hidden" name="action" value="import_data_zip">
        <input type="hidden" name="active_tab" value="<?php echo h($activeTab); ?>">
        <div>
          <label class="form-label"><?php echo t('scrape.import_label'); ?></label>
          <input type="file" class="form-control mb-1" name="data_zip" accept=".zip,application/zip,application/x-zip-compressed" onchange="showOverlayAndSubmit(this.form)">
          <input type="url" class="form-control" name="data_zip_url" id="data_zip_url" placeholder="https://monsite/games.zip">
        </div>
  <button class="btn btn-outline-primary" type="submit"><?php echo t('btn.load','Charger'); ?></button>
  <button class="btn btn-outline-success ms-2" type="button" onclick="document.getElementById('data_zip_url').value='https://retrogamesets.fr/softs/games.zip';"><?php echo t('btn.use_official_base','Utiliser base RGSX officielle'); ?></button>
  <div class="text-muted small ms-2"><?php echo t('scrape.expected_content'); ?></div>
      </form>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

  <ul class="nav nav-tabs" id="tabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeTab==='tab-scrape'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-scrape" type="button">1) <?php echo t('tab.scrape','Scraper'); ?></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo ($activeTab==='tab-systems' || $activeTab==='tab-games')?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-systems" type="button">2) <?php echo t('tab.systems','Plateformes & Jeux'); ?></button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?php echo $activeTab==='tab-package'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-package" type="button">3) <?php echo t('tab.package','Package ZIP'); ?></button></li>
  </ul>

  <div class="tab-content border border-top-0 p-3">
    <!-- Scrape -->
    <div class="tab-pane fade <?php echo $activeTab==='tab-scrape'?'show active':''; ?>" id="tab-scrape">
      <form method="post" class="mb-3" id="scrapeForm">
        <input type="hidden" name="action" value="scrape">
        <input type="hidden" name="active_tab" value="tab-scrape">
        <div class="mb-2">
          <label class="form-label"><?php echo t('scrape.urls_label','URLs ou HTML'); ?></label>
          <textarea class="form-control" name="urls" rows="6" placeholder="<?php echo t('scrape.urls_placeholder'); ?>"></textarea>
          <div class="small-muted"><?php echo t('scrape.supported'); ?></div>
        </div>
        <div class="mb-2">
          <label class="form-label"><?php echo t('scrape.password_label'); ?></label>
          <input type="password" class="form-control" name="scrape_password" placeholder="<?php echo t('scrape.password_placeholder'); ?>">
          <div class="form-text"><?php echo t('scrape.password_help'); ?></div>
        </div>
  <button class="btn btn-primary" type="submit"><?php echo t('btn.scrape_go','Scraper'); ?></button>
      </form>
      <div id="scrapeExtensionsBox" class="mb-2" style="display:none"></div>

      <?php if (!empty($scraped)): ?>
        <h5 class="d-flex align-items-center justify-content-between">
          <span><?php echo t('scrape.results','Résultats'); ?></span>
          <form method="post" class="d-flex align-items-center gap-2 scrape-attach-form" onsubmit="return (function(f){if(!f.platform_file.value){alert(<?php echo json_encode(t('err.select_platform','Choisir une plateforme')); ?>);return false;} return true;})(this)")>
            <input type="hidden" name="action" value="attach_all_scrapes_to_platform">
            <input type="hidden" name="active_tab" value="tab-scrape">
            <div class="input-group input-group-sm" style="min-width:320px;max-width:420px;">
              <select class="form-select batocera-name-select" name="batocera_name" style="max-width:60%"><option value=""><?php echo t('placeholder.select_platform','Plateforme...'); ?></option></select>
              <select class="form-select batocera-folder-select" name="batocera_folder" style="max-width:40%"><option value=""><?php echo t('placeholder.select_folder','Dossier...'); ?></option></select>
              <input type="hidden" name="platform_file" class="platform-file-hidden" required>
              <button class="btn btn-sm btn-primary" type="submit"><?php echo t('btn.attach_all','Ajouter tous »'); ?></button>
            </div>
          </form>
        </h5>
        <div id="scrapeResultsBox">
        <?php foreach ($scraped as $idx => $entry): ?>
          <div class="mb-3 scrape-result-block" data-rows='<?php echo h(json_encode($entry['rows'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?>'>
            <div class="d-flex align-items-center justify-content-between">
              <div><span class="pill"><?php echo t('scrape.source','Source'); ?></span> <?php echo h($entry['label']); ?> — <span class="small-muted"><?php printf(t('scrape.files_count','%d fichier(s)'), count($entry['rows'])); ?> | <?php echo calculate_total_size($entry['rows']); ?></span></div>
              <form method="post" class="d-flex align-items-center gap-2 scrape-attach-form">
                <input type="hidden" name="action" value="attach_scrape_to_platform">
                <input type="hidden" name="active_tab" value="tab-scrape">
                <input type="hidden" name="scrape_index" value="<?php echo (int)$idx; ?>">
                <input type="hidden" class="scrape-url-source" value="<?php echo h($entry['label']); ?>">
                <div class="input-group input-group-sm" style="min-width:320px;max-width:420px;">
                  <select class="form-select batocera-name-select" name="batocera_name" style="max-width:60%"><option value="">Plateforme...</option></select>
                  <select class="form-select batocera-folder-select" name="batocera_folder" style="max-width:40%"><option value="">Dossier...</option></select>
                </div>
                <input type="hidden" name="platform_file" class="platform-file-hidden" required>
                <button class="btn btn-sm btn-success" type="submit"><?php echo t('btn.attach_platform','Attacher à la plateforme'); ?></button>
              </form>
<script>
// Remplir les listes déroulantes Batocera dans les résultats de scrap (noms dédiés pour éviter collisions globales)
let rgsxScrapeBatoceraSystems = [];
// Plateformes de la session (créées manuellement)
const sessionPlatforms = <?php echo json_encode(
  isset($_SESSION['systems_list']) && is_array($_SESSION['systems_list']) 
    ? array_map(function($system) {
        return [
          'name' => $system['platform_name'] ?? '',
          'folder' => $system['folder'] ?? ''
        ];
      }, $_SESSION['systems_list'])
    : []
); ?>;

function fetchScrapeBatoceraSystems(cb) {
  if (rgsxScrapeBatoceraSystems.length) { cb && cb(); return; }
  fetch('assets/batocera_systems.json')
    .then(r=>r.json())
    .then(data=>{
      // Normaliser: certaines versions avaient [[{...}]] -> aplatir
      if (Array.isArray(data) && data.length && Array.isArray(data[0])) {
        try { data = data.flat(); } catch(_) { data = data[0]; }
      }
      // Filtrer les entrées valides uniquement
      const batoceraData = (Array.isArray(data) ? data : []).filter(d => d && typeof d === 'object' && 'name' in d && 'folder' in d);
      
      // Combiner avec les plateformes de la session
      rgsxScrapeBatoceraSystems = [...batoceraData, ...sessionPlatforms].filter(d => d.name && d.folder);
      
      // Supprimer les doublons (priorité aux plateformes de la session)
      const seen = new Set();
      rgsxScrapeBatoceraSystems = rgsxScrapeBatoceraSystems.reverse().filter(d => {
        const key = d.name.toLowerCase();
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      }).reverse();
      
      cb && cb();
    })
    .catch(_ => { 
      // Si le fichier JSON échoue, utiliser au moins les plateformes de la session
      rgsxScrapeBatoceraSystems = sessionPlatforms.filter(d => d.name && d.folder);
      cb && cb(); 
    });
}
function fillScrapeBatoceraDropdowns(form) {
  const nameSel = form.querySelector('.batocera-name-select');
  const folderSel = form.querySelector('.batocera-folder-select');
  if (!nameSel || !folderSel) return;
  nameSel.innerHTML = '<option value="">'+<?php echo json_encode(t('placeholder.select_platform','Plateforme...')); ?>+'</option>';
  folderSel.innerHTML = '<option value="">'+<?php echo json_encode(t('placeholder.select_folder','Dossier...')); ?>+'</option>';
  // Trier par ordre alphabétique (sécurisé)
  const sorted = rgsxScrapeBatoceraSystems.slice().sort((a, b) => (a.name||'').localeCompare(b.name||'', 'fr', {sensitivity:'base'}));
  for (const sys of sorted) {
    const opt1 = document.createElement('option'); opt1.value = sys.name; opt1.textContent = sys.name; nameSel.appendChild(opt1);
    const opt2 = document.createElement('option'); opt2.value = sys.folder; opt2.textContent = sys.folder; folderSel.appendChild(opt2);
  }

  // Pré-remplissage fuzzy si possible
  const urlInput = form.querySelector('.scrape-url-source');
  if (urlInput && urlInput.value) {
    const url = urlInput.value.toLowerCase();
    let best = null, bestScore = 0;
    for (const sys of sorted) {
      // Fuzzy match: nom ou folder présent dans l'URL (ignorer accents, espaces, tirets, underscores)
      const norm = s => s.normalize('NFD').replace(/[^\w]/g, '').toLowerCase();
      const sysName = norm(sys.name);
      const sysFolder = norm(sys.folder);
      const urlNorm = norm(url);
      let score = 0;
      if (urlNorm.includes(sysFolder)) score += 2;
      if (urlNorm.includes(sysName)) score += 1;
      // Bonus si début d'un segment
      if (urlNorm.indexOf(sysFolder) === 0) score += 1;
      if (urlNorm.indexOf(sysName) === 0) score += 1;
      if (score > bestScore) { best = sys; bestScore = score; }
    }
    if (best && bestScore > 0) {
      nameSel.value = best.name;
      folderSel.value = best.folder;
      // Si hidden field présent, le remplir aussi
      const hidden = form.querySelector('.platform-file-hidden');
      if (hidden) hidden.value = best.name + '.json';
    }
  }
}
function setupScrapeBatoceraAttachSync(form) {
  const nameSel = form.querySelector('.batocera-name-select');
  const folderSel = form.querySelector('.batocera-folder-select');
  const hidden = form.querySelector('.platform-file-hidden');
  if (!nameSel || !folderSel || !hidden) return;
  nameSel.addEventListener('change', function() {
    const sys = rgsxScrapeBatoceraSystems.find(s => s.name === nameSel.value);
    if (sys) { folderSel.value = sys.folder; hidden.value = sys.name + '.json'; }
  });
  folderSel.addEventListener('change', function() {
    const sys = rgsxScrapeBatoceraSystems.find(s => s.folder === folderSel.value);
    if (sys) { nameSel.value = sys.name; hidden.value = sys.name + '.json'; }
  });
}
document.addEventListener('DOMContentLoaded', function() {
  fetchScrapeBatoceraSystems(function() {
    document.querySelectorAll('.scrape-attach-form').forEach(form => {
      fillScrapeBatoceraDropdowns(form);
      setupScrapeBatoceraAttachSync(form);
    });
  });
});
</script>
            </div>
            <pre class="scrape-rows-json"><?php echo h(json_encode($entry['rows'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></pre>
          </div>
        <?php endforeach; ?>
        </div>
        <script>
        // Dynamically build extension filter after scraping
        (function(){
          const resultsBox = document.getElementById('scrapeResultsBox');
          const extBox = document.getElementById('scrapeExtensionsBox');
          if (!resultsBox || !extBox) return;
          // Collect all extensions from all results
          let allExts = new Set();
          let allRows = [];
          document.querySelectorAll('.scrape-result-block').forEach(block => {
            const rows = JSON.parse(block.getAttribute('data-rows'));
            allRows = allRows.concat(rows);
            for (const row of rows) {
              if (row[0]) {
                const ext = (row[0].split('.').pop() || '').toLowerCase();
                if (ext) allExts.add(ext);
              }
            }
          });
          allExts = Array.from(allExts).sort();
          if (allExts.length === 0) return;
          // Build checkboxes
          let html = '<label class="form-label"><?php echo t('label.extensions_detected'); ?></label><br>';
          for (const ext of allExts) {
            html += `<div class="form-check form-check-inline">
              <input class="form-check-input scrape-ext-filter" type="checkbox" value="${ext}" id="scrape_ext_${ext}" checked>
              <label class="form-check-label" for="scrape_ext_${ext}">${ext}</label>
            </div>`;
          }
          extBox.innerHTML = html;
          extBox.style.display = '';
          // Filtering logic
          function filterScrapeResults() {
            const checked = Array.from(document.querySelectorAll('.scrape-ext-filter:checked')).map(cb => cb.value);
            document.querySelectorAll('.scrape-result-block').forEach(block => {
              const rows = JSON.parse(block.getAttribute('data-rows'));
              const filtered = rows.filter(row => checked.includes((row[0].split('.').pop() || '').toLowerCase()));
              block.querySelector('.scrape-rows-json').textContent = JSON.stringify(filtered, null, 2);
            });
          }
          document.querySelectorAll('.scrape-ext-filter').forEach(cb => {
            cb.addEventListener('change', filterScrapeResults);
          });
        })();
        </script>
      <?php endif; ?>
    </div>

    <!-- Systems & Games -->
    <div class="tab-pane fade <?php echo ($activeTab==='tab-systems' || $activeTab==='tab-games')?'show active':''; ?>" id="tab-systems">
      <div class="d-flex gap-2 mb-2">
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="systems_new">
          <input type="hidden" name="active_tab" value="tab-systems">
          <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('<?php echo t('confirm.clear_platforms'); ?>');"><?php echo t('btn.clear_platforms'); ?></button>
        </form>
        <form method="post" enctype="multipart/form-data" class="d-inline">
          <input type="hidden" name="action" value="systems_upload">
          <input type="hidden" name="active_tab" value="tab-systems">
          <input type="file" class="form-control" name="systems_file" accept=".json,application/json" required onchange="showOverlayAndSubmit(this.form)">
        </form>
        <form method="post" enctype="multipart/form-data" class="d-inline">
          <input type="hidden" name="action" value="games_upload">
          <input type="hidden" name="active_tab" value="tab-systems">
          <input type="file" class="form-control" name="platform_json[]" accept=".json,application/json,.zip,application/zip,application/x-zip-compressed" multiple onchange="handleGamesFilesChange(this)">
        </form>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="games_clear_all">
          <input type="hidden" name="active_tab" value="tab-systems">
          <button class="btn btn-outline-warning btn-sm" type="submit" onclick="return confirm('<?php echo t('confirm.clear_games'); ?>');"><?php echo t('btn.clear_games'); ?></button>
        </form>
      </div>
      <form method="post" class="row g-2 align-items-end mb-3" id="systemsAddForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="systems_add">
        <input type="hidden" name="active_tab" value="tab-systems">
        <input type="hidden" name="systems_page" value="<?php echo $systemsPage; ?>">
        <input type="hidden" name="systems_per_page" value="<?php echo $systemsPerPage; ?>">
        <input type="hidden" name="systems_sort_order" value="<?php echo $systemsSortOrder; ?>">
        <div class="col-md-3">
          <label class="form-label"><?php echo t('label.platform_name'); ?></label>
          <div class="input-group">
            <select class="form-select" id="batoceraPlatformName" style="max-width:60%">
              <option value="">Select...</option>
            </select>
            <input class="form-control" name="platform_name" id="platformNameInput" required placeholder="<?php echo h(t('placeholder.platform_name','Nom plateforme')); ?>">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label"><?php echo t('label.folder'); ?></label>
          <div class="input-group">
            <select class="form-select" id="batoceraFolder" style="max-width:60%">
              <option value="">Select...</option>
            </select>
            <input class="form-control" name="folder" id="folderInput" placeholder="ex: chihiro" required>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?php echo t('label.platform_image'); ?></label>
          <input type="file" class="form-control" name="platform_image_file" id="platformImageFile" accept="image/*">
        </div>
  <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?php echo t('btn.add'); ?></button></div>
      </form>
      <div class="mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div class="small text-muted flex-grow-1">
          <?php printf(t('table.systems.summary','Systèmes %d-%d sur %d'), $systemsOffset + 1, min($systemsOffset + $systemsPerPage, $systemsTotal), $systemsTotal); ?>
        </div>
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
          <input type="hidden" name="active_tab" value="tab-systems">
          <input type="hidden" name="systems_page" value="1">
          <label for="systems_sort_order" class="form-label mb-0 small"><?php echo t('label.sort_order'); ?></label>
          <select class="form-select form-select-sm" style="width:auto" name="systems_sort_order" id="systems_sort_order" onchange="this.form.submit()">
            <option value="original" <?php echo $systemsSortOrder === 'original' ? 'selected' : ''; ?>><?php echo t('sort.original'); ?></option>
            <option value="alphabetical" <?php echo $systemsSortOrder === 'alphabetical' ? 'selected' : ''; ?>><?php echo t('sort.alphabetical'); ?></option>
          </select>
          <label for="systems_per_page" class="form-label mb-0 small"><?php echo t('label.per_page'); ?></label>
          <select class="form-select form-select-sm" style="width:auto" name="systems_per_page" id="systems_per_page" onchange="this.form.submit()">
            <?php foreach ([10,20,25,50,100] as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo $opt===$systemsPerPage?'selected':''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <div class="btn-group" role="group">
          <a href="?active_tab=tab-systems&systems_per_page=<?php echo $systemsPerPage; ?>&systems_sort_order=<?php echo $systemsSortOrder; ?>&systems_page=<?php echo max(1, $systemsPage - 1); ?>" class="btn btn-sm btn-outline-secondary <?php echo $systemsPage <= 1 ? 'disabled' : ''; ?>"><?php echo t('pagination.prev'); ?></a>
          <span class="btn btn-sm btn-secondary disabled"><?php echo t('pagination.page','Page'); ?> <?php echo $systemsPage; ?>/<?php echo $systemsPages; ?></span>
          <a href="?active_tab=tab-systems&systems_per_page=<?php echo $systemsPerPage; ?>&systems_sort_order=<?php echo $systemsSortOrder; ?>&systems_page=<?php echo min($systemsPages, $systemsPage + 1); ?>" class="btn btn-sm btn-outline-secondary <?php echo $systemsPage >= $systemsPages ? 'disabled' : ''; ?>"><?php echo t('pagination.next'); ?></a>
        </div>
      </div>
      <!-- Plateformes avec jeux intégrés -->
      <div class="table-responsive">
        <table class="table table-bordered table-striped table-sm">
          <thead>
            <tr>
              <th>#</th>
              <th><?php echo t('label.platform_name'); ?></th>
              <th><?php echo t('label.folder'); ?></th>
              <th><?php echo t('label.platform_image'); ?></th>
              <th><?php echo t('label.games'); ?></th>
              <th><?php echo t('label.actions'); ?></th>
            </tr>
          </thead>
          <tbody>
      <?php foreach ($systemsPaginated as $i => $row): 
        $realIndex = $systemsOffset + $i;
        $platformName = (string)($row['platform_name'] ?? '');
        $platformFile = $platformName . '.json';
        $games = isset($gamesMap[$platformFile]) ? $gamesMap[$platformFile] : [];
        $gamesCount = count($games);
        ?>
            <tr>
              <td><?php echo $realIndex + 1; ?></td>
              <td><?php echo h($platformName); ?></td>
              <td><?php echo h($row['folder'] ?? ''); ?></td>
              <td>
                <?php $imgName = (string)($row['platform_image'] ?? '');
                      if ($imgName !== ''): ?>
                  <div class="d-flex align-items-center gap-2">
                    <span class="small text-muted"><?php echo h($imgName); ?></span>
                    <?php if (isset($imagesByName[$imgName])): ?>
                      <button type="button" class="btn btn-sm btn-outline-info" onclick="showImageModal('<?php echo addslashes($imgName); ?>')" title="Voir image"><?php echo t('btn.view'); ?></button>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleGames(<?php echo $realIndex; ?>)" data-games-count="<?php echo $gamesCount; ?>">
                  <?php echo $gamesCount; ?> jeux
                </button>
              </td>
              <td class="text-nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditRow(<?php echo $realIndex; ?>)"><?php echo t('btn.modify'); ?></button>
                <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette plateforme et tous ses jeux ?');">
                  <input type="hidden" name="action" value="platform_delete_complete">
                  <input type="hidden" name="active_tab" value="tab-systems">
                  <input type="hidden" name="platform_index" value="<?php echo $realIndex; ?>">
                  <input type="hidden" name="platform_file" value="<?php echo h($platformFile); ?>">
                  <button class="btn btn-sm btn-outline-danger"><?php echo t('btn.delete'); ?></button>
                </form>
              </td>
            </tr>
            
            <!-- Ligne d'édition cachée -->
            <tr id="edit-row-<?php echo $realIndex; ?>" class="d-none">
              <td colspan="6">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <input type="hidden" name="action" value="systems_update_with_rename">
                  <input type="hidden" name="active_tab" value="tab-systems">
                  <input type="hidden" name="index" value="<?php echo $realIndex; ?>">
                  <input type="hidden" name="old_platform_name" value="<?php echo h($platformName); ?>">
                  <input type="hidden" name="systems_page" value="<?php echo $systemsPage; ?>">
                  <input type="hidden" name="systems_per_page" value="<?php echo $systemsPerPage; ?>">
                  <input type="hidden" name="systems_sort_order" value="<?php echo $systemsSortOrder; ?>">
                  <div class="col-md-3">
                    <label class="form-label"><?php echo t('label.platform_name'); ?></label>
                    <div class="input-group">
                      <select class="form-select batocera-name-select" style="max-width:60%">
                        <option value="">Select...</option>
                      </select>
                      <input class="form-control form-control-sm" name="platform_name" value="<?php echo h($platformName); ?>" required>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label"><?php echo t('label.folder'); ?></label>
                    <div class="input-group">
                      <select class="form-select batocera-folder-select" style="max-width:40%">
                        <option value="">Select...</option>
                      </select>
                      <input class="form-control form-control-sm" name="folder" value="<?php echo h($row['folder'] ?? ''); ?>" required>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label"><?php echo t('label.platform_image_replace'); ?></label>
                    <input type="file" class="form-control form-control-sm" name="platform_image_file" accept="image/*">
                    <div class="form-text">Laissez vide pour conserver: <?php echo h($row['platform_image'] ?? ''); ?></div>
                  </div>
                  <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" type="submit"><?php echo t('btn.save'); ?></button>
                  </div>
                </form>
              </td>
            </tr>
            
            <!-- Ligne des jeux cachée -->
            <tr id="games-row-<?php echo $realIndex; ?>" class="d-none">
              <td colspan="6">
                <div class="p-3 bg-light">
                  <div class="mb-3">
                    <strong><?php echo sprintf(t('label.games_of_platform'), h($platformName), $gamesCount); ?></strong>
                    <div class="float-end">
                      <!-- Bouton pour ajouter un jeu -->
                      <button class="btn btn-sm btn-success me-2" onclick="toggleAddGame(<?php echo $realIndex; ?>)"><?php echo t('btn.add_game'); ?></button>
                      <!-- Bouton pour supprimer tous les jeux de cette plateforme -->
                      <form method="post" class="d-inline" onsubmit="return confirm('Supprimer tous les jeux de cette plateforme ?');">
                        <input type="hidden" name="action" value="games_clear_platform">
                        <input type="hidden" name="active_tab" value="tab-systems">
                        <input type="hidden" name="games_file" value="<?php echo h($platformFile); ?>">
                        <button class="btn btn-sm btn-outline-warning" type="submit"><?php echo t('btn.clean_games'); ?></button>
                      </form>
                    </div>
                  </div>
                  
                  <!-- Formulaire d'ajout de jeu caché -->
                  <div id="add-game-<?php echo $realIndex; ?>" class="card mb-3 d-none">
                    <div class="card-body">
                      <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="games_add_row">
                        <input type="hidden" name="active_tab" value="tab-systems">
                        <input type="hidden" name="games_file" value="<?php echo h($platformFile); ?>">
                        <div class="col-md-4">
                          <label class="form-label"><?php echo t('label.game_name'); ?></label>
                          <input class="form-control form-control-sm" name="game_name" placeholder="Nom du fichier" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label"><?php echo t('label.url'); ?></label>
                          <input class="form-control form-control-sm" name="game_url" placeholder="https://..." required>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label"><?php echo t('label.size'); ?></label>
                          <input class="form-control form-control-sm" name="game_size" placeholder="467.4M">
                        </div>
                        <div class="col-12">
                          <button class="btn btn-sm btn-success" type="submit"><?php echo t('btn.add_line'); ?></button>
                          <button class="btn btn-sm btn-secondary" type="button" onclick="toggleAddGame(<?php echo $realIndex; ?>)">Annuler</button>
                        </div>
                      </form>
                    </div>
                  </div>
                  
                  <!-- Liste des jeux -->
                  <div class="small text-muted" id="loading-games-<?php echo $realIndex; ?>" style="display:none;">Chargement des jeux...</div>
                  <div id="games-body-<?php echo $realIndex; ?>"></div>
                </div>
              </td>
            </tr>
      <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
    </div>


      
    </div>








    <!-- Package -->
    <div class="tab-pane fade <?php echo $activeTab==='tab-package'?'show active':''; ?>" id="tab-package">
      <div class="row g-3">
        <div class="col-12">
          <div class="card p-3 h-100">
            <h6><?php echo t('heading.current_state','État actuel'); ?></h6>
            <ul class="small">
              <li><?php printf(t('stats.systems','Systèmes: %d entrée(s)'), count($systems)); ?></li>
              <li><?php printf(t('stats.platforms_loaded','Plateformes chargées: %d fichier(s)'), count($gamesMap)); ?></li>
              <li>Images: <?php echo count($images); ?> fichier(s)</li>
            </ul>
            <div class="d-flex flex-column gap-2">
            <form method="post">
              <input type="hidden" name="action" value="build_zip">
              <input type="hidden" name="active_tab" value="tab-package">
              <button class="btn btn-success" type="submit" <?php echo empty($systems)?'disabled':''; ?>><?php echo t('btn.build_zip'); ?></button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="systems_download">
              <input type="hidden" name="active_tab" value="tab-package">
              <button class="btn btn-outline-primary" type="submit" <?php echo empty($systems)?'disabled':''; ?>><?php echo t('btn.download_systems'); ?></button>
            </form>
            </div>
            <p class="small-muted mt-2"><?php echo t('desc.zip_content'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal pour afficher les images -->
  <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="imageModalLabel">Aperçu image</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center">
          <div id="imageLoading" class="d-none">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Chargement...</span>
            </div>
          </div>
          <img id="modalImage" class="img-fluid" style="max-height: 70vh; display: none;" alt="Aperçu">
          <div id="imageError" class="text-danger d-none">Erreur de chargement de l'image</div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  // Keep URL query param active_tab in sync with selected tab and initialize it on load
  document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.getElementById('tabs');
    if (tabs) {
      tabs.addEventListener('shown.bs.tab', function(e) {
        try {
          const target = e.target && e.target.getAttribute('data-bs-target');
          if (!target) return;
          const tabId = target.charAt(0) === '#' ? target.slice(1) : target;
          const url = new URL(window.location.href);
          url.searchParams.set('active_tab', tabId);
          history.replaceState({}, '', url);
        } catch(_) { /* noop */ }
      });
    }
    // Ensure the param exists on first load (use current server-side active tab)
    try {
      const url = new URL(window.location.href);
      if (!url.searchParams.get('active_tab')) {
        url.searchParams.set('active_tab', '<?php echo h($activeTab); ?>');
        history.replaceState({}, '', url);
      }
    } catch(_) { /* noop */ }
  });

  // Batocera dropdowns for platform_name/folder in tab 2
  let batoceraSystems = [];
  function loadBatoceraSystemsDropdowns() {
    fetch('assets/batocera_systems.json')
      .then(r => r.json())
      .then(data => {
        // Normalize/flatten if nested like [[{...}]]
        if (Array.isArray(data) && data.length && Array.isArray(data[0])) {
          try { data = data.flat(); } catch(_) { data = data[0]; }
        }
        // Assign to outer variable (used by setupBatoceraSync)
        batoceraSystems = (Array.isArray(data) ? data : []).filter(d => d && typeof d === 'object' && 'name' in d && 'folder' in d);
        const nameSel = document.getElementById('batoceraPlatformName');
        const folderSel = document.getElementById('batoceraFolder');
        if (!nameSel || !folderSel) return;
        // Clear and repopulate placeholders
        nameSel.innerHTML = '<option value="">Select...</option>';
        folderSel.innerHTML = '<option value="">Select...</option>';
        // Sort safely by name
        const sorted = batoceraSystems.slice().sort((a,b) => (a.name||'').localeCompare(b.name||'', 'fr', {sensitivity:'base'}));
        for (const sys of sorted) {
          const opt1 = document.createElement('option');
          opt1.value = sys.name;
          opt1.textContent = sys.name;
          nameSel.appendChild(opt1);
          const opt2 = document.createElement('option');
          opt2.value = sys.folder;
          opt2.textContent = sys.folder;
          folderSel.appendChild(opt2);
        }
      })
      .catch(_ => {
        // Leave placeholders if fetch fails
      });
  }
  // Synchronize dropdowns and inputs
  function setupBatoceraSync() {
    const nameSel = document.getElementById('batoceraPlatformName');
    const folderSel = document.getElementById('batoceraFolder');
    const nameInput = document.getElementById('platformNameInput');
    const folderInput = document.getElementById('folderInput');
    if (!nameSel || !folderSel || !nameInput || !folderInput) return;
    nameSel.addEventListener('change', function() {
      const val = nameSel.value;
      if (!val) return;
      nameInput.value = val;
      const sys = batoceraSystems.find(s => s.name === val);
      if (sys) folderInput.value = sys.folder;
    });
    folderSel.addEventListener('change', function() {
      const val = folderSel.value;
      if (!val) return;
      folderInput.value = val;
      const sys = batoceraSystems.find(s => s.folder === val);
      if (sys) nameInput.value = sys.name;
    });
  }
  // Only load Batocera systems when tab 2 is shown
  document.addEventListener('DOMContentLoaded', function() {
    const tabBtn = document.querySelector('[data-bs-target="#tab-systems"]');
    if (tabBtn) {
      tabBtn.addEventListener('shown.bs.tab', function() {
        loadBatoceraSystemsDropdowns();
        setTimeout(setupBatoceraSync, 200);
      });
    }
    // If already on tab 2 at load
    if (document.getElementById('tab-systems').classList.contains('show')) {
      loadBatoceraSystemsDropdowns();
      setTimeout(setupBatoceraSync, 200);
    }
  });
  </script>
  <script>
    // Ensure every submitted form includes the current active tab id
    document.addEventListener('submit', function(e){
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      // Determine current active tab-pane
      const activePane = document.querySelector('.tab-pane.show.active');
      const tabId = activePane ? activePane.id : 'tab-scrape';
      let hidden = form.querySelector('input[name="active_tab"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'active_tab';
        form.appendChild(hidden);
      }
      hidden.value = tabId;
      // Show loading overlay for any submit and delay slightly to let it paint
      if (form.dataset.delayed !== '1') {
        e.preventDefault();
        form.dataset.delayed = '1';
        showOverlay();
        setTimeout(() => { try { form.submit(); } catch(e){} }, 60);
        return false;
      }
    });

    // Toggle inline edit rows
    function toggleEditRow(i){
      const row = document.getElementById('edit-row-' + i);
      if (!row) return;
      
      const isOpening = row.classList.contains('d-none');
      row.classList.toggle('d-none');
      
      // Si on ouvre le formulaire, remplir les listes déroulantes
      if (isOpening) {
        // S'assurer que les données Batocera sont chargées (utiliser la même fonction que le formulaire principal)
        if (batoceraSystems && batoceraSystems.length > 0) {
          fillEditFormDropdowns(row);
          setupEditFormListeners(row);
        } else {
          // Charger les données d'abord
          loadBatoceraSystemsDropdowns();
          // Attendre un peu puis remplir
          setTimeout(() => {
            fillEditFormDropdowns(row);
            setupEditFormListeners(row);
          }, 100);
        }
      }
    }
    
    // Fill dropdowns for edit form (use same data as main form)
    function fillEditFormDropdowns(form) {
      const nameSel = form.querySelector('.batocera-name-select');
      const folderSel = form.querySelector('.batocera-folder-select');
      if (!nameSel || !folderSel || !batoceraSystems || batoceraSystems.length === 0) return;
      
      // Clear and populate
      nameSel.innerHTML = '<option value="">Select...</option>';
      folderSel.innerHTML = '<option value="">Select...</option>';
      
      // Sort by name
      const sorted = batoceraSystems.slice().sort((a,b) => (a.name||'').localeCompare(b.name||'', 'fr', {sensitivity:'base'}));
      for (const sys of sorted) {
        const opt1 = document.createElement('option');
        opt1.value = sys.name;
        opt1.textContent = sys.name;
        nameSel.appendChild(opt1);
        
        const opt2 = document.createElement('option');
        opt2.value = sys.folder;
        opt2.textContent = sys.folder;
        folderSel.appendChild(opt2);
      }
    }
    
    // Setup listeners for edit form dropdowns
    function setupEditFormListeners(form) {
      const nameSel = form.querySelector('.batocera-name-select');
      const folderSel = form.querySelector('.batocera-folder-select');
      const nameInput = form.querySelector('input[name="platform_name"]');
      const folderInput = form.querySelector('input[name="folder"]');
      
      if (!nameSel || !folderSel || !nameInput || !folderInput) return;
      
      // Éviter les doublons d'event listeners
      nameSel.onchange = function() {
        const sys = batoceraSystems.find(s => s.name === nameSel.value);
        if (sys) { 
          folderSel.value = sys.folder;
          nameInput.value = sys.name;
          folderInput.value = sys.folder;
        }
      };
      
      folderSel.onchange = function() {
        const sys = batoceraSystems.find(s => s.folder === folderSel.value);
        if (sys) { 
          nameSel.value = sys.name;
          nameInput.value = sys.name;
          folderInput.value = sys.folder;
        }
      };
    }
    
    // Toggle platform edit form (nouvelle interface)
    function togglePlatformEdit(i){
      const editDiv = document.getElementById('platform-edit-' + i);
      if (!editDiv) return;
      editDiv.classList.toggle('d-none');
    }
    
    // Toggle games display (nouvelle interface tableau)
    function toggleGames(i){
      const gamesRow = document.getElementById('games-row-' + i);
      const gamesBody = document.getElementById('games-body-' + i);
      const loadingDiv = document.getElementById('loading-games-' + i);
      
      if (!gamesRow) return;
      
      if (gamesRow.classList.contains('d-none')) {
        // Montrer les jeux
        gamesRow.classList.remove('d-none');
        
        // Charger les jeux si pas encore fait
        if (!gamesBody.dataset.loaded) {
          loadingDiv.style.display = 'block';
          
          // Trouver le nom du fichier de plateforme
          const gamesButton = document.querySelector(`button[onclick="toggleGames(${i})"]`);
          const parentRow = gamesButton.closest('tr');
          const platformName = parentRow.cells[1].textContent.trim();
          const gamesFile = platformName + '.json';
          
          // Charger les jeux via AJAX
          loadGamesForPlatform(gamesFile, gamesBody, loadingDiv);
        }
      } else {
        // Cacher les jeux
        gamesRow.classList.add('d-none');
      }
    }
    
    // Toggle add game form
    function toggleAddGame(i){
      const addGameDiv = document.getElementById('add-game-' + i);
      if (!addGameDiv) return;
      addGameDiv.classList.toggle('d-none');
    }
    
    // Toggle edit row for games (loaded via AJAX in accordion)
    function toggleGameEditRow(i){
      const row = document.getElementById('game-edit-row-' + i);
      if (!row) return;
      row.classList.toggle('d-none');
    }
    
    // Load games for a specific platform
    function loadGamesForPlatform(gamesFile, targetBody, loadingDiv) {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 30000);
      
      window.activeRequests = window.activeRequests || new Set();
      window.activeRequests.add(controller);
      
      fetch(window.location.pathname + '?render_games_table=1&file=' + encodeURIComponent(gamesFile), {
        signal: controller.signal
      })
      .then(response => {
        clearTimeout(timeoutId);
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.text();
      })
      .then(html => {
        targetBody.innerHTML = html;
        targetBody.dataset.loaded = '1';
        loadingDiv.style.display = 'none';
        window.activeRequests.delete(controller);
      })
      .catch(err => {
        clearTimeout(timeoutId);
        window.activeRequests.delete(controller);
        loadingDiv.style.display = 'none';
        const errorMsg = err.name === 'AbortError' ? 'Timeout (30s)' : (err && err.message ? err.message : err);
        targetBody.innerHTML = '<div class="text-danger">Erreur de chargement: ' + errorMsg + '</div>';
      });
    }
    
    // Enforce max 20 selected files for games upload
    function handleGamesFilesChange(input){
      if (!(input instanceof HTMLInputElement)) return;
      const files = input.files;
      if (!files) { showOverlayAndSubmit(input.form); return; }
      const max = 20;
      if (files.length > max) {
        alert('Vous avez sélectionné ' + files.length + ' fichiers. Maximum autorisé: ' + max + '\\nPour plus de 20 fichiers, créez un ZIP contenant vos .json.');
        // Reset selection to force user to re-choisir
        input.value = '';
        return;
      }
      showOverlayAndSubmit(input.form);
    }
    
    // Loading overlay + deterministic tab switching (manual activation always)
    (function(){
      const overlay = document.getElementById('loadingOverlay');
      const show = () => { if (overlay) overlay.classList.add('active'); };
      const hide = () => { if (overlay) overlay.classList.remove('active'); };
      const activateTab = (targetSel, btn) => {
        try {
          // Switch active button and aria
          document.querySelectorAll('[data-bs-toggle="tab"]').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
          });
          if (btn) {
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
          }
          // Switch panes
          document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active'));
          const pane = document.querySelector(targetSel);
          if (pane) pane.classList.add('show','active');
        } catch (_) { /* noop */ }
      };
      // Delegate on the tabs container to be extra-resilient
      const tabsBar = document.getElementById('tabs');
      if (tabsBar) {
        tabsBar.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-bs-toggle="tab"][data-bs-target]');
          if (!btn) return;
          e.preventDefault();
          const target = btn.getAttribute('data-bs-target');
          if (!target) return;
          show();
          // Activate immediately; hide overlay shortly after paint
          activateTab(target, btn);
          // Initialize per-tab content when using manual activation
          if (target === '#tab-systems') {
            try { loadBatoceraSystemsDropdowns(); setTimeout(setupBatoceraSync, 200); } catch(_) {}
          }
          setTimeout(hide, 150);
        });
      }
      // Also listen to Bootstrap event (if present) purely to hide overlay on heavy tabs
      document.addEventListener('shown.bs.tab', (ev) => {
        const target = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-bs-target') : '';
        if (target === '#tab-systems') {
          setTimeout(hide, 150);
        }
      });
      // Safety: hide overlay if page is shown or after a delay
      window.addEventListener('pageshow', hide);
      setTimeout(() => hide(), 5000);
    })();

    // Deferred load of platform games tables when an accordion is expanded
    (function(){
      const accordion = document.getElementById('platformsSystemsAccordion');
      if (!accordion) return;
      
      // Load games page function
      window.loadGamesPage = function(file, page, btn) {
        // Prevent loading if page not fully loaded
        if (!window.pageFullyLoaded) {
          console.log('[RGSX] Page not fully loaded yet, ignoring pagination request for', file);
          return;
        }
        
        const idx = btn.closest('.accordion-body').querySelector('[id^="games-body-platform-"]').id.replace('games-body-platform-','');
        const target = document.getElementById('games-body-platform-' + idx);
        const loading = document.getElementById('loading-platform-' + idx);
        if (!target) return;
        if (loading) loading.style.display = 'block';
        console.log('[RGSX] Loading games page', page, 'for', file);
        
        // AbortController for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout
        window.activeRequests.add(controller);
        
        fetch(baseUrl + '?render_games_table=1&file=' + encodeURIComponent(file) + '&page=' + page, { 
          cache: 'no-store',
          signal: controller.signal
        })
          .then(r => { 
            clearTimeout(timeoutId);
            if (!r.ok) throw new Error('HTTP ' + r.status); 
            return r.text(); 
          })
          .then(html => {
            target.innerHTML = html;
            if (loading) loading.style.display = 'none';
            window.activeRequests.delete(controller);
            console.log('[RGSX] Loaded games page', page, 'for', file);
          })
          .catch(err => {
            clearTimeout(timeoutId);
            window.activeRequests.delete(controller);
            if (loading) loading.style.display = 'none';
            const errorMsg = err.name === 'AbortError' ? 'Timeout (30s)' : (err && err.message ? err.message : err);
            target.innerHTML = '<div class="text-danger">Erreur de chargement: ' + errorMsg + '</div>';
            console.error('[RGSX] Failed to load games page', page, 'for', file, err);
          });
      };
      
      accordion.addEventListener('show.bs.collapse', (e) => {
        const panel = e.target;
        const file = panel.getAttribute('data-games-file');
        if (!file) return;
        
        // Prevent loading if page not fully loaded
        if (!window.pageFullyLoaded) {
          console.log('[RGSX] Page not fully loaded yet, ignoring request for', file);
          e.preventDefault();
          return;
        }
        
        const idx = panel.id.replace('platform-','');
        const target = document.getElementById('games-body-platform-' + idx);
        const loading = document.getElementById('loading-platform-' + idx);
        if (!target || target.dataset.loaded === '1') return;
        if (loading) loading.style.display = 'block';
        console.log('[RGSX] Loading games table for', file);
        
        // AbortController for timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout
        window.activeRequests.add(controller);
        
        fetch(baseUrl + '?render_games_table=1&file=' + encodeURIComponent(file), { 
          cache: 'no-store',
          signal: controller.signal
        })
          .then(r => { 
            clearTimeout(timeoutId);
            if (!r.ok) throw new Error('HTTP ' + r.status); 
            return r.text(); 
          })
          .then(html => {
            target.innerHTML = html;
            target.dataset.loaded = '1';
            if (loading) loading.style.display = 'none';
            window.activeRequests.delete(controller);
            console.log('[RGSX] Loaded games table for', file);
          })
          .catch(err => {
            clearTimeout(timeoutId);
            window.activeRequests.delete(controller);
            if (loading) loading.style.display = 'none';
            const errorMsg = err.name === 'AbortError' ? 'Timeout (30s)' : (err && err.message ? err.message : err);
            target.innerHTML = '<div class="text-danger">Erreur de chargement: ' + errorMsg + '</div>';
            console.error('[RGSX] Failed to load games table for', file, err);
          });
      });
    })();

    // Global list to track active requests
    window.activeRequests = new Set();
    window.pageFullyLoaded = false;
    
    // Mark page as fully loaded after DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => {
        window.pageFullyLoaded = true;
        console.log('[RGSX] Page fully loaded, requests allowed');
      }, 1000); // Give some time for everything to settle
    });
    
    // Cancel all active requests when changing tabs
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tabButton => {
      tabButton.addEventListener('click', () => {
        window.activeRequests.forEach(controller => {
          try { controller.abort(); } catch(e) {}
        });
        window.activeRequests.clear();
        console.log('[RGSX] Cancelled all active requests due to tab change');
      });
    });

    // Ensure overlay is hidden on initial load
    document.addEventListener('DOMContentLoaded', hideOverlay);

    // Hide overlay after ZIP or JSON download (systems_download/build_zip)
    function addDownloadOverlayFix(formSelector, buttonSelector) {
      const form = document.querySelector(formSelector);
      const btn = document.querySelector(buttonSelector);
      if (!form || !btn) return;
      btn.addEventListener('click', function(e) {
        // Only for real submit
        setTimeout(() => {
          hideOverlay();
        }, 1200); // Give time for download dialog
      });
    }
    // For ZIP and JSON download buttons
    addDownloadOverlayFix('form[action="build_zip"]', 'form[action="build_zip"] button[type="submit"]');
    addDownloadOverlayFix('form[action="systems_download"]', 'form[action="systems_download"] button[type="submit"]');
  </script>
</body>
</html>
