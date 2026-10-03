#!/usr/bin/env bash
# Menjalankan analisis SonarQube (lokal, via Docker) lengkap dengan coverage PHPUnit.
# Padanan sonar.ps1 untuk macOS/Linux. Hanya butuh bash, curl, dan Docker.
#
# Langkah otomatis:
#   1. Build image app (sudah berisi pcov untuk coverage)
#   2. Jalankan PHPUnit (Unit + Integration) -> coverage.xml & junit.xml
#   3. Nyalakan SonarQube di http://localhost:9100 dan tunggu sampai siap
#   4. Siapkan password admin & token (disimpan di .sonar-token, tidak ikut git)
#   5. Jalankan scanner, tunggu Quality Gate, tampilkan ringkasan metrik
#   6. Buka dashboard di browser
#
# Opsi:
#   --skip-tests   Lewati PHPUnit, pakai coverage.xml / junit.xml yang sudah ada
#   --no-browser   Jangan buka dashboard otomatis
#   --down         Matikan SonarQube (data analisis tetap tersimpan di volume)
#
# Contoh: ./sonar.sh   |   ./sonar.sh --skip-tests   |   ./sonar.sh --down
#
# Login dashboard: admin / (lihat ADMIN_PASS di bawah, hanya untuk SonarQube lokal).

set -u
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1

SKIP_TESTS=0; NO_BROWSER=0; DOWN=0
for arg in "$@"; do
    case "$arg" in
        --skip-tests) SKIP_TESTS=1 ;;
        --no-browser) NO_BROWSER=1 ;;
        --down)       DOWN=1 ;;
        -h|--help)    sed -n '2,22p' "$0"; exit 0 ;;
        *) echo "Opsi tidak dikenal: $arg"; exit 1 ;;
    esac
done

HOST_URL='http://localhost:9100'
PROJECT_KEY='inventory-oms'
TOKEN_FILE='.sonar-token'
# Password admin SonarQube LOKAL (default admin/admin wajib diganti). Bukan secret produksi.
ADMIN_PASS='Sonar-Admin#2026'
SONAR=(-f compose.sonar.yaml)

step() { printf '\n\033[36m>> %s\033[0m\n' "$1"; }
ok()   { printf '\033[32m[OK] %s\033[0m\n' "$1"; }
err()  { printf '\033[31m[GAGAL] %s\033[0m\n' "$1"; }

# --- Docker harus aktif
step "Mengecek Docker..."
if ! command -v docker >/dev/null 2>&1; then
    err "Docker tidak ditemukan. Install Docker Desktop dulu."; exit 1
fi
if ! docker info >/dev/null 2>&1; then
    err "Docker belum jalan. Nyalakan dulu lalu ulangi."; exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
    err "curl tidak ditemukan."; exit 1
fi
ok "Docker aktif."

if [ "$DOWN" -eq 1 ]; then
    step "Mematikan SonarQube..."
    docker compose "${SONAR[@]}" --profile scan down
    exit $?
fi

if [ ! -f .env ]; then
    cp .env.example .env
    ok ".env dibuat dari .env.example."
fi

# app berjalan? (dipakai untuk memilih exec vs run)
app_running() { docker compose ps --status running --services 2>/dev/null | grep -qx 'app'; }

# --- 1 & 2. Test + coverage
TESTS_FAILED=0
if [ "$SKIP_TESTS" -eq 0 ]; then
    step "Build image app (berisi pcov)..."
    docker compose build app || { err "Build image gagal."; exit 1; }

    # Kalau app sudah jalan, buat ulang container-nya agar memakai image baru (yang punya pcov)
    if app_running; then docker compose up -d --no-deps app; fi

    rm -f coverage.xml junit.xml

    step "Menjalankan PHPUnit (Unit + Integration) dengan coverage..."
    PHPUNIT=(php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover coverage.xml --log-junit junit.xml)
    if app_running; then
        docker compose exec -T app "${PHPUNIT[@]}"
    else
        docker compose run --rm -T app "${PHPUNIT[@]}"
    fi
    if [ $? -ne 0 ]; then
        TESTS_FAILED=1
        err "Ada test yang gagal. Analisis tetap dilanjutkan, tapi perbaiki test sebelum presentasi."
    else
        ok "Semua test lulus."
    fi
fi
if [ ! -f coverage.xml ]; then
    err "coverage.xml tidak ada. Jalankan tanpa --skip-tests."; exit 1
fi

# --- 3. SonarQube up
step "Menyalakan SonarQube (pertama kali bisa 2-4 menit)..."
docker compose "${SONAR[@]}" up -d sonarqube || { err "Gagal menyalakan SonarQube."; exit 1; }

READY=0
for _ in $(seq 1 90); do
    if curl -fs --max-time 5 "$HOST_URL/api/system/status" 2>/dev/null | grep -q '"status":"UP"'; then
        READY=1; break
    fi
    sleep 4
done
if [ "$READY" -ne 1 ]; then
    err "SonarQube belum siap setelah 6 menit. Cek: docker compose -f compose.sonar.yaml logs sonarqube"; exit 1
fi
ok "SonarQube siap di $HOST_URL"

# --- 4. Password admin & token
step "Menyiapkan akun & token..."
TOKEN=''
if [ -f "$TOKEN_FILE" ]; then
    SAVED="$(tr -d '[:space:]' < "$TOKEN_FILE")"
    if [ -n "$SAVED" ] && curl -fs -u "$SAVED:" "$HOST_URL/api/authentication/validate" 2>/dev/null | grep -q '"valid":true'; then
        TOKEN="$SAVED"
    fi
fi
if [ -z "$TOKEN" ]; then
    # Ganti password default admin/admin; kalau sudah pernah diganti, error ini diabaikan
    curl -s -u admin:admin -X POST "$HOST_URL/api/users/change_password" \
        --data-urlencode "login=admin" --data-urlencode "previousPassword=admin" \
        --data-urlencode "password=$ADMIN_PASS" >/dev/null 2>&1 || true
    RES="$(curl -fs -u "admin:$ADMIN_PASS" -X POST "$HOST_URL/api/user_tokens/generate" \
        --data-urlencode "name=sonar-sh-$(date +%Y%m%d%H%M%S)" 2>/dev/null)"
    TOKEN="$(printf '%s' "$RES" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"
    if [ -z "$TOKEN" ]; then
        err "Gagal membuat token. Kalau password admin sudah diubah manual, hapus volume: docker compose -f compose.sonar.yaml down -v"
        exit 1
    fi
    printf '%s' "$TOKEN" > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE" 2>/dev/null || true
fi
ok "Token siap ($TOKEN_FILE)."

# --- 5. Scan
step "Menjalankan scanner & menunggu Quality Gate..."
SONAR_TOKEN="$TOKEN" docker compose "${SONAR[@]}" run --rm scanner
SCAN_EXIT=$?

# --- Ringkasan
METRICS='alert_status,bugs,vulnerabilities,security_hotspots,code_smells,coverage,duplicated_lines_density,ncloc,reliability_rating,security_rating,sqale_rating'
if M="$(curl -fs -u "$TOKEN:" "$HOST_URL/api/measures/component?component=$PROJECT_KEY&metricKeys=$METRICS" 2>/dev/null)"; then
    step "Ringkasan hasil ($PROJECT_KEY)"
    printf '%s' "$M" | grep -o '{"metric":"[^"]*","value":"[^"]*"' \
        | sed 's/{"metric":"\([^"]*\)","value":"\([^"]*\)"/\1 \2/' \
        | while read -r k v; do printf '%-26s %s\n' "$k" "$v"; done
else
    err "Tidak bisa mengambil ringkasan metrik."
fi

DASH="$HOST_URL/dashboard?id=$PROJECT_KEY"
echo
if [ "$SCAN_EXIT" -eq 0 ]; then ok "Quality Gate: PASSED"; else err "Quality Gate gagal / scan error (exit $SCAN_EXIT)"; fi
echo "Dashboard : $DASH"
echo "Login     : admin / $ADMIN_PASS"
if [ "$NO_BROWSER" -eq 0 ]; then
    if command -v open >/dev/null 2>&1; then open "$DASH"
    elif command -v xdg-open >/dev/null 2>&1; then xdg-open "$DASH" >/dev/null 2>&1
    fi
fi

if [ "$SCAN_EXIT" -ne 0 ] || [ "$TESTS_FAILED" -eq 1 ]; then exit 1; fi
exit 0
