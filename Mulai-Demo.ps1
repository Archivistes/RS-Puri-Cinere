param(
    [string]$Php = 'php',
    [string]$PgBin = '',
    [string]$DemoDir = (Join-Path $PSScriptRoot '.demo')
)
$ErrorActionPreference = 'Stop'
$phpExe = (Get-Command $Php).Source
if (!$PgBin) { $PgBin = Split-Path -Parent (Get-Command psql).Source }
$psql = Join-Path $PgBin 'psql.exe'
$pgctl = Join-Path $PgBin 'pg_ctl.exe'
$initdb = Join-Path $PgBin 'initdb.exe'
$phpFlags = @('-d','display_errors=0')
if ((& $phpExe -m) -notcontains 'pdo_pgsql') {
    $extensionDir = Join-Path (Split-Path -Parent $phpExe) 'ext'
    $phpFlags += @('-d',"extension_dir=$extensionDir",'-d','extension=pdo_pgsql')
}
if ((& $phpExe @phpFlags -m) -notcontains 'pdo_pgsql') { throw 'Ekstensi PHP pdo_pgsql harus aktif.' }

$DemoDir = [IO.Path]::GetFullPath($DemoDir)
$cluster = Join-Path $DemoDir 'postgres'
$credentials = Join-Path $DemoDir 'db-passwords.json'
$initialized = Join-Path $DemoDir 'ready'
$fresh = !(Test-Path -LiteralPath $initialized)
if ($fresh -and (Test-Path -LiteralPath $cluster)) {
    throw 'Inisialisasi demo sebelumnya belum selesai. Gunakan -DemoDir dengan direktori kosong lain.'
}
$ownsServer = $false
$envNames = @('PGPASSWORD','POLI_DSN','POLI_DB_USER','POLI_DB_PASSWORD','POLI_DEMO_PASSWORD','POLI_HTTPS')
$previous = @{}
foreach ($name in $envNames) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }

function RunPsql {
    & $psql -X -v ON_ERROR_STOP=1 -w -h 127.0.0.1 -p 55437 -U postgres @args
    if ($LASTEXITCODE -ne 0) { throw 'Perintah setup PostgreSQL gagal.' }
}

try {
    if ($fresh) {
        New-Item -ItemType Directory -Force -Path $DemoDir | Out-Null
        $ownerPassword = [guid]::NewGuid().ToString('N')
        $appPassword = [guid]::NewGuid().ToString('N')
        @{
            owner = ($ownerPassword | ConvertTo-SecureString -AsPlainText -Force | ConvertFrom-SecureString)
            app = ($appPassword | ConvertTo-SecureString -AsPlainText -Force | ConvertFrom-SecureString)
        } | ConvertTo-Json | Set-Content -LiteralPath $credentials -Encoding utf8
        $pwFile = Join-Path $DemoDir 'init-password.tmp'
        [IO.File]::WriteAllText($pwFile,$ownerPassword)
        try {
            & $initdb -D $cluster -U postgres -A scram-sha-256 -E UTF8 --locale=C "--pwfile=$pwFile" --no-instructions
            if ($LASTEXITCODE -ne 0) { throw 'Inisialisasi PostgreSQL gagal.' }
        } finally {
            Remove-Item -LiteralPath $pwFile -ErrorAction SilentlyContinue
        }
    } else {
        $saved = Get-Content -Raw -LiteralPath $credentials | ConvertFrom-Json
        $ownerPassword = [Net.NetworkCredential]::new('',(ConvertTo-SecureString $saved.owner)).Password
        $appPassword = [Net.NetworkCredential]::new('',(ConvertTo-SecureString $saved.app)).Password
    }
    & $pgctl -D $cluster -l (Join-Path $DemoDir 'postgres.log') -o '-p 55437 -h 127.0.0.1' start
    if ($LASTEXITCODE -ne 0) { throw 'Periksa postgres.log dan ketersediaan port 55437.' }
    $ownsServer = $true
    $env:PGPASSWORD = $ownerPassword
    if ($fresh) {
        RunPsql -d postgres -f (Join-Path $PSScriptRoot 'sql/00_database.sql')
        RunPsql -d dashboard_poli -c "CREATE ROLE dashboard_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE"
        # Feed the credential over stdin, away from command arguments.
        $alter = "ALTER ROLE dashboard_app PASSWORD '$appPassword';"
        $alter | & $psql -X -v ON_ERROR_STOP=1 -w -h 127.0.0.1 -p 55437 -U postgres -d dashboard_poli -f -
        if ($LASTEXITCODE -ne 0) { throw 'Hak akses database demo gagal disiapkan.' }
        $schema = Join-Path $PSScriptRoot 'sql/01_skema.sql'
        $seed = Join-Path $PSScriptRoot 'sql/02_data_demo.sql'
        $grants = Join-Path $PSScriptRoot 'sql/04_hak_akses.sql'
        RunPsql -d dashboard_poli -f $schema -f $seed -f $grants
    }
    $env:POLI_DSN = 'pgsql:host=127.0.0.1;port=55437;dbname=dashboard_poli'
    $env:POLI_DB_USER = 'postgres'
    $env:POLI_DB_PASSWORD = $ownerPassword
    $env:POLI_DEMO_PASSWORD = 'Demo-' + [guid]::NewGuid().ToString('N').Substring(0,16)
    & $phpExe @phpFlags (Join-Path $PSScriptRoot 'scripts/demo-password.php')
    if ($LASTEXITCODE -ne 0) { throw 'Pengaturan akun demo gagal.' }
    New-Item -ItemType File -Force -Path $initialized | Out-Null
    $env:POLI_DB_USER = 'dashboard_app'
    $env:POLI_DB_PASSWORD = $appPassword
    $env:POLI_HTTPS = '0'
    Write-Host 'Buka http://127.0.0.1:8789 di browser.'
    Write-Host 'Akun: admin.demo atau petugas.dalam'
    Write-Host "Kata sandi demo: $env:POLI_DEMO_PASSWORD"
    Remove-Item Env:POLI_DEMO_PASSWORD,Env:PGPASSWORD -ErrorAction SilentlyContinue
    & $phpExe @phpFlags -S '127.0.0.1:8789' -t (Join-Path $PSScriptRoot 'public')
} finally {
    if ($ownsServer) { & $pgctl -D $cluster stop -m fast }
    foreach ($name in $envNames) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
}
