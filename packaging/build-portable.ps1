param(
    [string]$OutDir = (Join-Path (Split-Path -Parent $PSScriptRoot) 'dist'),
    [switch]$BundlePhp,
    [string]$PhpSource = 'C:\Program Files\PHP\current'
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$appName = 'SAJI-POS'
$stage = Join-Path $OutDir $appName
$configPath = Join-Path $root 'config.php'

if (-not (Test-Path $configPath)) {
    throw "Run this script from inside the project (config.php not found)."
}

$version = '1.0.0'
$configText = Get-Content $configPath -Raw
if ($configText -match "define\('APP_VERSION',\s*'([^']+)'\)") {
    $version = $Matches[1]
}

Write-Host "Building $appName $version in $stage"
if (Test-Path $stage) {
    Remove-Item -Recurse -Force $stage
}
New-Item -ItemType Directory -Force -Path $stage | Out-Null

$exclude = @('.git', 'dist', 'tests', 'tools', 'packaging', 'data', 'kcb-buni-app', 'node_modules', '.env', '.env.example', 'php.ini', 'BACKLOG.md')
Get-ChildItem -Force $root | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stage -Recurse -Force
}

foreach ($dir in @('data', 'data\backups', 'data\logs', 'public\uploads')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $stage $dir) | Out-Null
}

# Keep the deny-all rule in the packaged data folder (Apache defense in depth).
$dataHtaccess = Join-Path $root 'data\.htaccess'
if (Test-Path $dataHtaccess) {
    Copy-Item -Path $dataHtaccess -Destination (Join-Path $stage 'data\.htaccess') -Force
}

# Strip Python bytecode caches and the repo-only gitignore from the package.
Get-ChildItem -Path $stage -Recurse -Directory -Filter '__pycache__' -ErrorAction SilentlyContinue |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $stage '.gitignore') -Force -ErrorAction SilentlyContinue

if ($BundlePhp) {
    if (-not (Test-Path $PhpSource)) {
        throw "PHP source folder not found: $PhpSource"
    }
    Write-Host "Bundling PHP from $PhpSource"
    Copy-Item -Path $PhpSource -Destination (Join-Path $stage 'php') -Recurse -Force
    $iniPath = Join-Path $stage 'php-runtime.ini'
    (Get-Content $iniPath) -replace '^extension_dir=.*$', 'extension_dir="ext"' | Set-Content -Path $iniPath -Encoding ASCII
}

$launcher = @'
@echo off
setlocal
cd /d "%~dp0"
if not exist "%~dp0php\php.exe" (
  call "%~dp0run-pos.bat"
  exit /b 0
)
start "SAJI POS Server" /min "%~dp0php\php.exe" -c "%~dp0php-runtime.ini" -S 127.0.0.1:8000 "%~dp0router.php"
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:8000"
endlocal
'@
Set-Content -Path (Join-Path $stage 'start-saji-pos.bat') -Value $launcher -Encoding ASCII

$readme = @"
SAJI Hardware POS $version - portable build

1. Double-click start-saji-pos.bat
2. Your browser opens at http://127.0.0.1:8000
3. Default login: admin / admin123

Data is stored in the data folder next to this file. Back it up regularly.
If port 8000 is already in use, edit start-saji-pos.bat and change both
occurrences of 8000 to another port (for example 8010).

A one-click Windows installer (.exe) will replace this portable build later.
"@
Set-Content -Path (Join-Path $stage 'README-PORTABLE.txt') -Value $readme -Encoding ASCII

$zipName = if ($BundlePhp) { "$appName-$version-with-php.zip" } else { "$appName-$version.zip" }
$zipPath = Join-Path $OutDir $zipName
if (Test-Path $zipPath) {
    Remove-Item -Force $zipPath
}
Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zipPath -CompressionLevel Optimal
Write-Host "Created $zipPath"

# Record a checksum for release verification.
$hash = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()
$checksumPath = Join-Path $OutDir 'SHA256SUMS.txt'
$existing = @()
if (Test-Path $checksumPath) {
    $existing = @(Get-Content $checksumPath | Where-Object { $_.Trim() -ne '' -and $_ -notmatch [regex]::Escape($zipName) })
}
$existing += "$hash  $zipName"
Set-Content -Path $checksumPath -Value $existing -Encoding ASCII
Write-Host "SHA256 $hash"
