# PowerShell script to backup DB, create DB if missing, run migrations, and assign default tenant
# Usage: Open PowerShell in project root and run:
#   powershell -ExecutionPolicy Bypass -File .\scripts\migrate_and_assign.ps1

Set-StrictMode -Version Latest

function Read-EnvValue {
    param([string]$key)
    if (-Not (Test-Path -Path ".env")) { return $null }
    $line = Select-String -Path ".env" -Pattern "^$key=" -SimpleMatch -Quiet:$false | Select-Object -First 1
    if (-not $line) { return $null }
    $val = $line -replace "^$key=", ""
    # Trim possible quotes
    $val = $val.Trim('"')
    return $val
}

Write-Host "Reading DB config from .env..."
$DB_HOST = Read-EnvValue -key "DB_HOST"
$DB_PORT = Read-EnvValue -key "DB_PORT"
$DB_NAME = Read-EnvValue -key "DB_DATABASE"
$DB_USER = Read-EnvValue -key "DB_USERNAME"
$DB_PASS = Read-EnvValue -key "DB_PASSWORD"

if (-not $DB_HOST) { Write-Error ".env DB_HOST not found."; exit 1 }
if (-not $DB_PORT) { $DB_PORT = 3306 }
if (-not $DB_NAME) { Write-Error ".env DB_DATABASE not found."; exit 1 }
if (-not $DB_USER) { $DB_USER = "root" }

Write-Host "DB host: $DB_HOST`:$DB_PORT, DB: $DB_NAME, User: $DB_USER"

# Ensure backups folder
$backupDir = Join-Path -Path "." -ChildPath "backups"
if (-not (Test-Path -Path $backupDir)) { New-Item -ItemType Directory -Path $backupDir | Out-Null }
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupFile = Join-Path $backupDir ("backup_${DB_NAME}_$timestamp.sql")

# Check mysqldump availability
$mysqldump = Get-Command mysqldump -ErrorAction SilentlyContinue
if ($mysqldump) {
    Write-Host "Creating DB backup to $backupFile ..."
    $pwdArg = if ($DB_PASS -and $DB_PASS -ne "") { "-p$DB_PASS" } else { "" }
    $dumpCmd = "mysqldump -h $DB_HOST -P $DB_PORT -u $DB_USER $pwdArg $DB_NAME > `"$backupFile`""
    Write-Host $dumpCmd
    cmd.exe /c $dumpCmd
    if ($LASTEXITCODE -ne 0) { Write-Warning "mysqldump returned exit code $LASTEXITCODE — continuing but please verify backup." }
} else {
    Write-Warning "mysqldump not found in PATH — skipping DB dump. Use phpMyAdmin or Laragon to export database manually before continuing." 
}

# Create DB if missing
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if ($mysql) {
    Write-Host "Ensuring database $DB_NAME exists..."
    if ($DB_PASS -and $DB_PASS -ne "") { $pwdArg = "-p$DB_PASS" } else { $pwdArg = "" }
    $createCmd = "mysql -h $DB_HOST -P $DB_PORT -u $DB_USER $pwdArg -e \"CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
    Write-Host $createCmd
    cmd.exe /c $createCmd
    if ($LASTEXITCODE -ne 0) { Write-Warning "mysql client returned exit code $LASTEXITCODE — database creation may have failed." }
} else {
    Write-Warning "mysql client not found in PATH — cannot auto-create database. Please create $DB_NAME manually if it doesn't exist." 
}

# Run artisan commands
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { Write-Error "php not found in PATH. Ensure Laragon php is in PATH or run the script from a shell where php is available."; exit 2 }

function Run-Artisan([string]$args) {
    Write-Host "\n-> php artisan $args"
    & php artisan $args
    if ($LASTEXITCODE -ne 0) { Write-Error "artisan $args failed with exit code $LASTEXITCODE"; exit $LASTEXITCODE }
}

Run-Artisan "migrate:status"
Run-Artisan "migrate --force"
Run-Artisan "tenants:assign-default"
Run-Artisan "migrate:status"

# Optional: run tests
Write-Host "\nRunning test suite (optional). This may take a while."
Run-Artisan "test"

Write-Host "\nDone. Verify the application and the backups in the 'backups' folder. If anything failed, inspect output above and paste errors to get help."