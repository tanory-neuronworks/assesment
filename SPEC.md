# SPEC — Inventory & Order Management System

> Ringkasan teknis dari *Final Project Brief — Intermediate Programmer* (PT Neuronworks Indonesia, Edisi 1.0, Okt 2026). Dokumen ini adalah acuan kerja; brief PDF asli tetap sumber kebenaran resmi bila ada perbedaan.

## 0. Ringkasan

| | |
|---|---|
| **Model pengerjaan** | Individual — source code, repo, evidence dibuat sendiri |
| **Produk** | Web app Inventory & Order Management, 3 role, multi-gudang |
| **Stack wajib** | HTML/CSS/Vanilla JS + Fetch API, PHP 8.2+ Native OOP berlapis, MySQL 8, Docker Compose, PHPUnit, static analysis |
| **Alur inti** | Login → Master Data → Purchase Order → Sales Order → Stock Ledger → Dashboard/Laporan → Logout |
| **Nilai lulus** | Minimal 80, tanpa critical failure |

### Lima ketentuan teknis yang tidak dapat diganti
1. Backend PHP Native (no framework), OOP dengan Controller/Service/Repository, Dependency Inversion pada boundary repository.
2. Frontend Vanilla JS (no JS framework) + minimal 1 endpoint JSON API.
3. App & DB wajib jalan via Docker Compose.
4. Wajib ada unit test **dan** integration test sesuai standar minimum.
5. Wajib ada class diagram, ADR, dan refactoring log sebagai bukti.

> **Peringatan over-engineering:** kedalaman desain intermediate ≠ kerumitan tak perlu. Pattern yang dipasang tanpa dipahami atau layer tambahan tanpa alasan nyata dinilai negatif, sama seperti kode berantakan.

---

## 1. Domain & Alur Bisnis

**Tujuan:** membuktikan kemampuan merancang arsitektur berlapis yang testable, menjaga integritas data pada operasi konkuren, menerapkan Clean Code/Architecture secara nyata, dan mengomunikasikan keputusan desain.

### 1.1 Alur utama
1. Admin login → kelola user (Sales/Warehouse Staff) & master data (produk, kategori, gudang, supplier, customer).
2. Sales login → buat Sales Order (Draft) dari katalog & stok tersedia → ajukan → `PendingApproval`.
3. Admin approve/reject SO. **Sales tidak bisa approve order miliknya sendiri.**
4. Warehouse Staff proses goods issue untuk SO `Approved` → kurangi stok (race-condition safe) → SO jadi `Fulfilled`.
5. Saat stok rendah, Admin/Warehouse Staff buat Purchase Order ke supplier; saat barang datang, Warehouse Staff catat goods receipt → tambah stok.
6. Setiap pergerakan stok tercatat di Stock Ledger; dashboard & laporan dihitung dari data ini (bukan angka statis).
7. Logout → halaman terlindungi tidak bisa diakses tanpa login ulang.

### 1.2 Role & Segregation of Duties

| Aktivitas | Admin | Sales | Warehouse Staff |
|---|---|---|---|
| Login, logout, profil sendiri | ✅ | ✅ | ✅ |
| Kelola user | ✅ | ❌ | ❌ |
| Kelola master data | ✅ | Hanya lihat katalog | Hanya lihat produk & stok |
| Buat & ajukan Sales Order | ✅ | ✅ (milik sendiri) | ❌ |
| Approve/reject Sales Order | ✅ | ❌ (termasuk order sendiri) | ❌ |
| Buat Purchase Order | ✅ | ❌ | Boleh mengusulkan |
| Goods receipt (PO) | ✅ | ❌ | ✅ |
| Goods issue (SO) | ✅ | ❌ | ✅ |
| Dashboard | Seluruh data | Ringkasan order miliknya | Ringkasan stok & fulfillment |
| Laporan CSV | ✅ | Order miliknya | Laporan stok |

> **Keputusan proses:** segregation of duties wajib ditegakkan di **authorization layer server**, bukan hanya disembunyikan di UI. Endpoint approve tetap ada di aplikasi, tapi ditolak untuk Sales.

### 1.3 Entities & Nilai Tetap

| Entity | Field minimum | Nilai tetap / constraint |
|---|---|---|
| User | nama, email, password, role, status aktif, timestamps | role: `Admin`/`Sales`/`WarehouseStaff` |
| Warehouse | nama, lokasi, status aktif | - |
| Category | nama, deskripsi | - |
| Product | SKU (unik), nama, kategori, unit, harga beli, harga jual, reorder point, gambar (opsional), status aktif | - |
| ProductStock | produk, gudang, quantity, updated_at | `quantity >= 0` |
| Supplier / Customer | nama, kontak, alamat, status aktif | - |
| PurchaseOrder + Item | supplier, gudang tujuan, status, tanggal order, item (produk, qty, harga beli) | status: `Draft`/`Ordered`/`PartiallyReceived`/`Received`/`Cancelled` |
| SalesOrder + Item | customer, dibuat oleh, disetujui oleh, gudang asal, status, item (produk, qty, harga jual) | status: `Draft`/`PendingApproval`/`Approved`/`Fulfilled`/`Cancelled` |
| StockLedger | produk, gudang, tipe pergerakan, quantity, referensi (PO/SO id), dilakukan oleh, timestamp | tipe: `Receipt`/`Issue`/`Adjustment` |

**Alur status SO:** `Draft → PendingApproval → Approved → Fulfilled` (atau `Cancelled` di tahap manapun sebelum Fulfilled).

**Keputusan data:**
- Produk/supplier/customer **dinonaktifkan**, bukan dihapus permanen.
- Stok **tidak pernah** diubah langsung dari UI — hanya lewat service yang menulis baris `StockLedger` lalu update `ProductStock` **dalam satu transaksi**. Ini menjaga riwayat & traceability.

---

## 2. Requirement Wajib

> **Urutan pembangunan:** vertical slice dulu — login → master data → purchase order → sales order → dashboard/laporan. API, upload gambar, scheduled job ditambah setelah alur transaksi inti stabil.

### 2.1 Autentikasi & User
- **AUTH-01 Login & Session** — login by email/password → dashboard sesuai role; kredensial salah → pesan generik aman; user nonaktif tidak bisa login; halaman terlindungi butuh session; session ID di-regenerate setelah login; password via `password_hash()`/`password_verify()`.
- **AUTH-02 Logout** — hapus data auth di session; URL terlindungi tidak bisa dibuka lagi tanpa login.
- **USR-01 Manajemen User (3 role)** — Admin CRUD-terbatas (add/view/edit/aktif-nonaktif) user; email unik; role terbatas 3 pilihan; no public registration; Sales/Warehouse tidak bisa akses halaman/endpoint admin user.

### 2.2 Master Data
- **PRD-01 Produk, Kategori & Reorder Point** — SKU unik; validasi kategori/harga/unit/reorder point (>= 0); produk terpakai hanya bisa dinonaktifkan; upload gambar opsional dengan validasi tipe/ukuran, nama file acak.
- **WH-01 Gudang & Stok Multi-Lokasi** — Admin kelola gudang; tiap produk punya baris stok per gudang; tampilan stok total + rincian per gudang.

### 2.3 Purchase Order & Penerimaan Barang
- **PO-01** — PO: supplier, gudang tujuan, item (produk/qty/harga beli); status `Draft→Ordered→PartiallyReceived/Received→Cancelled`; goods receipt menambah `ProductStock` + tulis `StockLedger` (`Receipt`) dalam satu transaksi (lihat ARCH-02); partial receipt diperbolehkan, sisa qty tetap tercatat.

### 2.4 Sales Order & Pengeluaran Stok
- **SO-01** — alur status sesuai §1.3; approve diperiksa di server (Sales tidak bisa approve, termasuk order sendiri); goods issue hanya untuk SO `Approved`, ditolak jika stok tidak cukup; goods issue kurangi `ProductStock` + tulis `StockLedger` (`Issue`) dalam transaksi race-condition-safe.

### 2.5 Daftar, Pencarian, Dashboard & Laporan
- **VIEW-01** — Produk/PO/SO tampil list + detail sesuai role; empty state informatif.
- **FIND-01** — Produk: cari nama/SKU, filter kategori & status stok (low/normal). Order: cari nomor/pihak terkait, filter status, sort tanggal. Pagination 10/halaman, filter tetap aktif antar halaman. Seed: minimal 30 produk & 25 order gabungan.
- **DASH-01** — Admin: nilai inventori, produk di bawah reorder point, order pending per status. Sales: ringkasan order miliknya per status. Warehouse: antrean goods receipt/issue & produk low-stock. Semua dari query agregasi.
- **REPORT-01** — Ekspor CSV pergerakan stok (StockLedger) & status order per rentang tanggal, dari query yang sama dengan dashboard.

### 2.6 API
- **API-01** — Minimal 1 endpoint JSON terpisah dari halaman HTML. Contoh: `GET /api/products/{sku}/availability` → stok per gudang. Auth diperiksa sama seperti halaman biasa; `Content-Type: application/json`; status code tepat (200/401/404).

### 2.7 Validation, Error Handling, UI & Database
- **VAL-01** — Field wajib/enum/tanggal/FK/angka (>=0) divalidasi frontend **dan** backend (backend = source of truth); data tidak tersimpan jika invalid; input dipertahankan jika relevan.
- **ERR-01** — no-login → redirect login; no-permission → 403; not-found → 404; exception/stack trace tidak ditampilkan ke user.
- **UI-01** — Responsive di 360px & desktop; navigasi/tabel tidak terpotong; form punya label, focus state, kontras dasar.
- **DB-01** — Tabel sesuai §1.3 dengan PK/FK/constraint (`quantity >= 0`)/index relevan; semua query PDO prepared statement; operasi multi-tabel (goods receipt/issue) dalam transaksi eksplisit; schema+seed bisa build dari kosong (termasuk data untuk FIND-01).
- **JOB-01** — Script mandiri (`php scripts/check-low-stock.php`) hasilkan ringkasan produk di bawah reorder point; jalan manual via `docker compose exec`; tidak wajib auto-scheduled.

---

## 3. Arsitektur & Kualitas Desain

> Inti penilaian level Intermediate — bukan sekadar nama pattern di README, tapi bukti pemahaman *mengapa* keputusan desain diambil.

### 3.1 Layered Architecture & Dependency Inversion
- **ARCH-01** — 3 layer: Controller (HTTP/routing) → Service (business rule) → Repository (akses data); dependency arah Controller→Repository. Minimal 1 interface Repository dengan 2 implementasi (MySQL asli + in-memory/fake untuk unit test). Service pakai constructor injection, no `new PDO()` tersembunyi. Business logic test-able tanpa koneksi DB nyata.
- **ARCH-02** — Transaksi & concurrency-safe stock ops. `ProductStock` update + `StockLedger` write dalam satu transaksi (`beginTransaction`/`commit`/`rollBack`). Dua goods issue bersamaan pada produk+gudang sama tidak boleh oversell / saling menimpa. Mekanisme (locking/optimistic/dsb.) adalah keputusan desain peserta — jelaskan & buktikan dengan test/skenario terkontrol (simulasi thread sungguhan tidak wajib).

### 3.2 Class Diagram, ADR & Refactoring Log
- **DESIGN-01** — Class diagram *initial* (`docs/planning/`, sebelum coding) & *as-built* (`docs/architecture/`, akhir), dengan penanda dependency ke interface vs kelas konkret + 2-3 kalimat perubahan & alasannya. Tool bebas (draw.io/PlantUML/Mermaid/sketsa scan).
- **DESIGN-02** — 2-3 ADR singkat (context/decision/consequences) di `docs/architecture/adr-*.md`.
- **DESIGN-03** — Refactoring log (≥3 entri: smell, teknik, before/after), 1 audit SRP, tech-debt register jujur, minimal 1 commit `refactor:` yang perbaiki kode lama. Files: `docs/quality/refactor-log.md`, `docs/quality/tech-debt.md`.
- **DESIGN-04** — Critique exercise: analisis tertulis cuplikan kode bermasalah dari assessor (smell, prinsip SOLID dilanggar, arah refactor). Implementasi tidak wajib. `docs/quality/critique.md`.

### 3.3 Testing sebagai Bagian dari Desain
- **TEST-01 Unit Test** — Minimal 6 test case di ≥3 area logic (mis. validasi tanggal PO, transisi status SO, perhitungan low-stock, ownership/authorization approve); tanpa session/PDO nyata/layanan eksternal; getter/setter trivial tidak dihitung.
- **TEST-02 Integration Test** — Minimal 3 test menyentuh MySQL nyata di Docker (mis. goods receipt benar-benar nambah stok end-to-end, goods issue kedua ditolak saat stok habis).
- **TEST-03 Static Analysis & FIRST** — PHPStan (level 5+) atau PHPCS (PSR-12), nol critical error; test ikuti FIRST (no `sleep()`, no network call nyata, no ketergantungan urutan).

---

## 4. Ketentuan Teknis

| Area | Wajib | Diperbolehkan | Tidak diperbolehkan |
|---|---|---|---|
| Frontend | HTML semantik, CSS buatan sendiri, Vanilla JS | Fetch API, library icon | React/Vue/Angular/jQuery, framework CSS, template admin siap pakai |
| Backend | PHP 8.2+ Native, OOP berlapis, DIP pada boundary repository | Composer (autoload & dev dependency) | Laravel/CodeIgniter/Symfony/Slim, ORM, generator CRUD, DI container framework |
| Database | MySQL 8, relasi, constraint, index, PDO prepared statement, transaksi eksplisit | Migration/seed buatan sendiri | NoSQL sebagai storage utama, query concatenation input user |
| Environment | Dockerfile, Docker Compose, `.env.example` | Apache/Nginx sesuai rancangan | Setup yang hanya jalan di komputer peserta |
| Testing | PHPUnit unit+integration, static analysis report | Test tambahan lain | Test trivial getter/setter, test yang lulus karena di-skip |
| Desain | Class diagram initial & as-built, ADR, refactoring log | Tool diagram apapun yang legible | Diagram tidak sesuai kode aktual |

### 4.1 Struktur Aplikasi (nama folder boleh beda, tanggung jawab wajib terpisah)
```
public/                  # entry point & static asset
app/Controller/
app/Service/
app/Repository/
app/Entity/
views/                   # template UI
config/                  # loader environment
database/                # schema, seed
tests/Unit/
tests/Integration/
docs/planning/
docs/architecture/
docs/quality/
docs/testing/
```

### 4.2 Security Minimum
- Password hashing via PHP password API; authorization selalu diperiksa server-side (termasuk segregation of duties §1.2).
- Semua query berinput → prepared statement; output user di-escape sebelum render HTML.
- Session dikelola aman, ID di-regenerate setelah login; secret/credential tidak masuk repo.
- ARCH-02: operasi stok dirancang aman dari race condition.

### 4.3 Di Luar Scope
Microservices, message queue sungguhan, cloud deployment, CI/CD, Kubernetes, real-time notification, mobile app, cron scheduler otomatis, automated end-to-end test.

### 4.4 Fitur Bonus (dinilai setelah requirement wajib stabil, tidak menutup kekurangan wajib)
Notifikasi email simulasi (mis. Mailhog), audit trail perubahan master data, dashboard grafik (SVG/canvas buatan sendiri), integration test tambahan.

---

## 5. Docker & Testing

- Minimal service `app`/`web` + `mysql`; build bersih via `docker compose up --build`.
- Konfigurasi via env var + `.env.example`; schema/seed dijalankan sesuai README.
- Tidak boleh bergantung absolute path / konfigurasi khusus komputer peserta.
- **Sebelum submit:** clone ke folder bersih → jalankan perintah README → pastikan login, alur PO/SO, unit+integration test semua berfungsi.
- **Demo/defense:** assessor bisa minta jalankan Docker, jalankan test, telusuri class diagram→kode, demo skenario ARCH-02, bahas 1 ADR, atau safe-refactor demo (ubah logic kecil, test tetap hijau).

---

## 6. Proses Kerja, Git & Penggunaan AI

### 6.1 Git & Integritas Proses
- Repo individual; commit bertahap menggambarkan perubahan nyata, termasuk ≥1 commit `refactor:` (DESIGN-03).
- Jangan commit `.env`, credential aktif, token, data client/PII.
- Cantumkan sumber snippet/package/asset yang bukan buatan sendiri.

### 6.2 Penggunaan AI — 4 kewajiban: **DISCLOSE, REVIEW, VERIFY, TEST**
- Peserta tetap bertanggung jawab penuh — harus bisa jelaskan setiap keputusan arsitektur tanpa bantuan AI saat defense.
- Catat tool, tujuan, ringkasan prompt (disanitasi), output dipakai/ditolak, bukti verifikasi → `ai-usage-log.md`.
- Jangan kirim source proprietary/data client/credential ke layanan AI publik.

---

## 7. Submission Package

| Artefak | Isi minimum |
|---|---|
| Source code | Kode final, `composer.json`/lock, `.gitignore`, dependency declaration |
| Docker | Dockerfile, `compose.yaml`, `.env.example` |
| `README.md` | Fitur, requirement, instalasi, akun demo, perintah Docker/test, known limitations |
| `database/` | `schema-and-seed.sql` atau migration/seed setara |
| `tests/Unit`, `tests/Integration` | Unit & integration test sesuai standar minimum |
| `docs/planning/` | User story, scope, ERD, class diagram initial, backlog |
| `docs/architecture/` | Class diagram as-built, ADR |
| `docs/quality/` | Refactoring log, audit SRP, tech-debt register, `critique.md`, laporan static analysis |
| `docs/testing/` | Test scenario, hasil test, screenshot relevan, known bugs |
| `ai-usage-log.md` | Deklarasi penggunaan AI (termasuk jika tidak pakai AI) |
| Release final | Tag/release resmi; commit setelah freeze tidak dinilai kecuali diminta |

### 7.1 Data Demo Minimum
- 1 akun Admin, ≥2 akun Sales, ≥2 akun Warehouse Staff.
- ≥2 gudang, 30 produk (variasi reorder point, beberapa di bawah reorder point).
- ≥25 order gabungan (PO+SO), variasi status termasuk `PendingApproval` & `Cancelled`.

---

## 8. Demo, Penilaian & Critical Failure

### 8.1 Demo & Technical Defense

| Bagian | Durasi | Yang ditunjukkan |
|---|---|---|
| Demo produk | 12-15 menit | Docker start, login 3 role, alur PO→SO→ledger, validation/failure, find/pagination, dashboard, responsive |
| Engineering evidence | 5-7 menit | Class diagram, ADR, unit+integration test, static analysis, refactor log |
| Technical defense | 10-12 menit | Critique exercise, penjelasan ARCH-02, tracing diagram→kode, safe-refactor demo |

### 8.2 Critical Failure (otomatis gagal jika terjadi salah satu)
- App/DB tidak bisa jalan via Docker setelah setup wajar.
- Alur inti login→PO/SO→stok tidak berfungsi, atau fitur hanya tampilan tanpa proses/data nyata.
- Pakai framework backend/frontend/ORM/DI container yang dilarang.
- Tidak ada unit+integration test valid, atau semua test gagal di release final.
- Password plaintext, secret aktif di repo, query concatenation input user, atau authorization hanya di frontend.
- Stok diubah langsung tanpa service/ledger → StockLedger tidak konsisten dengan ProductStock.
- Goods issue/receipt tidak transaksional → oversell bisa direproduksi assessor.
- Class diagram tidak mencerminkan kode aktual / tidak bisa ditelusuri saat defense.
- Peserta tidak bisa jelaskan arsitektur/keputusan desain sendiri, atau evidence menunjukkan plagiarisme.
- Penggunaan AI/sumber eksternal material disembunyikan.

**Status lulus:** nilai ≥80 **dan** tanpa critical failure.

---

## 9. Checklist Sebelum Submission

- [ ] Seluruh requirement wajib (§2) teruji di release/tag final
- [ ] App & DB jalan via Docker dari folder bersih
- [ ] Unit + integration test jalan 1 perintah, semua lulus
- [ ] Laporan static analysis terlampir, nol critical error
- [ ] Class diagram initial & as-built tersedia, sesuai kode aktual
- [ ] Goods issue/receipt terbukti transaksional; oversell tidak bisa direproduksi
- [ ] Segregation of duties (Sales tidak approve order sendiri) teruji di server
- [ ] Search, filter, sort, pagination, dashboard 3 role, endpoint JSON bisa didemonstrasikan
- [ ] Tidak ada secret/credential/data client/PII di repo & history
- [ ] README teruji dari environment bersih; akun demo 3 role tersedia
- [ ] Bisa jelaskan data flow, layered architecture, 1 ADR, 1 refactor tanpa bantuan AI
- [ ] Release/tag final dibuat, link submission benar

---

## 10. FAQ Singkat

- **ORM (mis. Eloquent standalone)?** Tidak boleh — PDO native wajib.
- **DI container (PHP-DI/Symfony DI)?** Tidak wajib — constructor injection manual cukup.
- **Tool UML berbayar untuk class diagram?** Tidak wajib — draw.io/PlantUML/Mermaid/sketsa scan diterima.
- **Clean Architecture 4 ring penuh?** Tidak — 3 layer pragmatis (Controller/Service/Repository) + interface di boundary repository sudah cukup.
- **Critique exercise wajib diimplementasi?** Tidak — analisis tertulis saja cukup.
- **Jumlah test minimum?** ≥6 unit test (≥3 area) + ≥3 integration test (MySQL nyata di Docker).
- **Static analysis wajib nol error total?** Wajib nol *critical* error; warning boleh ada jika dijelaskan.
- **Race condition wajib diuji paralel sungguhan?** Tidak wajib — skenario terkontrol yang bisa diverifikasi cukup.
- **JOB-01 wajib auto-scheduled di server?** Tidak — manual via Docker cukup.
- **Bonus bisa menutup requirement wajib yang belum selesai?** Tidak.
- **Perlu halaman register?** Tidak — semua akun dibuat Admin.
- **Requirement ambigu?** Tanyakan trainer sebelum ubah scope; simpan jawaban di `docs/planning/`.

---

*Sumber: Neuronworks / Learning Series — Intermediate Programmer Final Project Brief, Participant Guide Edisi 1.0.*
