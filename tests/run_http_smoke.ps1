# Starts the built-in server on port 8011, runs tests/http_smoke.ps1, then stops it.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\run_http_smoke.ps1

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$ini = Join-Path $root 'php-runtime.ini'
$smoke = Join-Path $PSScriptRoot 'http_smoke.ps1'
$port = 8011
$logDir = Join-Path $env:TEMP 'saji-http-smoke'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$stdout = Join-Path $logDir 'server.out.log'
$stderr = Join-Path $logDir 'server.err.log'

if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
    throw "Port $port is already in use. Stop the existing server before running this suite."
}

$server = Start-Process -FilePath 'php' `
    -ArgumentList @('-c', $ini, '-S', "127.0.0.1:$port", 'router.php') `
    -WorkingDirectory $root -PassThru -WindowStyle Hidden `
    -RedirectStandardOutput $stdout -RedirectStandardError $stderr

$exitCode = 1
try {
    $ready = $false
    for ($i = 0; $i -lt 40; $i++) {
        if ($server.HasExited) { break }
        try {
            $health = Invoke-RestMethod -Uri "http://127.0.0.1:$port/api.php?action=health" -TimeoutSec 2
            if ($health.ok) { $ready = $true; break }
        } catch {
            Start-Sleep -Milliseconds 250
        }
    }
    if (-not $ready) {
        $details = if (Test-Path $stderr) { Get-Content $stderr -Raw } else { '' }
        throw "Server did not become ready on port $port. $details"
    }

    & powershell -NoProfile -ExecutionPolicy Bypass -File $smoke
    $exitCode = $LASTEXITCODE
} finally {
    if (-not $server.HasExited) {
        Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    }
}

exit $exitCode
