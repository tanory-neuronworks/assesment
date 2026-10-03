#!/usr/bin/env bash
# Menjalankan aplikasi via Docker Compose (padanan run.ps1 untuk macOS/Linux).
# Cara pakai: ./run.sh            (jalankan app)
#             ./run.sh --sonar    (jalankan app + analisis SonarQube, lihat sonar.sh)

set -u

# Pindah ke folder script ini, supaya bisa dijalankan dari mana saja
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1

SONAR=0
for arg in "$@"; do
    case "$arg" in
        --sonar|-s) SONAR=1 ;;
        -h|--help)  sed -n '2,4p' "$0"; exit 0 ;;
        *) echo "Opsi tidak dikenal: $arg (pakai --sonar)"; exit 1 ;;
    esac
done

step() { printf '\n\033[36m=============== %s ===============\033[0m\n' "$1"; }

# Tampilkan versi Docker (sekaligus memastikan Docker terpasang)
step "Cek versi Docker"
docker --version || { echo "Docker tidak ditemukan. Install Docker Desktop dulu."; exit 1; }

# Buat file .env dari template kalau belum ada (berisi konfigurasi database)
step "Siapkan file .env"
if [ ! -f .env ]; then
    cp .env.example .env
    echo ".env dibuat dari .env.example"
else
    echo ".env sudah ada, dilewati"
fi

# Build image dari Dockerfile, lalu jalankan app + MySQL di background (-d)
step "Build image & jalankan container"
docker compose up --build -d || exit 1

# Tampilkan status container (harus "Up", idealnya "healthy")
step "Status container (harus Up / healthy)"
docker compose ps

# Tampilkan 20 baris log terakhir dari app (cek tidak ada "Fatal error")
step "20 baris log terakhir app (cek tidak ada Fatal error)"
docker compose logs app --tail 20

# Opsional: analisis SonarQube (test + coverage + scan), butuh beberapa menit
if [ "$SONAR" -eq 1 ]; then
    step "Analisis SonarQube"
    ./sonar.sh
fi
