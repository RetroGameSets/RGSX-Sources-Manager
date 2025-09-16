<?php
// Télécharge et parse le fichier es_systems.yml de Batocera

$url = 'https://raw.githubusercontent.com/batocera-linux/batocera.linux/master/package/batocera/emulationstation/batocera-es-system/es_systems.yml';
$yml = @file_get_contents($url);
if (!$yml) die("Erreur de téléchargement\n");
$lines = explode("\n", $yml);
$result = [];
$current_key = null;
$current = [];
foreach ($lines as $line) {
    // Detect new block (system)
    if (preg_match('/^([a-zA-Z0-9_\-]+):\s*$/', $line, $m)) {
        // Save previous block if valid
        if (!empty($current)) {
            $name = $current['name'] ?? null;
            $folder = $current_key;
            if ($name && $folder) {
                $result[] = ["name" => trim($name), "folder" => trim($folder)];
            }
        }
        $current_key = $m[1];
        $current = [];
        continue;
    }
    // Parse key: value lines
    if (preg_match('/^\s*([a-zA-Z0-9_\-]+):\s*(.+)$/', $line, $m)) {
        $current[$m[1]] = $m[2];
    }
}
// Save last block
if (!empty($current)) {
    $name = $current['name'] ?? null;
    $folder = $current_key;
    if ($name && $folder) {
        $result[] = ["name" => trim($name), "folder" => trim($folder)];
    }
}
file_put_contents(__DIR__ . '/batocera_systems.json', json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "OK: ".count($result)." systèmes extraits\n";
