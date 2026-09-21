# Patch Re-review (Gate 3): Bulk Catalog Studio

| | |
|---|---|
| **Tanggal** | 21 September 2026 |
| **Reviewer** | Pi (QA statis — patch re-review terhadap `docs/review/qa-report.md`) |
| **Objek** | Diff sejak commit `7111662` (laporan QA-1): 14 file berubah + `includes/class-bcs-mcp-server.php` (baru) + `tests/test-bcs-mcp-server.php` & `tests/test-bcs-mcp-missing-deps.php` (baru) + `.gitignore` |
| **Metode** | Baca penuh diff + kelas baru · analisis bypass SSRF/host-parse · eksekusi keempat harness · `php -l` seluruh PHP plugin & tests |

---

## VERDICT: **APPROVE**

Seluruh temuan BLOCKER/MAJOR QA-1 diperbaiki dengan benar dan **terbukti oleh test regresi yang menguji skenario aslinya** (bukan sekadar perubahan kode). Tidak ditemukan regresi keamanan baru. Catatan residual di bawah bersifat non-blocking (NIT + item Fase D).

---

## 1. BLOCKER-1 — update existing: status draft & stok nol → **TERVERIFIKASI FIXED**

- `class-bcs-plan-builder.php` — `build_payload()` kini menerima `$for_update`: payload `update_product` **tidak membawa key `status`** sama sekali, dan **tidak membawa `stock_quantity`** bila kolom stok kosong (flag baru `stock_provided` di parser; `stock_provided=false` juga untuk stok non-numerik).
- `class-bcs-csv-parser.php:478-503` — flag `stock_provided` di-set hanya bila kolom stok terisi dan berhasil diparse.
- `class-bcs-catalog-writer.php:apply_scalar_fields` tidak berubah (key absent → field tak tersentuh) — semantik jadi benar.

**Bukti test (bukan klaim):** `R1` pada `tests/test-bcs-integration.php` menyiapkan produk existing **publish** + stok 7 (produk #555) dan publish + stok 11 (#556), CSV update dengan stok kosong dan stok `33` eksplisit. Assert: payload baris stok-kosong TANPA `status` & TANPA `stock_quantity`; payload baris eksplisit TANPA `status` + stok 33; setelah execute penuh (approve + execute): status #555 **tetap `publish`**, stok #555 **tetap 7**, nama berubah, stok #556 jadi 33, status #556 tetap publish. ✅

## 2. MAJOR-1 SSRF — **TERVERIFIKASI FIXED** (analisis bypass disertakan)

`class-bcs-abilities.php:373-420, 510-640`:
- Host dikunci **exact-match** ke `docs.google.com` (`is_host_allowed`, filter `bcs_allowed_source_hosts` untuk ekspansi sadar-risiko). Exact match menutup trik suffix (`docs.google.com.evil.com`), prefix (`evil-docs.google.com` — yang di kode lama masih lolos via `strpos`), dan userinfo (`https://docs.google.com@evil.com/` → host ter-parse `evil.com` → ditolak).
- `https` dipertahankan; wajib `output=csv`/`format=csv` kini berlaku untuk **semua** URL (karena hanya satu host yang diizinkan).
- `resolve_host_ip()`: literal IP divalidasi langsung; hostname di-resolve (`gethostbyname`) dan ditolak bila gagal atau privat. `is_ip_private()`: IPv4 CIDR presisi (0/8, 10/8, 100.64/10 CGNAT, 127/8, 169.254/16 metadata, 172.16/12, 192.168/16) + IPv6 (`::1`, `fc00::/7`, `fe80::/10`); IP tidak-valid diperlakukan berbahaya.
- **`'redirection' => 0`** — jalur redirect-ke-internal ditutup.

**Bypass yang dicari dan hasilnya:**
- *Parsing host*: exact match + `esc_url_raw`+`wp_http_validate_url` sebelum `wp_parse_url` — tidak ditemukan bypass; IPv6 literal `[fd00::1]` pun ditolak di layer whitelist (bukan docs.google.com).
- *DNS rebinding*: memang ada TOCTOU teoretis (cek `gethostbyname` lalu `wp_remote_get` resolve ulang) dan `gethostbyname` hanya memeriksa A record (bukan AAAA) — **namun tidak tereksploitasi di V1** karena host dikunci exact `docs.google.com` yang tidak terkontrol attacker; risiko hanya muncul bila admin memperluas via filter, dan README §5.1 sudah menyatakan "dengan risiko sendiri". **Catatan non-blocking**: saat memperluas whitelist via filter, tambahkan pemeriksaan AAAA/re-resolve.
- **Bukti test**: `R4` — whitelist (google lolos, evil.com ditolak), 8 kasus IP privat/publik IPv4+IPv6, url evil.com / 127.0.0.1:9200 / http-non-https ditolak dengan kode error benar, happy path `docs.google.com` export=csv ter-parse, tanpa export=csv ditolak. ✅

## 3. MAJOR-2 konsumsi token atomik — **TERVERIFIKASI FIXED**

- `class-bcs-approval-token.php:consume()` — `UPDATE … WHERE id = %d AND token_used_at IS NULL` dengan `affected_rows === 1` wajib (prepare penuh).
- **Urutan konsisten di dua jalur**: transisi job `planned→approved` (atomik, tepat satu pemenang) **dulu**, baru `consume()`. Ada di `class-bcs-abilities.php:826-846` (MCP) dan `admin/class-bcs-admin.php:602-646` (dashboard).
- **Tidak ada state approved-tanpa-token**: transisi hanya dicapai setelah `verify()` lulus (plan_hash hash_equals + `wp_check_password`), gate baru menolak plan non-`planned` (`class-bcs-abilities.php:782-797`) sehingga plan `superseded` tidak bisa diapprove meski tokennya masih valid; bila `consume()` gagal sesudah transisi (teoretis), `verify()`+gate job+gate plan menutup approve kedua, dan kejadian teraudit.
- **Bukti test**: `R3` — consume kedua ditolak (`bcs_token_used`); dua approve "bersamaan" pada token sama hanya satu yang sukses. `R6b` — approve plan superseded dengan token valid-nya sendiri **ditolak**. ✅

## 4. MAJOR-3 rollback job-scoped — **TERVERIFIKASI FIXED**

- `class-bcs-rollback-service.php:find_created_product_id()` — sumber primer `BCS_Audit_Logger::find_row_product_id( job_id, row_key, 'create_product' )` (query audit **milik job sendiri**, prepared).
- Fallback meta `_bcs_row_key` kini **wajib** cocok `_bcs_job_id = job_id` (meta_query AND) — produk job lain tidak mungkin terpilih.
- **Bukti test**: `R2` — dua job dengan CSV identik (row_key sama by design §5.4): rollback job B men-trash tepat 1 produk milik B, **produk job A aman**. ✅

## 5. Custom MCP server baru — **TERVERIFIKASI SESUAI Riset §3.3**

`includes/class-bcs-mcp-server.php` (baru, 301 baris):
- **Hook**: `add_action( 'mcp_adapter_init', … )` ✓ (riset §3). Callback memakai `$adapter` yang diterima hook, bukan re-instance.
- **`create_server` 13 argumen** cocok persis riset §3.3 + transport-permissions.md: `('bcs-mcp-server', 'bcs-mcp', 'mcp', name, description, version, [HttpTransport::class], ErrorLogMcpErrorHandler::class, NullMcpObservabilityHandler::class, abilities, [], [], permission_callback)`.
- **Tepat 8 ability**: `ability_names()` = `BCS_Schemas::ability_names()` (satu sumber kebenaran dengan registrar) — `bcs/parse-source` … `bcs/audit-log`.
- **Endpoint**: namespace `bcs-mcp` + route `mcp` → `/wp-json/bcs-mcp/mcp`; tidak menyentuh route default yang ter-intercept Novamira.
- **Permission transport parameter ke-13**: closure `current_user_can( 'manage_woocommerce' )` (lebih ketat dari transport default `is_user_logged_in()`; re-check per ability tetap berlaku di layer ability).
- **Guard dependency nonfatal**: `dependencies_met()` cek 4 class (`\WP\MCP\Core\McpAdapter`, transport, error, observability); `abilities_registered()` cek `wp_has_ability` untuk 8 ability; bila gagal → registrasi dilewati + `admin_notice` hanya untuk `activate_plugins` — tanpa fatal. `BCS_MCP_Server::init()` aman dipanggil saat adapter tidak aktif (hook tidak pernah menembak).
- **Header**: `bulk-catalog-studio.php` & `readme.txt` → `Requires Plugins: woocommerce, mcp-adapter` — kini **benar dan wajib**, karena plugin mendaftarkan server via `mcp_adapter_init` (slug `mcp-adapter` sesuai riset gotcha #17).
- **PHP 7.4**: tidak ada sintaks 8.x (closure biasa, `::class` konstanta, tanpa match/nullsafe/named-args); lint lulus.
- **Bukti test**: `test-bcs-mcp-server.php` **26 PASS** (endpoint, 8 ability, transport cap parameter ke-13, guard, header) dan `test-bcs-mcp-missing-deps.php` **7 PASS** (dependency hilang: tanpa fatal, notice hanya untuk admin, notice menyebut class yang kurang).

## 6. Harness & lint — **SEMUA HIJAU**

| Harness | Hasil |
|---|---|
| `php -l` 17 file plugin + 4 file test | 0 error |
| `tests/test-bcs-parser.php` | **33 PASS**, 0 FAIL |
| `tests/test-bcs-integration.php` | **134 PASS, 0 FAIL** (72 alur awal + 62 regresi retur QA-1: R1–R10) |
| `tests/test-bcs-mcp-server.php` | **26 PASS**, 0 FAIL |
| `tests/test-bcs-mcp-missing-deps.php` | **7 PASS**, 0 FAIL |

## 7. Spot-check regresi keamanan & jejak Novamira — **BERSIH**

- Semua query baru (`consume`, `find_row_product_id`, `has_row_entry`, update `superseded`) memakai `prepare`/nilai terkuras; tidak ada permukaan input baru; POST admin tetap di belakang cap-check `render()` + `check_admin_referer` per aksi; NIT-6 benar — POST kini diproses sebelum satu pun output (redirect berfungsi, token tampil sekali tetap aman).
- Update `superseded` di `plan-builder` tepat urutannya (sebelum insert plan baru) dan dipasangkan dengan gate plan-`planned` di approve — celah "approve plan lama hasil rebuild" justru ** tertutup** oleh patch ini.
- **Jejak route default**: semua contoh konfigurasi (`WP_API_URL`) kini menunjuk `/wp-json/bcs-mcp/mcp`; penyebutan `mcp-adapter-default-server` yang tersisa hanya narasi penjelasan ("TIDAK dipakai — ter-intercept Novamira"). Tidak ada config contoh yang masih memakai route default. (Dua NIT dokumentasi di bawah.)

---

## Catatan residual (non-blocking, tidak memengaruhi APPROVE)

1. **NIT (dok)** — `readme.txt` bagian *Description* (±baris 10) masih berbunyi "otomatis terekspos sebagai MCP tools oleh MCP Adapter resmi (default server)" — perbarui ke custom server `bcs-mcp/mcp` agar konsisten dengan bagian Installation/Connecting.
2. **NIT (dok)** — `README.md` §1 poin 2 memakai endpoint default-server sebagai indikator "adapter aktif"; karena route itu ter-intercept Novamira, ganti pengecekan prasyarat ke `bcs-mcp/mcp` (atau `wp option`/plugins list).
3. **Residual SSRF (dok)** — saat filter `bcs_allowed_source_hosts` dipakai untuk memperluas whitelist: `gethostbyname` hanya memeriksa A record (tanpa AAAA) dan terdapat jeda TOCTOU terhadap resolve ulang oleh HTTP transport. Tidak tereksploitasi di V1 (host terkunci); cantumkan peringatan ini di doc-comment filter.
4. **Fase D (dari QA-1, tetap terbuka)** — validasi live validator Abilities API terhadap `const` + `type:["string","null"]`; discovery live tools di `/wp-json/bcs-mcp/mcp` (termasuk konfirmasi tools individual muncul); `dbDelta` di MySQL nyata; eksekusi WC live AC-1/2/6.

## Kesesuaian Self-Check (revisi)

`docs/dev/self-check.md` diperbarui jujur: klaim lama "73/73" dikoreksi menjadi "72 PASS sebelum retur", kini "134 PASS/0 FAIL"; §10 memetakan 17 temuan QA-1 → perbaikan + bukti uji; MINOR-3 dan NIT-2 ditangani sebagai dokumentasi deviasi (sesuai rekomendasi QA-1). Verdict tabel §10 cocok dengan hasil verifikasi independen di laporan ini.
