$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$ini = Join-Path $root 'php-runtime.ini'
$resetScript = Join-Path $root 'tests\reset_admin.php'
$base = 'http://127.0.0.1:8011/api.php'
$failures = 0

function Assert($name, $condition, $detail = '') {
    if ($condition) { Write-Output "PASS  $name" }
    else { Write-Output "FAIL  $name $detail"; $script:failures++ }
}

function Read-ErrorBody($err) {
    try {
        $stream = $err.Exception.Response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        return $reader.ReadToEnd()
    } catch { return '' }
}

# Known starting state.
& php -c $ini $resetScript | Out-Null

try {
    # 1. Security headers on a public endpoint
    $health = Invoke-WebRequest -Uri "${base}?action=health" -UseBasicParsing
    Assert 'health endpoint responds' ($health.StatusCode -eq 200)
    Assert 'CSP header present' ([bool]$health.Headers['Content-Security-Policy'])
    Assert 'nosniff header present' ($health.Headers['X-Content-Type-Options'] -contains 'nosniff')

    # 2. Session + CSRF token issued by /me
    $me = Invoke-RestMethod -Uri "${base}?action=me" -SessionVariable session
    Assert 'me returns csrf token' ([bool]$me.csrf)
    Assert 'me returns license status' ([bool]$me.license)
    Assert 'me returns version' ([bool]$me.version)

    # 3. Login (exempt from CSRF) and read the returned token
    $loginBody = @{ username = 'admin'; password = 'admin123' } | ConvertTo-Json
    $login = Invoke-RestMethod -Uri "${base}?action=login" -Method Post -Body $loginBody -ContentType 'application/json' -WebSession $session
    Assert 'login returns user' ([bool]$login.user.id)
    Assert 'login returns csrf token' ([bool]$login.csrf)
    Assert 'login reports password-change flag' ($login.must_change_password -eq $true)
    $csrf = $login.csrf
    $headers = @{ 'X-CSRF-Token' = $csrf }

    # 4. Mutating request without CSRF must be rejected
    $noCsrfStatus = 0
    try {
        Invoke-WebRequest -Uri "${base}?action=dashboard" -Method Post -Body '{}' -ContentType 'application/json' -WebSession $session -UseBasicParsing | Out-Null
        $noCsrfStatus = 200
    } catch {
        $noCsrfStatus = [int]$_.Exception.Response.StatusCode
    }
    Assert 'request without CSRF is rejected' ($noCsrfStatus -eq 403) "status=$noCsrfStatus"

    # 5. Forced password change is enforced server-side
    $gateStatus = 0
    $gateBody = ''
    try {
        Invoke-WebRequest -Uri "${base}?action=dashboard" -Method Post -Body '{}' -ContentType 'application/json' -WebSession $session -Headers $headers -UseBasicParsing | Out-Null
        $gateStatus = 200
    } catch {
        $gateStatus = [int]$_.Exception.Response.StatusCode
        $gateBody = Read-ErrorBody $_
    }
    Assert 'dashboard blocked until password changes' ($gateStatus -eq 403) "status=$gateStatus"
    Assert 'blocked response carries PASSWORD_CHANGE_REQUIRED' ($gateBody -match 'PASSWORD_CHANGE_REQUIRED') $gateBody

    # 6. Change the password, then the gate opens
    $changeBody = @{ current_password = 'admin123'; new_password = 'SmokeTest!2026' } | ConvertTo-Json
    $change = Invoke-RestMethod -Uri "${base}?action=change_password" -Method Post -Body $changeBody -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'password change succeeds' ($change.ok -eq $true)
    $meAfter = Invoke-RestMethod -Uri "${base}?action=me" -WebSession $session
    Assert 'must_change_password cleared' ($meAfter.must_change_password -eq $false)

    # 7. Mutating request with CSRF succeeds after the change
    $dashboard = Invoke-RestMethod -Uri "${base}?action=dashboard" -Method Post -Body '{}' -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'request with CSRF succeeds' ($null -ne $dashboard.today)

    # 8. License endpoints
    $license = Invoke-RestMethod -Uri "${base}?action=license_status" -WebSession $session -Headers $headers
    Assert 'license_status returns state' ([bool]$license.license.state)
    Assert 'trial or licensed state is valid' ($license.license.valid -eq $true) "state=$($license.license.state)"
    Assert 'license reports clock guard field' ($null -ne $license.license.clock_tampered)

    # 9. Backup listing
    $backups = Invoke-RestMethod -Uri "${base}?action=backup_list" -WebSession $session -Headers $headers
    Assert 'backup_list returns backups' ($backups.backups.Count -gt 0)
    Assert 'backup_list returns auto setting' ($null -ne $backups.auto_enabled)

    # 10. Create and delete a manual backup through the API
    $created = Invoke-RestMethod -Uri "${base}?action=backup" -Method Post -Body '{}' -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'manual backup created' ($created.ok -eq $true -and [bool]$created.file)
    $deleteBody = @{ file = $created.file } | ConvertTo-Json
    $deleted = Invoke-RestMethod -Uri "${base}?action=backup_delete" -Method Post -Body $deleteBody -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'manual backup deleted' ($deleted.ok -eq $true)

    # 11. Restore requires the typed confirmation
    $restoreStatus = 0
    try {
        $restoreBody = @{ file = $backups.backups[0].file; confirm = 'NO' } | ConvertTo-Json
        Invoke-WebRequest -Uri "${base}?action=backup_restore" -Method Post -Body $restoreBody -ContentType 'application/json' -WebSession $session -Headers $headers -UseBasicParsing | Out-Null
        $restoreStatus = 200
    } catch {
        $restoreStatus = [int]$_.Exception.Response.StatusCode
    }
    Assert 'restore rejects missing confirmation' ($restoreStatus -eq 400) "status=$restoreStatus"

    # 12. Path traversal on download is rejected
    $traversalStatus = 0
    try {
        Invoke-WebRequest -Uri "${base}?action=backup_download&file=..%5Cconfig.php" -WebSession $session -UseBasicParsing | Out-Null
        $traversalStatus = 200
    } catch {
        $traversalStatus = [int]$_.Exception.Response.StatusCode
    }
    Assert 'backup download rejects traversal' ($traversalStatus -eq 400 -or $traversalStatus -eq 404) "status=$traversalStatus"

    # 13. Router path traversal (the built-in server does not apply .htaccess)
    $traversalPaths = @(
        'http://127.0.0.1:8011/..%2fconfig.php',
        'http://127.0.0.1:8011/..%2fdata%2fsaji_pos.sqlite3',
        'http://127.0.0.1:8011/..%2ftools%2fkeys%2fprivate.key',
        'http://127.0.0.1:8011/%2e%2e%2fconfig.php',
        'http://127.0.0.1:8011/api/config.php',
        'http://127.0.0.1:8011/api/bootstrap.php'
    )
    foreach ($traversalPath in $traversalPaths) {
        $traversalHttpStatus = 0
        try {
            Invoke-WebRequest -Uri $traversalPath -UseBasicParsing | Out-Null
            $traversalHttpStatus = 200
        } catch {
            $traversalHttpStatus = [int]$_.Exception.Response.StatusCode
        }
        Assert "blocked traversal $traversalPath" ($traversalHttpStatus -eq 404) "status=$traversalHttpStatus"
    }

    # 14. Login throttling (dummy username, leaves the real admin untouched)
    $throttleStatus = 0
    $ghostSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    for ($i = 1; $i -le 6; $i++) {
        $body = @{ username = 'ghost_user'; password = 'wrong' } | ConvertTo-Json
        try {
            Invoke-WebRequest -Uri "${base}?action=login" -Method Post -Body $body -ContentType 'application/json' -WebSession $ghostSession -UseBasicParsing | Out-Null
            $throttleStatus = 200
        } catch {
            $throttleStatus = [int]$_.Exception.Response.StatusCode
        }
    }
    Assert 'repeated failed logins are throttled' ($throttleStatus -eq 429) "status=$throttleStatus"
}
finally {
    # Always leave the documented default login in place.
    & php -c $ini $resetScript | Out-Null
}

Write-Output ""
Write-Output "$failures failure(s)"
exit $failures
