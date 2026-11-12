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

    # 5. Login proceeds directly to the application without a password-change gate
    $dashboard = Invoke-RestMethod -Uri "${base}?action=dashboard" -Method Post -Body '{}' -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'dashboard is available immediately after login' ($null -ne $dashboard.today)

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

    # 14. CSV import template and preview (preview never writes)
    $template = Invoke-RestMethod -Uri "${base}?action=import_template&type=products" -WebSession $session -Headers $headers
    Assert 'import template returns csv' ($template.csv -match 'sku' -and $template.csv -match 'selling_price')
    $previewCsv = "sku,name,category,selling_price`nSMOKE-1,Smoke Product,Test,10`n"
    $previewBody = @{ type = 'products'; mode = 'skip'; csv = $previewCsv } | ConvertTo-Json
    $preview = Invoke-RestMethod -Uri "${base}?action=import_preview" -Method Post -Body $previewBody -ContentType 'application/json' -WebSession $session -Headers $headers
    Assert 'import preview counts rows' ($preview.row_count -eq 1 -and $preview.insert -eq 1) "row_count=$($preview.row_count) insert=$($preview.insert)"
    Assert 'import preview has no errors' (@($preview.errors).Count -eq 0)

    # 15. Admin password reset signs out the target without forcing a password change
    $resetUsername = 'smoke_reset_' + (Get-Random -Minimum 10000 -Maximum 99999)
    $resetUserId = $null
    try {
        $createBody = @{ username = $resetUsername; full_name = 'Smoke Reset'; password = 'InitialPass123!'; role = 'EMPLOYEE'; branch = 'Main' } | ConvertTo-Json
        Invoke-RestMethod -Uri "${base}?action=create_user" -Method Post -Body $createBody -ContentType 'application/json' -WebSession $session -Headers $headers | Out-Null
        $users = (Invoke-RestMethod -Uri "${base}?action=list&table=users" -WebSession $session -Headers $headers).rows
        $resetUser = $users | Where-Object { $_.username -eq $resetUsername } | Select-Object -First 1
        $resetUserId = $resetUser.id
        Assert 'reset test user created' ($null -ne $resetUserId)

        $resetSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $initialLoginBody = @{ username = $resetUsername; password = 'InitialPass123!' } | ConvertTo-Json
        $initialLogin = Invoke-RestMethod -Uri "${base}?action=login" -Method Post -Body $initialLoginBody -ContentType 'application/json' -WebSession $resetSession
        Assert 'reset test user can sign in directly' ($initialLogin.user.username -eq $resetUsername)

        $reset = Invoke-RestMethod -Uri "${base}?action=reset_user_password" -Method Post -Body (@{ id = $resetUserId } | ConvertTo-Json) -ContentType 'application/json' -WebSession $session -Headers $headers
        Assert 'password reset returns a temporary password' ($reset.temporary_password.Length -ge 10)
        $afterReset = Invoke-RestMethod -Uri "${base}?action=me" -WebSession $resetSession
        Assert 'password reset invalidates the old session' ($null -eq $afterReset.user)

        $tempLoginBody = @{ username = $resetUsername; password = $reset.temporary_password } | ConvertTo-Json
        $tempLogin = Invoke-RestMethod -Uri "${base}?action=login" -Method Post -Body $tempLoginBody -ContentType 'application/json' -WebSession (New-Object Microsoft.PowerShell.Commands.WebRequestSession)
        Assert 'temporary password works without a forced change' ($tempLogin.user.username -eq $resetUsername)
    } finally {
        if ($resetUserId) {
            try {
                Invoke-RestMethod -Uri "${base}?action=delete_user" -Method Post -Body (@{ id = $resetUserId } | ConvertTo-Json) -ContentType 'application/json' -WebSession $session -Headers $headers | Out-Null
            } catch { }
        }
    }

    # 16. Login throttling (dummy username, leaves the real admin untouched)
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
