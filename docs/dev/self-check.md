# Self-Check: Bulk Catalog Studio (Fase B — developer scout)

Tanggal: 21 September 2026 (diperbarui setelah retur QA-1) · Verifikator: Pi (developer scout)
Metode: review statis per poin spec + **pengujian runtime di luar WordPress** (2 harness PHP dengan stub WP/WC — lihat §8) + `php -l` seluruh file (PHP CLI lokal 8.5 — parse-level, kode ditulis kompatibel PHP 7.4; tidak ada ssh ke VPS sama sekali). Bagian §10 = perbaikan retur QA-1.

Legend: ✅ terverifikasi · ⚠️ terverifikasi dengan catatan/deviasi terdokumentasi · ❌ tidak terpenuhi

---

## §1 Ability-by-ability (8 ability)

Registrasi: `includes/class-bcs-abilities.php` — kategori `bcs` via `wp_abilities_api_categories_init` (`bulk-catalog-studio.php:111`, registrar `class-bcs-abilities.php:28`), 8 ability via `wp_abilities_api_init` (`bulk-catalog-studio.php:112`, definisi `:71–163`). Meta standar: `show_in_rest=true`, `mcp.public=true`, `mcp.type='tool'` (`:168–186`). Re-check capability + rate limit di dalam setiap `execute_callback` (`server_guard` `:230`).

| Ability | Input schema strict | Output schema | Permission (callback + re-check) | Status | Bukti |
|---|---|---|---|---|---|
| `bcs/parse-source` | ✅ `additionalProperties:false`, required `[source_type]` (schemas `:28–86`) | ✅ job_id/status const parsed/source_hash/total_rows/warnings required | ✅ `edit_products` (`:196–204`, re-check `:295`) | ✅ | Uji integrasi T1–T2; fixture 30 baris → job parsed |
| `bcs/check-duplicates` | ✅ (`schemas :88–134`) strategy sku/sku+title, on_duplicate flag_only/skip/update | ✅ intra_batch_duplicates + existing_matches + duplicate_count + on_duplicate_effective | ✅ `edit_products` (`:517`) | ✅ | Uji T3: 1 grup intra-batch KML-GRS-005 baris 5&15 |
| `bcs/build-plan` | ✅ (`schemas :136–229`) + `row_overrides` (tambahan yang DISURUH spec §4 catatan implementasi) | ✅ summary 6 field + plan_hash + diff_preview_url + approval_token_hint | ✅ `edit_products` (`:571`); `flag_only` ditolak (`plan-builder :193`) | ✅ | Uji T3b/T4: summary 18/10/60/2/30 |
| `bcs/approve-plan` | ✅ plan_id + plan_hash(40) + confirmation_token pattern `^[A-Z0-9-]+$` 6–12 | ✅ status const approved + approved_by/at | ✅ `manage_woocommerce` (gate1 #3) (`:610`) | ✅ | Uji T9: token salah/plan_hash beda/single-use semua ditolak |
| `bcs/execute-batch` | ✅ plan_id + dry_run + resume | ✅ counts 6 field + failures[] + duration_ms | ✅ `manage_woocommerce` (`:698`) | ✅ | Uji T10: done, created 28, variasi 60, skip 2, failed 0 |
| `bcs/job-status` | ✅ job_id opsional, required `[]` | ✅ jobs[] + stale flag | ✅ `edit_products` (`:724`) | ✅ | Uji T12: detail + list 20 |
| `bcs/rollback-batch` | ✅ job_id + confirm const true + scope | ✅ counts trashed/restored/variations/failed + note | ✅ `manage_woocommerce` (`:788`) | ✅ | Uji T14: 28 trashed, terminal, confirm ditolak |
| `bcs/audit-log` | ✅ job_id + level/limit/offset | ✅ total + entries[] sesuai kolom audit | ✅ `edit_products` (`:815`) | ✅ | Uji T12: 40 entri row (28 create + 10 variasi + 2 skip) |

Catatan gotcha riset (abilities-mcp-api.md): hook `wp_abilities_api_init` (bukan `abilities_api_init`) ✅; kategori didaftarkan dulu ✅; guard `function_exists('wp_register_ability')` ✅; idempotent-guard `wp_has_ability` ✅ (`:49`).

## §2 State machine job

| Poin | Status | Lokasi |
|---|---|---|
| Status lengkap 9 nilai | ✅ | `class-bcs-job-manager.php:23–33` |
| Peta transisi persis §2.2 (failed & rolled_back terminal) | ✅ | `:46–60` (uji T15 konsistensi) |
| Transisi invalid → `WP_Error` + entri audit | ✅ | `:226–286` (uji T6: planned→executing ditolak) |
| Tidak ada parameter `status` dari input eksternal | ✅ | tidak ada di schema input manapun |
| Transisi atomik `WHERE status = <expected>` | ✅ | `:262–271` (update guard, affected===1) |
| Maks 1 job executing per site | ✅ | `begin_execution :322–331` (cek job executing lain non-stale) |
| `executing → executing` hanya resume + stale (>5 menit) ATAU marker deferred | ✅ (+extensi deferred terdokumentasi §7) | `:305–330`; staleness `is_stale :417–427` |
| Heartbeat tiap chunk | ✅ | `:432–446`; executor `:108–110` |

## §3 Model data

| Poin | Status | Lokasi |
|---|---|---|
| `bcs_jobs` kolom + 4 index persis §3.2 | ✅ | `class-bcs-install.php:44–71` |
| `bcs_plans` kolom + 2 index persis §3.3 | ⚠️ `token_hash` VARCHAR(255) bukan CHAR(60) — alasan: menampung portable hash (34), bcrypt (60), argon2id (±97) tergantung host; tetap hasil hash, bukan plaintext | `:84–111` |
| `bcs_audit` kolom + 3 index persis §3.4 | ⚠️ `payload_digest` dipakai juga sebagai penyimpan row_key untuk entri row (keduanya sha1-hex 40) — memungkinkan query idempotency §5.4 tanpa kolom baru | `:124–152` |
| Plan staging v0 (hasil parse) | ✅ status `staging` (nilai tambahan di luar enum plan — terdokumentasi) | `class-bcs-plan-builder.php:63–107` |
| Plan versi naik per rebuild, hash = sha1 | ✅ | `persist_plan :470–503`, `hash_plan :510–517` |
| Options `bcs_db_version`, `bcs_settings`, transient rate | ✅ | `class-bcs-settings.php`, `class-bcs-install.php:155–157` |
| Bentuk operasi plan_json sesuai kontrak §3.3 (row_key, op, payload, before, duplicate_check) | ✅ | `build() :207–437`, payload builder `:552–660` |

## §4 Alur end-to-end

| Poin | Status | Bukti |
|---|---|---|
| 3 mode sumber: inline_csv / attachment / url | ✅ | `class-bcs-abilities.php:299–390`; attachment: post_type + mime text/csv; url: https-only + Google Sheets wajib export CSV |
| CSV 30 baris (20 simple + 10 variable ≈ 60 variasi, 2 duplikat SKU) | ✅ | fixture `tests/fixtures/sample-fashion-30.csv`; uji parser: 30/20/10/60 + dup baris 5&15 |
| Deteksi upload ganda via source_hash (warning non-blocking) | ✅ | `find_job_by_source_hash :483–501` (uji T2) |
| Keputusan per-baris via `row_overrides` (skip/update/create) | ✅ | `class-bcs-plan-builder.php:258–270` |
| Preview diff di layar admin + token tampil SEKALI | ✅ | `admin/class-bcs-admin.php:325–…` (generate `:530–560`, tampil sekali `:520–527`); hash token disimpan, plaintext tak pernah dipersist |
| Approve via MCP (token dari manusia) ATAU tombol admin | ✅ | ability `:610–681`; tombol admin `:562–621` |
| Eksekusi chunk 10 + heartbeat + resume idempotent | ✅ | `class-bcs-executor.php:21,104–260` (uji T10) |
| Rollback: 28 produk + variasi trash, update → snapshot before | ✅ | uji T14; `class-bcs-rollback-service.php:33–215` |

## §5 Model keamanan

| Poin | Status | Lokasi |
|---|---|---|
| §5.1 User khusus + role minimal (dokumentasi di README) | ✅ (dokumen; pembuatan user bukan ranah plugin) | `README.md` §2 |
| §5.2 Capability mapping: 5 read=`edit_products`, 3 write=`manage_woocommerce` | ✅ | tabel §1 di atas |
| §5.3 Rate limit 60/menit sliding window transient | ✅ (fixed-window bucket 60 dtk — approximasi sliding, terdokumentasi) | `class-bcs-abilities.php:242–256` (uji T17: call ke-61 ditolak) |
| §5.3 Batas: 100 baris/job (hard 500), inline 2MB, attachment 10MB, variasi 100/produk | ✅ | `class-bcs-csv-parser.php:152–179` (uji 600 baris ditolak), `class-bcs-settings.php` |
| §5.4 row_key = sha1(source_hash|row_index|lower(trim(sku))) | ✅ | `class-bcs-csv-parser.php:872–878` (uji case/trim insensitive) |
| §5.4 Resume lewati baris ok via audit (job_id, row_key, ok) | ✅ | `has_row_success :219–240`; executor `:126–131` |
| §5.4 Verifikasi SKU ulang saat eksekusi (race) | ✅ | `class-bcs-catalog-writer.php:206–216, 448–461` |
| §5.5.1 Strict schema additionalProperties:false semua ability | ✅ | `class-bcs-schemas.php` (uji T16 per ability) |
| §5.5.2 Tulis produk hanya via WC CRUD; tanpa wp_insert_post/$wpdb tulis | ✅ | `class-bcs-catalog-writer.php` — tidak ada wp_insert_post; `$wpdb` tulis hanya di job/plan/audit (bukan produk) |
| §5.5.2 Whitelist meta `_bcs_*` + SEO + `_wp_attachment_image_alt` | ✅ | `:35, 42–50, 52` |
| §5.5.3 Whitelist post type product/product_variation + taxonomy product_cat/product_tag/pa_* | ✅ | `ensure_term :655–668` (hard error), trash/update whitelist |
| §5.5.4 Guard `class_exists('WC_Product')` | ✅ | `guard() :59–66` — dipanggil executor + rollback |
| §5.5.5 Re-check permission di execute_callback | ✅ | `server_guard :230–268` |
| §5.5.6 Semua write teraudit (actor_user_id + actor_via) | ✅ | `class-bcs-audit-logger.php:44–110` |
| §5.5.7 Token terikat plan_hash | ✅ | `class-bcs-approval-token.php:103–106` (uji T9) |

## §6 Acceptance criteria (statik; eksekusi penuh butuh WP+WooCommerce nyata — ranah Fase C/D)

| AC | Status bukti kode | Catatan |
|---|---|---|
| AC-1 30 produk+variasi 1 CSV | ✅ simulasi penuh di harness (T10: created 28+2 skip sesuai policy, variasi 60) | perlu verifikasi live di staging |
| AC-2 Produk native editable | ✅ by-construction: hanya WC_Product CRUD; set_attribute/variasi + sync (`writer:296–334`) | verifikasi live menyusul |
| AC-3 Preview sebelum apply | ✅ | admin `render_plan` + before/after (`:610–650`) |
| AC-4 Approval wajib + token single-use | ✅ | uji T7 (execute sebelum approve ditolak), T9 (token salah/pakai-ulang ditolak) |
| AC-5 Log perubahan | ✅ | uji T12: 28 entri create_product ok dengan product_id + actor |
| AC-6 Rollback | ✅ simulasi (T14: 28 trashed, terminal) | restore snapshot butuh WC live (restore path `writer:1065–1150`) |
| AC-7 Deaktivasi tanpa kehilangan data | ✅ | `bcs_deactivate` kosong (`bulk-catalog-studio.php:76–82`) |
| AC-8 Uninstall bersih | ✅ | `uninstall.php` — drop 3 tabel + 2 option + transient `bcs_rate_*`; produk tidak disentuh |
| AC-9 Permission + audit semua ability | ✅ | matriks §1 + audit call/row |
| AC-10 Deteksi duplikasi | ✅ | uji T3 (intra-batch) + `find_by_sku`/`find_by_title` |
| AC-11 Idempotency & resume | ✅ | row_key audit check (`executor:126–131`) + begin_execution resume gate (`job-manager:305–330`); deferred time-budget → resume tanpa tunggu stale |
| AC-12 Batas & scope | ✅ | 600 baris ditolak (uji), schema strict menolak field liar (uji T16), whitelist tulis |

## §7 Deviasi/keputusan implementasi (semua terdokumentasi)

1. **`token_hash` VARCHAR(255)** (bukan CHAR(60)) — dukung semua algoritma hash password WP. Nilai tetap hash, bukan plaintext.
2. **`payload_digest` entri row = row_key** — keduanya sha1-hex 40; menghindari kolom baru dan tetap memenuhi query idempotency §5.4.
3. **Plan status `staging`** untuk baris v0 (di luar enum plan §3.3) — untuk persist hasil parse sesuai §1.1.
4. **Deferred time-budget**: bila anggaran waktu request habis di tengah chunk, job TETAP `executing` dengan marker `bcs_deferred` di `last_error` dan output `partially_failed` + reason `deferred` — resume diperbolehkan tanpa menunggu stale bila marker ada. Alasan: output schema §1.5 tidak punya status `executing`, dan memaksa manusia menunggu 5 menit untuk deferred yang fresh tidak ergonomis. Idempotency tetap dijaga row_key.
5. **`update_product` tidak menyentuh variasi/atribut existing** di V1 — menjaga akurasi rollback snapshot (variasi hanya untuk create). Warning muncul di parse bila row update punya kolom variasi.
6. **Rate limit fixed-window 60 dtk** (bukan true sliding) — perbedaan worst-case kecil, implementasi bebas-lock.
7. **Row `create_variations`** tambahan di audit per produk variable (di atas entri `create_product`) — untuk trace jumlah variasi; tidak melanggar skema.
8. **`sideload_url` diterima di schema image_policy** (sesuai §1.3) tapi ditolak saat build dengan pesan gate1 #4 — V1 hanya `attachment_id_only`.
9. **`image_url` numerik** diterima sebagai attachment_id (pola umum data orang); URL non-numerik diabaikan + warning.
10. **Admin approve/token** diproses POST di halaman yang sama (bukan admin_post) agar token tampil inline sekali tanpa lewat query-string redirect.

## §8 Hasil verifikasi runtime (harness, bukan WP)

Harness di `tests/` (di luar folder plugin): stub minimal WP (`WP_Error`, wpdb fake pattern-matcher, transients, hash) + stub WC (`WC_Product*`, `wc_get_product`, term, dsb).

- `php tests/test-bcs-parser.php` → **33/33 PASS**: fixture 30/20/10/60, duplikat intra-batch 5&15, row_key stabil, 600 baris ditolak (AC-12), delimiter ;/tab, mapping Indonesia persis spec §4, harga `189.000`→189000 / `1.899,50`→1899.5 / `seribu`→warning, cartesian rightmost-fastest selaras urutan SKU variasi.
- `php tests/test-bcs-integration.php` → **134 PASS / 0 FAIL** (72 test alur awal + 62 test regresi retur QA-1 — klaim lama "73/73" dikoreksi: sebelum retur aktualnya 72 PASS): alur penuh parse→dup-check→build-plan (18/10/60/2/30)→token→approve→execute (done, 28/60/2/0)→job-status→audit-log (40 row)→rollback (28 trashed, terminal), dry_run tanpa perubahan state, state machine guards, rate limit, schema strict, PLUS regresi QA-1 (R1–R10, lihat §10).
- `php -l` → semua file plugin lulus; tidak ada sintaks PHP 8 (nullsafe, match, named args, enum, readonly, promotion sudah di-grep kosong).

Bug nyata yang tertangkap & diperbaiki berkat harness: (a) dobel prefix `pa_pa-ukuran` akibat `sanitize_title` mengubah `_`→`-` pada nama taxonomy (3 titik: parser, plan-builder, writer); (b) urutan cartesian atribut tidak selaras urutan SKU variasi; (c) jumlah kombinasi harus mengikuti daftar `variation_sku` eksplisit bila ada; (d) harga ribuan gaya Indonesia `189.000`.

## §9 Yang TIDAK bisa diverifikasi di fase ini (untuk Fase C/D)

- Eksekusi live terhadap WooCommerce nyata (AC-1/2/6 field-level, `WC_Product_Attribute` + term pairing, Yoast/Rank Math meta live).
- Registrasi ability live di WP 7.1.1 + discover via MCP Adapter (butuh situs; deploy ranah orchestrator).
- `dbDelta` di MySQL nyata (dibuat sesuai kaidah dbDelta: KEY, dua spasi PRIMARY KEY, satu field per baris).
- Validasi validator Abilities API live terhadap `const` + `type: ["string","null"]` (NIT-3 QA) — Fase D.

## §10 Perbaikan Retur QA-1 (semua BLOCKER + MAJOR + MINOR murah)

Referensi laporan: `docs/review/qa-report.md`. Semua perubahan diverifikasi `php -l` + harness penuh (§8).

| Temuan | Perbaikan | Lokasi | Status |
|---|---|---|---|
| **BLOCKER-1** update membawa `defaults.status=draft` + stok default 0 ke produk existing | `build_payload()` menerima `$for_update`: payload update TANPA key `status`, dan TANPA `stock_quantity` bila kolom stok kosong (flag baru `stock_provided` dari parser) | `class-bcs-plan-builder.php` (build_payload + op update), `class-bcs-csv-parser.php` (stock_provided) | ✅ uji R1: produk existing publish + stok 7 tidak berubah kecuali field dikirim |
| **MAJOR-3** rollback lookup meta row_key bisa kollide lintas job | `find_created_product_id()` sumber primer = audit job ini (`find_row_product_id`), fallback meta + verifikasi `_bcs_job_id` | `class-bcs-rollback-service.php`, `class-bcs-audit-logger.php:find_row_product_id` | ✅ uji R2: dua job CSV identik — rollback job B hanya trash produk B, produk A aman |
| **MAJOR-2** konsumsi token tidak atomik + urutan salah | `consume()` = `UPDATE … WHERE id=%d AND token_used_at IS NULL` (wajib affected===1); dipanggil SETELAH transisi job sukses (jalur MCP + admin admin sama) | `class-bcs-approval-token.php:consume`, `class-bcs-abilities.php:execute_approve_plan`, `admin/class-bcs-admin.php` | ✅ uji R3: consume kedua ditolak; dua approve "bersamaan" hanya satu pemenang |
| **MAJOR-1** SSRF mode url | V1 kunci host ke `docs.google.com` (filter `bcs_allowed_source_hosts`), resolve host + blokir IP privat/loopback/link-local (IPv4 CIDR + IPv6 fc00/7, fe80/10), `redirection => 0`, wajib export=csv; dicatat di README batas V1 | `class-bcs-abilities.php` (`is_host_allowed`, `resolve_host_ip`, `is_ip_private`) | ✅ uji R4: whitelist/blokir IP/https/export guard + happy path docs.google.com |
| MINOR-1 entropy token | 5 → 8 karakter (total 12, tetap dalam schema) | `class-bcs-approval-token.php:generate` | ✅ uji T9/R5 |
| MINOR-2 tidak ada jalur rebuild plan | build-plan diizinkan pada job `planned`; plan planned lain otomatis `superseded` SEBELUM insert plan baru; approve-plan menolak plan non-`planned` | `class-bcs-plan-builder.php:build`, `class-bcs-abilities.php` | ✅ uji T5/R6 + R6b (approve plan superseded ditolak) |
| MINOR-3 output approve gagal berupa WP_Error | DOKUMENTASI deviasi (pilihan reviewer): idiomatik MCP; tidak diubah kodenya | bagian deviasi di bawah | ✅ dokumen |
| MINOR-4 resume double-count `skipped` | resume memakai `has_row_entry` (ok ATAU skipped) dan TIDAK menambah counter; entri failed tetap di-retry | `class-bcs-executor.php`, `class-bcs-audit-logger.php:has_row_entry` | ✅ uji R10: counter tetap 2/28/60 |
| MINOR-5 `image_warning` dead code | pesan error disimpan ke `$image_warning` sebelum return | `class-bcs-catalog-writer.php:create_product` | ✅ uji R7 |
| MINOR-6 redirect setelah output | proses POST dipindah ke awal `render()` sebelum satu pun output; token hasil generate diteruskan ke `render_plan()` | `admin/class-bcs-admin.php` | ✅ kode review + lint |
| MINOR-7 encoding non-UTF-8 | deteksi `mb_check_encoding` + konversi Windows-1252/ISO-8859-1 → UTF-8 + warning | `class-bcs-csv-parser.php:maybe_convert_to_utf8` | ✅ uji R8 (Café Windows-1252) |
| NIT-1 mime attachment | whitelist disempitkan (`.xls` biner dihapus): text/csv, text/plain, application/csv | `class-bcs-abilities.php` | ✅ + koreksi klaim §4 self-check |
| NIT-4 override update pada non-dup | ditolak eksplisit saat build (`bcs_invalid_override`) | `class-bcs-plan-builder.php` | ✅ uji R9 |
| NIT-5 `add_settings_error` tak tampil | error generate token → redirect `bcs_error` (sama dengan approve) | `admin/class-bcs-admin.php` | ✅ kode review |
| NIT-6 label "Waktu (UTC)" | label menjadi "Waktu" (nilai ISO dari `mysql2date`) | `admin/class-bcs-admin.php` | ✅ |
| NIT-7 kolom ability log generate token | `'wp_admin'` → `'bcs/admin-screen'` (actor_via tetap deteksi otomatis) | `admin/class-bcs-admin.php` | ✅ |
| NIT-8 resume menghapus marker deferred | resume tidak lagi `last_error = NULL`; marker bertahan sampai finalisasi | `class-bcs-job-manager.php` | ✅ |
| NIT-2 `plan_hash` = sha1(plan_id\|plan_json) | DOKUMENTASI deviasi (lebih kuat dari §3.3 — terikat baris plan) | daftar deviasi di bawah | ✅ dokumen |
| NIT-5..8 lainnya | lihat baris di atas | — | ✅ |

Deviasi tambahan yang terdokumentasi pasca-retur:
11. **Approve gagal = WP_Error** (bukan field `note` §1.4) — idiomatik MCP error; kontrak `note` hanya dipakai bila failed > 0 pada output yang sukses.
12. **`plan_hash` = sha1(plan_id + '|' + plan_json)** — lebih kuat dari §3.3: approval terikat baris plan spesifik.
13. **Status plan `superseded`** — nilai tambahan untuk hasil rebuild (MINOR-2).

### Koreksi klaim self-check (sesuai retur)
- §8 lama: "73/73 PASS integrasi" → dikoreksi: sebelum retur aktualnya **72 PASS / 0 FAIL**; kini 134 PASS / 0 FAIL setelah regresi QA-1.
- §4 lama: "attachment: post_type + mime text/csv" → dikoreksi: whitelist mime adalah text/csv, text/plain, application/csv (.xls biner dihapus pada retur ini).

## §11 Custom MCP server Gate 3 (nol ketergantungan route Novamira)

Konteks: endpoint default `/wp-json/mcp/mcp-adapter-default-server` di server target ter-intercept Novamira (`serverInfo: Novamira (legacy alias)`). Plugin kini mendaftarkan MCP server sendiri sehingga endpoint utama menjadi `/wp-json/bcs-mcp/mcp`.

| Poin Gate 3 | Implementasi | Lokasi | Status |
|---|---|---|---|
| 1. Hook `mcp_adapter_init` → `create_server()` sesuai riset §3.3 | `BCS_MCP_Server::init()` mendaftar hook; `register_server()` memanggil `$adapter->create_server(...)` 13 parameter | `includes/class-bcs-mcp-server.php:init/register_server` | ✅ uji G1–G2 (fake adapter merekam 1 panggilan) |
| 2. ID/namespace/route/nama/versi | `bcs-mcp-server` / `bcs-mcp` / `mcp` / `Bulk Catalog Studio MCP` / `BCS_VERSION` | helper terpusat `server_id/rest_namespace/route/server_name/server_version` | ✅ uji G2 |
| 3. Transport + handler | Hanya `\WP\MCP\Transport\HttpTransport`, `ErrorLogMcpErrorHandler`, `NullMcpObservabilityHandler` | `transport_class/error_handler_class/observability_handler_class` | ✅ uji G3 |
| 4. Tepat 8 ability sebagai tool individual | Daftar terpusat `ability_names()` (sinkron `BCS_Schemas::ability_names()`); di custom server muncul individual di `tools/list` (riset §3.1) | `ability_names()` | ✅ uji G4 (urutan + sinkron schemas) |
| 5. Parameter ke-13 `current_user_can('manage_woocommerce')` | Anonim tanpa return-type (PHP 7.4 aman), diuji dua arah capability | `transport_permission_callback()` | ✅ uji G5 (true/false) |
| 6. Guard class adapter/transport/handler; admin notice non-fatal | `dependencies_met()` cek 4 class; gagal → `register_server` return false, `create_server` tak dipanggil; pemeriksaan `admin_init` memastikan notice tetap terpicu saat hook adapter tidak ada; notice hanya untuk `activate_plugins`, tanpa fatal | `dependencies_met/admin_notice_missing_dependency` | ✅ uji G8 + subprocess `tests/test-bcs-mcp-missing-deps.php` (M1: tanpa class `WP\MCP\*` → tanpa fatal, notice muncul utk admin saja) |
| 7. Header `Requires Plugins: woocommerce, mcp-adapter` | Header bootstrap diperbarui | `bulk-catalog-studio.php:9` | ✅ uji G7 statis |
| 8. README/readme.txt endpoint kustom | `WP_API_URL` → `https://<domain>/wp-json/bcs-mcp/mcp`; ditegaskan default `/wp-json/mcp/...` TIDAK dipakai (Novamira); tools individual di `tools/list` | `README.md` (intro + §2 + §5 poin 9 + §7), `readme.txt` (installation + proxy + changelog) | ✅ uji G7 |
| 9. Test regresi ringan | Harness `tests/test-bcs-mcp-server.php` (26 asersi: konfigurasi create_server, endpoint, transport capability, notice, header, README) + subprocess missing-deps | `tests/test-bcs-mcp-server.php` + `tests/test-bcs-mcp-missing-deps.php` | ✅ 26 PASS + M1 7 assertion PASS |

Catatan perilaku (sesuai riset abilities-mcp-api.md §3):
- Pada custom server, ability TIDAK butuh `meta.mcp.public` untuk tampil (diekspos eksplisit), tetapi flag tetap dipertahankan agar ability juga terlihat di default server bila route itu dipakai.
- Guard tambahan: bila 8 ability belum terdaftar di registry saat `mcp_adapter_init` menembak, registrasi dilewati + notice (registrasi manual di luar hook `wp_abilities_api_init` ditolak core — riset gotcha #1).
- `admin_notices` hook didaftarkan sejak `init()`; notice hanya render bila flag dependency-missing terisi (teruji tidak muncul saat dependency lengkap — uji G6).

Hasil verifikasi Gate 3 (lokal, tanpa VPS): `php -l` 21 file PHP lulus (17 plugin + 4 harness) · harness parser 33 PASS · harness integrasi 134 PASS / 0 FAIL · harness MCP server 26 PASS (termasuk subprocess missing-deps 7 assertion PASS).
