#Requires -Version 5.1
<#
.SYNOPSIS
    Menjalankan PHPUnit (unit / integration test) di dalam container Docker.

.DESCRIPTION
    PHP tidak perlu terinstall di host. Kalau container 'app' sudah jalan
    (via .\run.ps1), test dijalankan dengan 'docker compose exec'. Kalau belum,
    dipakai container sementara 'docker compose run --rm'.

    Unit test tidak butuh MySQL, jadi container mysql tidak ikut dinyalakan.
    Integration test butuh MySQL, jadi mysql otomatis dinyalakan dulu.

.PARAMETER Suite
    Unit (default), Integration, atau All.

.PARAMETER Filter
    Hanya jalankan test yang namanya cocok (diteruskan ke 'phpunit --filter').

.EXAMPLE
    .\test.ps1
    .\test.ps1 -Suite Integration
    .\test.ps1 -Suite All
    .\test.ps1 -Filter SalesOrderApprovalTest
    .\test.ps1 -Filter testRejectsEmptyItems
#>

param(
    [ValidateSet('Unit', 'Integration', 'All')]
    [string]$Suite = 'Unit',
    [string]$Filter
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

function Write-Step($message) {
    Write-Host ""
    Write-Host ">> $message" -ForegroundColor Cyan
}

function Write-Ok($message) {
    Write-Host "[OK] $message" -ForegroundColor Green
}

function Write-Err($message) {
    Write-Host "[GAGAL] $message" -ForegroundColor Red
}

# 1. Pastikan Docker CLI tersedia & daemon jalan
Write-Step "Mengecek Docker..."
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Err "Docker tidak ditemukan. Install Docker Desktop dulu: https://www.docker.com/products/docker-desktop/"
    exit 1
}

docker info *>$null
if ($LASTEXITCODE -ne 0) {
    Write-Err "Docker Desktop belum jalan. Nyalakan Docker Desktop lalu jalankan script ini lagi."
    exit 1
}
Write-Ok "Docker aktif."

# 2. .env dibutuhkan compose (env_file) dan tests/bootstrap.php
if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Ok ".env belum ada, disalin dari .env.example."
}

# 3. Susun argumen phpunit
$phpunitArgs = @('vendor/bin/phpunit')
if ($Suite -ne 'All') {
    $phpunitArgs += @('--testsuite', $Suite)
}
if ($Filter) {
    $phpunitArgs += @('--filter', $Filter)
}

# 4. Jalankan di container yang sudah ada, atau container sementara
$runningServices = docker compose ps --status running --services 2>$null
$appRunning = $runningServices -contains 'app'

Write-Step "Menjalankan test suite: $Suite$(if ($Filter) { " (filter: $Filter)" })"

if ($appRunning) {
    Write-Ok "Container 'app' sudah jalan, memakai 'docker compose exec'."
    docker compose exec -T app @phpunitArgs
} elseif ($Suite -eq 'Unit') {
    Write-Ok "Container 'app' belum jalan, memakai container sementara (tanpa MySQL)."
    docker compose run --rm --no-deps -T app @phpunitArgs
} else {
    Write-Ok "Container 'app' belum jalan, menyalakan MySQL + container sementara (run pertama bisa agak lama)..."
    docker compose run --rm -T app @phpunitArgs
}

$exitCode = $LASTEXITCODE

Write-Host ""
if ($exitCode -eq 0) {
    Write-Ok "Semua test lulus."
} else {
    Write-Err "Ada test yang gagal (exit code $exitCode). Lihat output di atas."
}

exit $exitCode
