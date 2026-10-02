#Requires -Version 5.1
<#
.SYNOPSIS
    Menjalankan analisis SonarQube (lokal, via Docker) lengkap dengan coverage PHPUnit.

.DESCRIPTION
    Langkah otomatis:
      1. Build image app (sudah berisi pcov untuk coverage)
      2. Jalankan PHPUnit (Unit + Integration) -> coverage.xml & junit.xml
      3. Nyalakan SonarQube di http://localhost:9100 dan tunggu sampai siap
      4. Siapkan password admin & token (disimpan di .sonar-token, tidak ikut git)
      5. Jalankan scanner, tunggu Quality Gate, tampilkan ringkasan metrik
      6. Buka dashboard di browser

    Login dashboard: admin / (lihat $AdminPass di bawah, hanya untuk SonarQube lokal).

.PARAMETER SkipTests
    Lewati PHPUnit, pakai coverage.xml / junit.xml yang sudah ada.

.PARAMETER NoBrowser
    Jangan buka dashboard otomatis.

.PARAMETER Down
    Matikan SonarQube (data analisis tetap tersimpan di volume).

.EXAMPLE
    .\sonar.ps1
    .\sonar.ps1 -SkipTests
    .\sonar.ps1 -Down
#>

param(
    [switch]$SkipTests,
    [switch]$NoBrowser,
    [switch]$Down
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$HostUrl    = 'http://localhost:9100'
$ProjectKey = 'inventory-oms'
$TokenFile  = Join-Path $PSScriptRoot '.sonar-token'
# Password admin SonarQube LOKAL (default admin/admin wajib diganti). Bukan secret produksi.
$AdminPass  = 'Sonar-Admin#2026'
$Sonar      = @('-f', 'compose.sonar.yaml')

function Write-Step($m) { Write-Host ""; Write-Host ">> $m" -ForegroundColor Cyan }
function Write-Ok($m)   { Write-Host "[OK] $m" -ForegroundColor Green }
function Write-Err($m)  { Write-Host "[GAGAL] $m" -ForegroundColor Red }

function Get-AuthHeader($user, $pass) {
    $raw = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${user}:${pass}"))
    return @{ Authorization = "Basic $raw" }
}

function Invoke-Sonar($path, $header, $method = 'Get', $body = $null) {
    $args = @{ Uri = "$HostUrl$path"; Headers = $header; Method = $method; UseBasicParsing = $true }
    if ($body) { $args.Body = $body }
    return Invoke-RestMethod @args
}

# --- Docker harus aktif
Write-Step "Mengecek Docker..."
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Err "Docker tidak ditemukan. Install Docker Desktop dulu."
    exit 1
}
docker info *>$null
if ($LASTEXITCODE -ne 0) {
    Write-Err "Docker Desktop belum jalan. Nyalakan dulu lalu ulangi."
    exit 1
}
Write-Ok "Docker aktif."

if ($Down) {
    Write-Step "Mematikan SonarQube..."
    docker compose @Sonar --profile scan down
    exit $LASTEXITCODE
}

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Ok ".env dibuat dari .env.example."
}

# --- 1 & 2. Test + coverage
$testsFailed = $false
if (-not $SkipTests) {
    Write-Step "Build image app (berisi pcov)..."
    docker compose build app
    if ($LASTEXITCODE -ne 0) { Write-Err "Build image gagal."; exit 1 }

    # Kalau app sudah jalan, buat ulang container-nya agar memakai image baru (yang punya pcov)
    $running = docker compose ps --status running --services 2>$null
    if ($running -contains 'app') {
        docker compose up -d --no-deps app
    }

    Remove-Item coverage.xml, junit.xml -ErrorAction SilentlyContinue

    Write-Step "Menjalankan PHPUnit (Unit + Integration) dengan coverage..."
    $phpunit = @('php', '-d', 'pcov.enabled=1', 'vendor/bin/phpunit',
                 '--coverage-clover', 'coverage.xml', '--log-junit', 'junit.xml')
    $running = docker compose ps --status running --services 2>$null
    if ($running -contains 'app') {
        docker compose exec -T app @phpunit
    } else {
        docker compose run --rm -T app @phpunit
    }
    if ($LASTEXITCODE -ne 0) {
        $testsFailed = $true
        Write-Err "Ada test yang gagal. Analisis tetap dilanjutkan, tapi perbaiki test sebelum presentasi."
    } else {
        Write-Ok "Semua test lulus."
    }
}
if (-not (Test-Path coverage.xml)) {
    Write-Err "coverage.xml tidak ada. Jalankan tanpa -SkipTests."
    exit 1
}

# --- 3. SonarQube up
Write-Step "Menyalakan SonarQube (pertama kali bisa 2-4 menit)..."
docker compose @Sonar up -d sonarqube
if ($LASTEXITCODE -ne 0) { Write-Err "Gagal menyalakan SonarQube."; exit 1 }

$ready = $false
for ($i = 0; $i -lt 90; $i++) {
    try {
        $st = Invoke-RestMethod -Uri "$HostUrl/api/system/status" -UseBasicParsing -TimeoutSec 5
        if ($st.status -eq 'UP') { $ready = $true; break }
    } catch { }
    Start-Sleep -Seconds 4
}
if (-not $ready) { Write-Err "SonarQube belum siap setelah 6 menit. Cek: docker compose -f compose.sonar.yaml logs sonarqube"; exit 1 }
Write-Ok "SonarQube siap di $HostUrl"

# --- 4. Password admin & token
Write-Step "Menyiapkan akun & token..."
$token = $null
if (Test-Path $TokenFile) {
    $saved = (Get-Content $TokenFile -Raw).Trim()
    try {
        $v = Invoke-Sonar '/api/authentication/validate' (Get-AuthHeader $saved '')
        if ($v.valid) { $token = $saved }
    } catch { }
}
if (-not $token) {
    # Ganti password default admin/admin; kalau sudah pernah diganti, error ini diabaikan
    try {
        Invoke-Sonar '/api/users/change_password' (Get-AuthHeader 'admin' 'admin') 'Post' `
            @{ login = 'admin'; previousPassword = 'admin'; password = $AdminPass } | Out-Null
    } catch { }
    try {
        $name = "sonar-ps1-" + (Get-Date -Format 'yyyyMMddHHmmss')
        $res = Invoke-Sonar '/api/user_tokens/generate' (Get-AuthHeader 'admin' $AdminPass) 'Post' @{ name = $name }
        $token = $res.token
        Set-Content -Path $TokenFile -Value $token -NoNewline -Encoding ascii
    } catch {
        Write-Err "Gagal membuat token. Kalau password admin sudah diubah manual, hapus volume: docker compose -f compose.sonar.yaml down -v"
        exit 1
    }
}
Write-Ok "Token siap (.sonar-token)."

# --- 5. Scan
Write-Step "Menjalankan scanner & menunggu Quality Gate..."
$env:SONAR_TOKEN = $token
docker compose @Sonar run --rm scanner
$scanExit = $LASTEXITCODE
Remove-Item Env:SONAR_TOKEN -ErrorAction SilentlyContinue

# --- Ringkasan
$metrics = 'alert_status,bugs,vulnerabilities,security_hotspots,code_smells,coverage,duplicated_lines_density,ncloc,reliability_rating,security_rating,sqale_rating'
try {
    $m = Invoke-Sonar "/api/measures/component?component=$ProjectKey&metricKeys=$metrics" (Get-AuthHeader $token '')
    Write-Step "Ringkasan hasil ($ProjectKey)"
    foreach ($x in $m.component.measures) { "{0,-26} {1}" -f $x.metric, $x.value }
} catch {
    Write-Err "Tidak bisa mengambil ringkasan metrik."
}

$dash = "$HostUrl/dashboard?id=$ProjectKey"
Write-Host ""
if ($scanExit -eq 0) { Write-Ok "Quality Gate: PASSED" } else { Write-Err "Quality Gate gagal / scan error (exit $scanExit)" }
Write-Host "Dashboard : $dash"
Write-Host "Login     : admin / $AdminPass"
if (-not $NoBrowser) { Start-Process $dash }

if ($scanExit -ne 0 -or $testsFailed) { exit 1 }
exit 0
