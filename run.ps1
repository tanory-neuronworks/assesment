# Menjalankan aplikasi via Docker Compose.
# Cara pakai: .\run.ps1           (jalankan app)
#             .\run.ps1 -Sonar    (jalankan app + analisis SonarQube, lihat sonar.ps1)

param([switch]$Sonar)

# Pindah ke folder script ini, supaya bisa dijalankan dari mana saja
Set-Location -Path $PSScriptRoot

# Tampilkan versi Docker (sekaligus memastikan Docker terpasang)
Write-Host "`n=============== Cek versi Docker ===============" -ForegroundColor Cyan
docker --version

# Buat file .env dari template kalau belum ada (berisi konfigurasi database)
Write-Host "`n=============== Siapkan file .env ===============" -ForegroundColor Cyan
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host ".env dibuat dari .env.example"
} else {
    Write-Host ".env sudah ada, dilewati"
}

# Build image dari Dockerfile, lalu jalankan app + MySQL di background (-d)
Write-Host "`n=============== Build image & jalankan container ===============" -ForegroundColor Cyan
docker compose up --build -d

# Tampilkan status container (harus "Up", idealnya "healthy")
Write-Host "`n=============== Status container (harus Up / healthy) ===============" -ForegroundColor Cyan
docker compose ps

# Tampilkan 20 baris log terakhir dari app (cek tidak ada "Fatal error")
Write-Host "`n=============== 20 baris log terakhir app (cek tidak ada Fatal error) ===============" -ForegroundColor Cyan
docker compose logs app --tail 20

# Opsional: analisis SonarQube (test + coverage + scan), butuh beberapa menit
if ($Sonar) {
    Write-Host "`n=============== Analisis SonarQube ===============" -ForegroundColor Cyan
    & (Join-Path $PSScriptRoot 'sonar.ps1')
}
