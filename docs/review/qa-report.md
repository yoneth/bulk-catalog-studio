# Laporan QA Statis: Plugin Bulk Catalog Studio (Fase C)

| | |
|---|---|
| **Tanggal** | 21 September 2026 |
| **Reviewer** | Pi (QA statis, Fase C) — REVIEW ONLY, tidak ada kode/VPS yang diubah |
| **Objek** | `plugin/bulk-catalog-studio/` — 16 file PHP, 7.230 baris |
| **Sumber kebenaran** | `docs/spec/bulk-catalog-studio.md` · `docs/spec/gate1-decisions.md` · `docs/research/abilities-mcp-api.md` |
| **Metode** | Baca penuh 16 file + verifikasi silang spec/gate1/riset · `php -l` semua file (PHP CLI lokal 8.5; target kompatibilitas 7.4 dicek via grep pola sintaks 8.x — bersih) · eksekusi harness `tests/test-bcs-parser.php` (33 PASS) dan `tests/test-bcs-integration.php` (72 PASS, tanpa FAIL) · verifikasi perilaku `$wpdb->update()` langsung ke source core WordPress trunk |

---

## VERDICT: **REJECT** (retur terbatas untuk perbaikan)

Arsitektur, keamanan transversal (SQLi/XSS/CSRF/capability), kepatuhan gotchas riset, dan kualitas WordPress sudah **sehat dan rapi**. Namun ada **1 temuan BLOCKER** (bug logika bisnis yang mengubah data komersial live secara diam-diam) dan **3 temuan MAJOR**. Setelah kelima temuan BLOCKER+MAJOR diperbaiki, cukup **patch re-review** pada titik temuan — tidak perlu audit ulang penuh.

**Ringkasan severity:** 1 BLOCKER · 3 MAJOR · 7 MINOR · 8 NIT

---

## 1. Keamanan

### Positif (terverifikasi lulus)
- **SQLi**: seluruh 38 titik `$wpdb` memakai `prepare()` atau `insert/update` berparameter; interpolasi hanya nama tabel dari `$wpdb->prefix` (aman). `find_by_sku` fallback & `find_by_title` memakai `esc_like` + `prepare` (`class-bcs-duplicate-checker.php:110-155`). ✅
- **XSS admin**: semua output di-escape (`esc_html`/`esc_attr`/`esc_url`); konten HTML produk disanitasi `wp_kses_post` di plan-builder (`class-bcs-plan-builder.php:669-673`); `render_before_after` escape internal. ✅
- **CSRF**: kedua form admin memakai `wp_nonce_field` + `check_admin_referer` + cap check (`admin/class-bcs-admin.php:549-556, 583-593`). ✅
- **Capability**: menu + `render()` (`edit_products`), aksi tulis admin (`manage_woocommerce`), dan **re-check di dalam setiap execute_callback** via `server_guard` (`class-bcs-abilities.php:230-268`) sesuai spec §5.5.5. ✅
- **Rate limit**: 60/menit per user, fixed-window (deviasi terdokumentasi §7.6). ✅
- **Path traversal**: tidak ada input file path dari user; attachment dibaca via `get_attached_file()` setelah validasi post_type. ✅
- **`file://` / skema liar**: mode `url` dipagari https-only + `wp_http_validate_url` (`class-bcs-abilities.php:355-366`). ✅ (namun lihat MAJOR-1)

### MAJOR-1 — SSRF pada `bcs/parse-source` mode `url` (host privat + redirect tidak diblokir)
`class-bcs-abilities.php:344-390`. URL https **sembarangan** diterima (bukan hanya Google Sheets), termasuk `https://127.0.0.1:…`, `https://10.x/`, `https://169.254.169.254/…`. `wp_http_validate_url()` tidak memblokir IP privat. Lebih parah: `wp_remote_get()` default mengikuti **redirect hingga 5 hop tanpa re-validasi** — halaman publik bisa 302 ke `http://169.254.169.254/` atau host internal, dan body-nya diparse lalu sebagian (kolom terdeteksi, warnings) dikembalikan ke klien MCP. Ini jalur eksfiltrasi data internal oleh pemegang `edit_products`.
**Fix konkret**: (a) resolve host (atau baca IP hasil koneksi) dan tolak range privat/link-local/loopback (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7); (b) set `'redirection' => 0` (atau maks 2 dengan re-validasi hop); (c) untuk V1 paling murah & selaras gate1 #1: batasi host ke `docs.google.com` saja.

### MAJOR-2 — Konsumsi token approval TIDAK atomik (TOCTOU)
`class-bcs-approval-token.php:136-170` (`consume()` tanpa guard `token_used_at IS NULL`), dipakai berurutan verify→consume di `class-bcs-abilities.php:645-660` dan admin `class-bcs-admin.php:594-621`. Dua approve bersamaan: keduanya lolos `verify()` (belum dipakai), **keduanya berhasil `consume()`** (update tanpa syarat). Kebetulan transisi `planned→approved` yang atomic menolak approve kedua, sehingga end-state aman — tetapi invariant "single-use" ditopang oleh guard lain, bukan oleh token itu sendiri. Selain itu `consume()` dijalankan **sebelum** transisi: bila transisi gagal, token sudah terbakar tanpa approval (harus generate ulang).
**Fix konkret**: jadikan konsumsi atomik — `UPDATE … SET token_used_at=… WHERE id=%d AND token_used_at IS NULL` dan wajibkan `affected_rows === 1`; panggil **setelah** transisi job sukses (atau gabungkan status token + transisi dalam satu transaksi/urutan yang recoverable).

### MINOR-1 — Entropy token tipis
`class-bcs-approval-token.php:53-60`: `BCS-` + 5 karakter dari charset 31 ≈ 28,6 juta kombinasi (±24,6 bit). Offline brute force tidak relevan (disimpan `wp_hash_password`), online dibatasi rate limit + bcrypt, jadi masih bisa diterima — tapi margin kecil bila rate limit dinaikkan admin.
**Fix**: naikkan ke 8 karakter (±39 bit), masih dalam `maxLength 12` schema.

### NIT-1 — Whitelist mime attachment lebih luas dari klaim self-check
`class-bcs-abilities.php:323`: menerima `text/plain` dan `application/vnd.ms-excel` (biner .xls → parse kacau). Self-check §4 mengklaim "mime text/csv". Selaraskan klaim dokumen atau sempitkan whitelist + validasi konten.

---

## 2. Kesesuaian Spec

### Positif (terverifikasi)
- **8 ability** nama/schema/permission persis §1 & gate1 (5×`edit_products`, 3×`manage_woocommerce`); semua input/output schema `additionalProperties:false`; `row_overrides` sesuai catatan implementasi §4 (`class-bcs-schemas.php`). ✅
- **State machine §2** dipaksa: peta transisi persis §2.2, `failed`/`rolled_back` terminal, tidak ada parameter `status` dari input, update atomik `WHERE status = <expected>` (`class-bcs-job-manager.php:226-286`), guard 1 job executing (`begin_execution :296-369`, toleransi stale sesuai spec). ✅
- **Model tabel §3**: kolom + index persis; `dbDelta` mengikuti kaidah (`class-bcs-install.php`). `token_hash VARCHAR(255)` dan `payload_digest=row_key` adalah deviasi terdokumentasi yang wajar. ✅
- **Idempotency §5.4**: `row_key` persis rumus spec (`class-bcs-csv-parser.php:843-848`); resume lewati baris ok via audit (`has_row_success`, executor `:126-131`); verifikasi SKU ulang saat eksekusi (`catalog-writer :216-228, 355-369`). ✅
- **Rollback soft-trash + snapshot before** sesuai gate1 #5 (`rollback-service`, `writer:1020-1150`). ✅ (namun lihat MAJOR-3)
- **AC-12**: batas keras 500 baris (`csv-parser :152-179`), inline 2 MB, attachment 10 MB, strict schema menolak field liar. ✅
- **Gotchas riset**: hook `wp_abilities_api_categories_init` (kategori dulu) + `wp_abilities_api_init` ✓; nama ability lowercase/dash/satu slash ✓; `meta.mcp.public` + `type=tool` + `show_in_rest` ✓; `function_exists` + `wp_has_ability` guard ✓; tidak ada dev notice tanpa guard WP_DEBUG ✓.
- **`Requires Plugins: woocommerce`** — **keputusan reviewer: CUKUP tanpa `mcp-adapter`**. Registrasi ability berada di hook core dan terekspos ke MCP saat request REST, bukan saat load; guard `function_exists('wp_register_ability')` menutup skenario adapter tak aktif tanpa fatal. Menambah `mcp-adapter` justru membuat plugin gagal aktif bila adapter dimatikan sementara. Catat saja di README bahwa MCP exposure butuh adapter aktif.

### MINOR-2 — Tidak ada jalur rebuild plan (planned → parsed tidak ada)
`class-bcs-plan-builder.php:208-220` (build hanya saat `parsed`) + peta transisi (`job-manager :46-60`) tidak punya `planned → parsed`. Workflow §4 menempatkan keputusan manusia SEBELUM build (via `row_overrides`), jadi alur utama aman — tetapi begitu plan terlanjur dibangun dengan `on_duplicate` salah, satu-satunya jalan adalah job baru (re-parse). Tidak fatal, tapi memboroskan siklus review manusia.
**Fix**: izinkan `build-plan` pada job `planned` (transisi `planned → planned` internal — aman, belum ada produk tertulis; plan baru versi N+1 otomatis menggugurkan token lama via plan_hash).

### MINOR-3 — Output approve gagal berupa `WP_Error`, bukan field `note`
Spec §1.4 mendefinisikan output `note` berisi alasan penolakan bila gagal; implementasi melempar `WP_Error` (`class-bcs-abilities.php:651-661`). Lebih idiomatik untuk MCP, tapi menyimpang dari kontrak output dan tidak didokumentasikan di self-check.
**Fix**: dokumentasikan sebagai deviasi, atau kembalikan array dengan `status:'rejected'` + `note`.

### NIT-2 — `plan_hash` = sha1(`plan_id|plan_json`), bukan sha1(`plan_json`) murni
`class-bcs-plan-builder.php:644-653`. Fungsional setara (bahkan lebih kuat — hash terikat baris plan), tapi beda dengan redaksi §3.3. Dokumentasikan.

### NIT-3 — Dukungan validator `const` + `type: ["string","null"]` belum terbukti live
Output schema memakai `const` (mis. `approval_token_hint`, `status: parsed`) dan `type` array untuk null (mis. `sku`). Validasi output Abilities API pada WP 7.1.1 live harus dikonfirmasi di Fase D; bila validator menolak `const` yang diterjemahkan `__()` di situs non-ID, output akan gagal validasi.

---

## 3. Logika Bisnis

### BLOCKER-1 — `on_duplicate=update` diam-diam meng-unpublish produk live dan men-zero-kan stok
- `class-bcs-plan-builder.php:466` — `build_payload()` **selalu** mengisi `'status' => $defaults['status']` (default `'draft'`) dan `'stock_quantity' => (int) $row['stock_quantity']`.
- `class-bcs-plan-builder.php:447-460, 452-466` — operasi `update_product` memakai `build_payload()` yang sama; parser juga men-default stok kosong menjadi `0` + warning (`class-bcs-csv-parser.php:449-466`).
- `class-bcs-catalog-writer.php:516-530` — `apply_scalar_fields()` pada update: `set_status($payload['status'])` **selalu dieksekusi**, dan `set_stock_quantity(0)` + `set_stock_status('outofstock')` untuk nilai 0.

Akibatnya, pada alur emas spesifikasi sendiri (§4 langkah 4: baris 5 duplikat → `on_duplicate=update`), produk existing yang **published** akan kembali jadi **draft**, dan baris dengan kolom Stok kosong men-set stok live menjadi 0 + outofstock. Spec §1.3 menegaskan `defaults.status` adalah *"Status produk **baru**"*. Ini korupsi data komersial yang senyap (recoverable hanya via rollback).
**Fix konkret**: (a) untuk `op=update_product`, payload tidak boleh membawa `status` (hapus dari `build_payload` via flag `for_update=true`); (b) parser/plan-builder: stok kosong → `null` (bukan 0) dan `apply_scalar_fields` melewatkan `stock_quantity`/`stock_status` bila `null` pada jalur update; (c) tambahkan harness test khusus "update tidak mengubah status/stok yang tak dikirim".

### MAJOR-3 — Rollback bisa men-trash produk milik job lain (row_key bertabrakan lintas job)
`class-bcs-rollback-service.php:196-230`: `find_created_product_id()` mencari produk hanya via meta `_bcs_row_key`. Per §5.4, `row_key = sha1(source_hash|row_index|sku)` **sengaja tanpa job_id** — CSV identik yang diproses ulang pada job lain menghasilkan row_key yang sama, dan `update_product` ikut menimpa meta `_bcs_row_key` pada produk yang sama (`catalog-writer :786-797`). Rollback job lama dapat men-trash produk yang kini dimiliki/di-update job baru.
**Fix konkret**: ambil `product_id` dari **audit** (sumber yang sudah job-scoped): `SELECT product_id FROM bcs_audit WHERE job_id=%d AND level='row' AND payload_digest=%s AND action='create_product' AND status='ok' LIMIT 1`. Meta `_bcs_row_key` jadikan fallback dengan verifikasi `_bcs_job_id = job_id`.

### MINOR-4 — Resume menghitung ulang baris sukses lama sebagai `skipped`
`class-bcs-executor.php:122-125`: pada resume, baris yang `has_row_success` di-`$counts['skipped']++`, padahal semantik `skipped` pada spec = skip-duplikat. Counter job akhir menjadi menyesatkan (created tampak kurang, skipped tampak lebih).
**Fix**: hitung baris resume sebagai counter terpisah (mis. `resumed`) atau jangan increment skipped untuk baris yang sudah ok (counter job sudah dipersist per chunk).

### MINOR-5 — `image_warning` tidak pernah sampai ke audit (dead code)
`class-bcs-catalog-writer.php:306-311`: `if ( is_wp_error( $image_result ) ) { $image_result = null; }` menghapus error **sebelum** return `image_warning => is_wp_error(...)` (`:339-342`) — selalu `null`. Warning gambar gagal hilang tanpa jejak.
**Fix**: simpan pesan error ke variabel `$image_warning` dan kembalikan itu, tanpa men-null-kan.

### MINOR-6 — Approve admin: redirect setelah output terkirim
`class-bcs-admin.php:347-360` memanggil `handle_plan_actions()` **di tengah render** (setelah tabel operasi tercetak). `wp_safe_redirect()` pada jalur sukses/gagal verifikasi akan gagal (headers already sent) lalu `exit()` memotong halaman — pengguna tidak melihat notice sukses/error. Deviasi #10 self-check sah sebagai pola, tapi harus diproses sebelum output.
**Fix**: pindahkan pemrosesan POST ke hook awal (mis. `load-{menu_slug}` / awal `render()` sebelum echo apa pun).

### MINOR-7 — Parsing CSV tidak menangani encoding non-UTF-8
`class-bcs-csv-parser.php:117-124` hanya strip BOM. CSV Windows-1252/ISO-8859-1 (umum dari Excel) akan tersimpan apa adanya → karakter rusak permanen di nama/deskripsi produk.
**Fix**: deteksi via `mb_check_encoding`/`iconv` dan konversi ke UTF-8 (atau minimal warning bila bukan UTF-8 valid).

### NIT-4 — Override `row_overrides[op=update]` pada baris non-duplikat diam-diam menjadi create
`class-bcs-plan-builder.php:289-322` — kondisi `if ( 'update' === $op && $is_dup )` gagal → jatuh ke blok create. Validasi saat eksekusi menangkap SKU bentrok, tapi lebih baik ditolak/warning saat build.

### NIT-5 — `add_settings_error()` tidak tampil di halaman menu kustom
`class-bcs-admin.php:541` — pesan error generate-token tidak pernah dirender (tanpa `settings_errors()`). Tampilkan notice inline.

### NIT-6 — Label kolom "Waktu (UTC)" salah
`class-bcs-admin.php:712` + `mysql2date('c', …)` di audit logger mengonversi ke timezone situs, bukan UTC. Ganti label atau pakai `gmdate` dari timestamp mentah.

### NIT-7 — Entri audit generate token memakai kolom `ability='wp_admin'`
`class-bcs-admin.php:558` — kolom `ability` diisi bukan nama ability. Gunakan `action='generate_token'` + `actor_via=wp_admin` dengan ability kosong/konsisten.

### NIT-8 — Resume menghapus marker deferred
`class-bcs-job-manager.php:336-345` — resume men-set `last_error = NULL`; bila request resume lalu mati sebelum chunk selesai tanpa deferred baru, marker hilang dan job harus menunggu stale 5 menit. Pertahankan marker sampai finalisasi.

---

## 4. Kualitas WordPress

- Prefix `bcs_` konsisten; tidak ada fungsi global tanpa prefix (hanya `bcs_activate/deactivate/maybe_upgrade/load_textdomain`). ✅
- Text domain `bulk-catalog-studio` konsisten (289 pemakaian; 11 temuan `'bcs'` adalah slug kategori/kode settings-error, bukan text domain). ✅
- i18n penuh (`__()` + translators comment rapi); `load_plugin_textdomain` di `init`. ✅
- Aktivasi/deaktivasi/uninstall sesuai §3.6 + AC-7/AC-8; uninstall hanya via `uninstall.php`, produk tidak disentuh; transient `bcs_rate_*` dibersihkan (NIT: bila object cache aktif, transien tersisa di cache — kosong, boleh diabaikan). ✅
- Kompatibilitas PHP 7.4: `php -l` lulus di PHP 8.5; grep nullsafe/match/named-args/enum/readonly/`str_*` 8.x — bersih. ✅ (klaim self-check terverifikasi)
- Sanitasi CSV: BOM ✅, baris kosong ✅, kolom kurang/lebih ✅ (kolom tanpa mapping jadi warning), encoding ❌ (MINOR-7).

---

## 5. Verifikasi Ulang Klaim Self-Check (`docs/dev/self-check.md`)

Self-check menyatakan 10 deviasi (instruksi review menyebut "6 penyimpangan" — jumlah di dokumen adalah 10; semua dicek):

| # | Deviasi | Verdict reviewer |
|---|---|---|
| 1 | `token_hash` VARCHAR(255) | **Setuju** — terdokumentasi di `install.php:84-88`; tetap hash, bukan plaintext |
| 2 | `payload_digest` entri row = row_key | **Setuju** — `has_row_success` memang query kolom ini (`audit-logger :219-240`) |
| 3 | Plan status `staging` v0 | **Setuju** — di luar enum §3.3 tapi diperlukan §1.1 |
| 4 | Deferred marker `bcs_deferred` + output `partially_failed` | **Setuju** — implementasi persis seperti dijelaskan (`executor :289-330`, `job-manager :315-332`) |
| 5 | `update_product` tidak menyentuh variasi existing | **Setuju sebagian** — variasi memang tidak disentuh; **tapi klaim "menjaga akurasi rollback" menutupi bug yang justru ada di field lain** (BLOCKER-1: status & stok tetap tersentuh) |
| 6 | Rate limit fixed-window | **Setuju** — terdokumentasi |
| 7 | Row `create_variations` tambahan di audit | **Setuju** — tidak melanggar skema output |
| 8 | `sideload_url` diterima schema, ditolak saat build | **Setuju** — `plan-builder :243-246` sesuai gate1 #4 |
| 9 | `image_url` numerik = attachment_id | **Setuju** — `csv-parser :617-635` |
| 10 | POST approve/token di halaman sama | **Setuju polanya, tapi eksekusinya bermasalah** — redirect setelah output (MINOR-6) |

**Klaim lain yang dicek langsung:**
- §1–§5 (guard server, state machine, dbDelta, rate limit, row_key, re-check capability): **akurat**.
- §8 "33/33 PASS parser": **akurat** (dieksekusi ulang: 33 PASS).
- §8 "73/73 PASS integrasi": **tidak persis** — aktual **72 PASS, 0 FAIL** (selisih 1; kecilkan klaim atau cek test yang hilang).
- §4 "attachment: post_type + mime text/csv": **kurang presisi** — kode menerima 4 mime (NIT-1).
- Kebutuhan Fase D yang disebut §9 (eksekusi WC live, dbDelta MySQL, discovery MCP) — **setuju**, di luar cakupan statis ini.

---

## 6. Prioritas Eksekusi untuk Orchestrator (urut)

1. **BLOCKER-1** — update tidak boleh menyentuh `status`/`stock_quantity` yang tidak dikirim (+ harness test).
2. **MAJOR-3** — rollback ambil `product_id` dari audit, bukan meta row_key lintas job.
3. **MAJOR-2** — konsumsi token atomik (`WHERE token_used_at IS NULL`, setelah transisi).
4. **MAJOR-1** — SSRF: blokir IP privat + matikan/limit redirect (atau kunci host ke `docs.google.com`).
5. MINOR-2…MINOR-7 dan NIT sesuai kapasitas; MINOR-6 & MINOR-7 disarankan ikut patch pertama.
6. Koreksi dua klaim self-check (73/73 → 72; mime attachment) agar dokumen akurat.

Setelah 1–4 selesai: **patch re-review** pada 4 titik tersebut, lalu lanjut Fase D (deploy + verifikasi live AC-1/2/6 + discovery MCP + validasi schema `const`/type-array di WP 7.1.1).

---

## Sumber eksternal yang di-fetch untuk verifikasi

- https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/src/wp-includes/class-wpdb.php — konfirmasi `process_field_formats()`/`update()`: nilai `null` → `col = NULL` (format null di-fallback ke format pertama, tidak mematahkan query). Pola `formats_for()` plugin dengan demikian **aman**, bukan temuan.
