param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'
$projectPath = Split-Path $PSScriptRoot -Parent
$runtimePath = Join-Path (Split-Path $projectPath -Parent) '.runtime'
$phpPath = Join-Path $runtimePath 'php\php.exe'
if (-not (Test-Path -LiteralPath $phpPath)) {
    $phpPath = (Get-Command php -ErrorAction Stop).Source
}
if (-not (Test-Path -LiteralPath (Join-Path $projectPath '.env'))) {
    throw 'Configure .env using .env.example first. See README.md.'
}
if (-not (Test-Path -LiteralPath (Join-Path $projectPath 'public\build\manifest.json'))) {
    throw 'Build the frontend first with pnpm build. See README.md.'
}
$pgReady = Join-Path $runtimePath 'postgres\pgsql\bin\pg_isready.exe'
if (Test-Path -LiteralPath $pgReady) {
    & $pgReady -h 127.0.0.1 -p 54329 -q
    if ($LASTEXITCODE -ne 0) {
        $pgControl = Join-Path $runtimePath 'postgres\pgsql\bin\pg_ctl.exe'
        $pgData = Join-Path $runtimePath 'pgdata'
        $pgLog = Join-Path $runtimePath 'postgres.log'
        & $pgControl -D $pgData -l $pgLog -o '-h 127.0.0.1 -p 54329' -w start
        if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL could not start. Check .runtime/postgres.log.' }
    }
}
Push-Location $projectPath
try {
    Write-Host "Personal OS is starting at http://127.0.0.1:$Port"
    Write-Host 'Press Ctrl+C to stop the website. Your saved data stays in PostgreSQL.'
    & $phpPath artisan serve --host=127.0.0.1 --port=$Port --no-reload
} finally { Pop-Location }
