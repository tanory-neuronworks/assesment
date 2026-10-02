# Menghapus container, volume, dan image aplikasi dari Docker.
# Cara pakai: .\remove.ps1
# PERINGATAN: data MySQL (volume db_data) ikut terhapus.

# Pindah ke folder script ini, supaya bisa dijalankan dari mana saja
Set-Location -Path $PSScriptRoot

# Hentikan & hapus container, network, dan volume (db_data + volume anonim vendor)
Write-Host "`n=============== Hapus container & volume ===============" -ForegroundColor Cyan
docker compose down --volumes --remove-orphans

# Hapus image aplikasi (image mysql:8.0 dibiarkan karena bisa dipakai proyek lain)
Write-Host "`n=============== Hapus image inventory-app ===============" -ForegroundColor Cyan
docker image rm inventory-app:latest 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "Image inventory-app:latest dihapus"
} else {
    Write-Host "Image inventory-app:latest tidak ditemukan, dilewati"
}

# Tampilkan sisa resource (seharusnya kosong)
Write-Host "`n=============== Sisa resource (harus kosong) ===============" -ForegroundColor Cyan
docker ps -a --filter "name=assesment"
docker volume ls --filter "name=assesment"
docker image ls inventory-app
