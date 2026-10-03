# Inventory & Order Management System

## **🚀 QUICK START**

Butuh **Docker Desktop** saja. Pilih sesuai OS:

### **🪟 Windows (PowerShell)**

**▶ Jalankan aplikasi**

```powershell
.\run.ps1
```

**▶ Jalankan aplikasi + SonarQube**

```powershell
.\run.ps1 -Sonar
```

**▶ Analisis SonarQube saja (tanpa menjalankan app)**

```powershell
.\sonar.ps1
```

Opsi `sonar.ps1`: `-SkipTests`, `-NoBrowser`, `-Down`.

### **🍎 macOS / 🐧 Linux (bash)**

**▶ Jalankan aplikasi**

```bash
./run.sh
```

**▶ Jalankan aplikasi + SonarQube**

```bash
./run.sh --sonar
```

**▶ Analisis SonarQube saja (tanpa menjalankan app)**

```bash
./sonar.sh
```

Opsi `sonar.sh`: `--skip-tests`, `--no-browser`, `--down`. Kalau muncul `permission denied`, jalankan sekali: `chmod +x run.sh sonar.sh`.

**Aplikasi: http://localhost:8080** · **Dashboard SonarQube: http://localhost:9100**

Web app manajemen inventori & order multi-gudang, 3 role (Admin, Sales, Warehouse Staff). PHP 8.2+ native OOP berlapis (Controller → Service → Repository), MySQL 8, Vanilla JS, Docker Compose.

> **Status:** Slice 1-4 — seluruh requirement wajib di SPEC.md §2 sudah diimplementasikan (Auth, Master Data, PO/Goods Receipt, SO/Approval/Goods Issue, Dashboard, Laporan CSV, Search/Filter/Pagination, API, Job). Yang tersisa adalah dokumentasi desain (class diagram, ADR, dll) dan submission package — lihat "Known limitations" di bawah.

## Fitur

**Slice 1 — Fondasi, Auth, Master Data**
- Login/logout berbasis session, password di-hash (`password_hash`), session ID regenerate setelah login, user nonaktif langsung kehilangan akses (AUTH-01, AUTH-02).
- Manajemen user 3 role oleh Admin — tanpa halaman registrasi publik (USR-01).
- Master data: kategori, gudang, produk (SKU unik, reorder point, upload gambar opsional), supplier, customer — data terpakai dinonaktifkan, bukan dihapus (PRD-01, WH-01).
- Stok per gudang (tampilan total + rincian per gudang di halaman detail produk).
- Validasi & otorisasi ditegakkan di server (bukan hanya UI); segregation of duties: hanya Admin yang bisa mengelola user & menyetujui hal-hal sensitif.
- Responsive layout (360px & desktop), tanpa framework CSS/JS.

**Slice 2 — Purchase Order & Goods Receipt**
- Admin/Warehouse Staff membuat PO (supplier, gudang tujuan, item baris produk) — Sales tidak punya akses sama sekali ke PO (BR-06), ditegakkan di server.
- Goods receipt (penuh/sebagian) menambah `product_stocks` **dan** menulis `stock_ledger` dalam satu transaksi DB eksplisit (`beginTransaction`/`commit`/`rollBack`) — implementasi ARCH-02.
- Stock increment pakai atomic `INSERT ... ON DUPLICATE KEY UPDATE quantity = quantity + ?` dan update `qty_received` pakai atomic conditional `UPDATE ... WHERE qty_received + ? <= qty_ordered`, jadi race/over-receipt ditolak di level DB, bukan check-then-write di aplikasi.
- Riwayat pergerakan stok (`StockLedger`) ditampilkan di halaman detail produk untuk traceability (BR-08).
- Cancel PO (hanya selagi belum diterima penuh).

**Slice 3 — Sales Order, Approval & Goods Issue**
- Sales/Admin membuat SO sebagai Draft (customer, gudang asal, item baris produk), lalu "Ajukan" → `PendingApproval`.
- Approve/reject hanya oleh Admin, **dan** pembuat SO tidak pernah bisa menyetujui/menolak order miliknya sendiri — ditegakkan di service layer, berlaku untuk siapa pun termasuk Admin (BR-04), bukan sekadar role check.
- Goods issue (Admin/Warehouse Staff) mengurangi stok memakai atomic conditional `UPDATE ... WHERE quantity >= ?` — inilah mekanisme anti-oversell (ARCH-02/BR-09): pengecekan stok *adalah* statement UPDATE itu sendiri, bukan `SELECT` terpisah lalu `UPDATE`.
- Goods issue bersifat all-or-nothing per SO: kalau satu baris item saja stoknya kurang, seluruh transaksi di-rollback, tidak ada baris lain yang telanjur berkurang.
- Sales hanya melihat SO miliknya sendiri; Admin melihat semua; Warehouse Staff melihat semua secara read-only (untuk menemukan SO `Approved` yang perlu diproses).

**Slice 4 — Dashboard, Laporan, Search/Filter/Pagination, API, Job**
- Dashboard per role dari query agregasi real-time (bukan angka statis): Admin (nilai inventori, produk low-stock, PO/SO per status), Sales (order miliknya per status), Warehouse (antrean goods receipt/issue, produk low-stock).
- Ekspor CSV: riwayat pergerakan stok (Admin/Warehouse) dan status order (Admin: PO+SO, Sales: SO miliknya) untuk rentang tanggal tertentu.
- Search/filter/pagination (10/halaman) di listing Produk (nama/SKU, kategori, status stok low/normal) dan Purchase/Sales Order (nomor/pihak terkait, status) — filter tetap aktif antar halaman.
- Endpoint JSON `GET /api/products/{sku}/availability` — auth session sama seperti halaman biasa, tapi kegagalan mengembalikan JSON (401/404), bukan redirect/halaman HTML.
- Script mandiri `scripts/check-low-stock.php` — dijalankan manual via `docker compose exec app php scripts/check-low-stock.php`.

## Requirement

- Docker Desktop / Docker Engine + Docker Compose v2.
- Tidak perlu PHP/MySQL terinstal di komputer — semua jalan di dalam container.

## Menjalankan aplikasi

```bash
cp .env.example .env
# edit .env bila perlu (password default hanya untuk lokal)

docker compose up --build
```

Aplikasi tersedia di http://localhost:8080. Schema + seed data otomatis dijalankan saat container `mysql` pertama kali dibuat (`database/schema-and-seed.sql`).

Untuk reset database dari kondisi bersih:

```bash
docker compose down -v
docker compose up --build
```

## Akun demo

Login menerima email atau username. Semua akun demo memakai password yang sama: `12345678`.

| Role | Email | Username | Password |
|---|---|---|---|
| Admin | admin@neuronworks.test | admin | 12345678 |
| Sales | sales1@neuronworks.test | sales1 | 12345678 |
| Sales | sales2@neuronworks.test | sales2 | 12345678 |
| Warehouse Staff | gudang1@neuronworks.test | gudang1 | 12345678 |
| Warehouse Staff | gudang2@neuronworks.test | gudang2 | 12345678 |

## Menjalankan test

```bash
docker compose exec app vendor/bin/phpunit --testsuite Unit
docker compose exec app vendor/bin/phpunit --testsuite Integration
docker compose exec app vendor/bin/phpunit
```

Unit test memakai repository in-memory (tanpa koneksi DB/session nyata). Integration test menyentuh MySQL nyata di container `mysql`, dibungkus transaksi yang di-rollback tiap test agar tidak mengubah seed data.

## Static analysis

```bash
docker compose exec app composer stan
```

Menjalankan PHPStan level 5 terhadap `app/`.

## Struktur proyek

```
public/            Entry point (index.php), asset CSS/JS, folder upload produk
app/Core/          Router, Request/Response, Session, Auth, Csrf, Database, View
app/Controller/    HTTP layer
app/Service/       Business rule & validasi
app/Repository/    Contracts (interface) + implementasi Mysql (PDO) & InMemory (fake untuk unit test)
app/Entity/        Data class domain
views/             Template PHP (layout + halaman)
config/            Loader environment & wiring dependency (bootstrap.php)
database/          schema-and-seed.sql
tests/Unit/        Unit test (repository in-memory)
tests/Integration/ Integration test (MySQL nyata)
docker/            Konfigurasi Apache vhost untuk image PHP
```

## Known limitations

- Sort (urutan) di listing Order baru default (tanggal terbaru dulu) — belum ada kontrol UI untuk mengubah arah sort. Search/filter/pagination sudah lengkap.
- Race-condition pada goods issue diverifikasi lewat skenario terkontrol (dua SO berurutan berebut stok terbatas) di `tests/Integration/GoodsIssueIntegrationTest.php`, bukan simulasi thread/koneksi paralel sungguhan — sesuai SPEC §10 FAQ ("simulasi thread sungguhan tidak wajib"). Mekanisme keamanannya sendiri (atomic conditional `UPDATE`) tetap efektif di real concurrency karena bergantung pada row-locking InnoDB, bukan pada urutan pemanggilan di test.
- Seed data belum mencapai kuota minimum submission (30 produk, ≥25 order gabungan per §7.1 SPEC) — seed saat ini (16 produk, 6 PO + 7 SO) cukup untuk demo tiap alur & status, tapi perlu ditambah sebelum submission final.
- Dokumentasi desain (class diagram, ADR, refactor log, tech-debt register, critique), `ai-usage-log.md`, dan `docs/testing/` belum dibuat — sekarang seluruh requirement wajib (§2 SPEC) sudah stabil, ini pekerjaan berikutnya sebelum submission.
