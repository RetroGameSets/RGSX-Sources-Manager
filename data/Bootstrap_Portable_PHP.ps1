# Bootstraps a portable PHP runtime into windows\php (if missing)
param()
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpDir = Join-Path $root 'php'
if (Test-Path $phpDir) {
  Write-Host "[INFO] Dossier PHP deja present: $phpDir"
  exit 0
}

Write-Host "[INFO] Telechargement de PHP portable pour Windows (x64, Thread Safe) ..."
$phpZipUrl = 'https://windows.php.net/downloads/releases/php-8.4.12-Win32-vs17-x64.zip'
$zipPath = Join-Path $env:TEMP 'php_portable.zip'
Invoke-WebRequest -Uri $phpZipUrl -OutFile $zipPath

Write-Host "[INFO] Decompression ..."
Expand-Archive -Path $zipPath -DestinationPath $phpDir
Remove-Item $zipPath -Force

# Minimal php.ini with required extensions
$ini = @'
[PHP]
extension_dir = "ext"
extension = zip
max_file_uploads = 200
upload_max_filesize = 256M
post_max_size = 256M
memory_limit = 1G
allow_url_fopen = On
extension=openssl
'@
Set-Content -Path (Join-Path $phpDir 'php.ini') -Value $ini -Encoding ASCII

Write-Host "[OK] PHP portable installe dans $phpDir"