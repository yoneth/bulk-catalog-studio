# Spesifikasi Fitur: BCS Sync (Google Sheets → Katalog WooCommerce)

| | |
|---|---|
| **Status** | Draft v1 — menunggu Gate 1 |
| **Basis** | `docs/research/gsheets-sync.md` (29 sumber, terverifikasi orchestrator 2026-09-21) |
| **Plugin** | `bulk-catalog-studio` (ekstensi modul sync, satu kodebasis) |
| **Prinsip induk** | BCS Sync = operator batch dengan gerbang; Sheet = satu-satunya penulis field yang di-own koneksi |
| **Kompatibilitas** | PHP 7.4, WordPress ≥ 6.x, WooCommerce aktif, MCP Adapter tetap dependency |
| **Bahasa user-facing** | Inggris (admin UI, schema MCP, error, README) |

---

## 0. Ringkasan desain

BCS Sync menambahkan kemampuan *one-way scheduled sync* dari Google Sheets ke katalog
WooCommerce di dalam plugin Bulk Catalog Studio. Arah **selalu satu arah**: Sheet = sumber
(source of truth) untuk field yang di-own koneksi; WooCommerce = tujuan.

Empat pilar desain (semua keputusan Gate-level sudah dikunci oleh Mas Rio):

1. **Approval per koneksi, bukan per operasi.** Manusia menyetujui koneksi (mapping +
   policy + threshold) sekali; run di dalam batas berjalan otomatis dengan audit penuh.
   Run yang menembus threshold masuk antre approval manual (reuse pola layar diff/token).
2. **Diff key-based per SKU dengan state per-record di tujuan.** Sumber kebenaran state =
   meta `_bcs_sync_hash` / `_bcs_synced_at` per produk/variasi — bukan snapshot global.
   Reorder sheet tidak memicu operasi; rollback/edit manual tidak membuat state stale.
3. **Rantai guard berurutan sebelum tulis.** Fetch valid → struktur kolom cocok → baris > 0 →
   diff → validasi nilai → threshold → lock → apply. Satu pun gagal = run berhenti/teredam
   **tanpa menulis apa pun** (kecuali quarantine field-level yang hanya menunda baris itu).
4. **Delete selalu eksplisit.** Hanya kolom `sync_action=delete` yang menghapus (soft, ke
   trash). Baris hilang dari sheet = flag review, tidak pernah auto-delete.

Non-goal penting: BCS Sync **tidak** menggantikan pipeline batch BCS yang ada
(parse → build-plan → approve → execute). Keduanya berbagi `BCS_Catalog_Writer`, executor
chunked, dan audit; sync adalah konsumen berkala dari primitif yang sama.

---

## 1. Komponen & ability baru

### 1.1 Kemampuan MCP baru (3 ability, namespace `bcs/`)

Custom server `bcs-mcp` bertambah dari 8 → 11 tool individual (nama dash).

#### `bcs/sync-list-connections` — read-only

Input: `{}` (tanpa parameter; strict schema, additionalProperties: false).

Output:
```json
{
  "connections": [ {
    "connection_id": 3,
    "name": "Main catalog",
    "fetcher": "sheets_api",
    "sheet_ref": "1AbC…xyz (gid 0)",
    "status": "active",
    "interval_minutes": 5,
    "last_run": { "run_id": 41, "state": "done",
                  "summary": { "create": 0, "update": 2, "variation_update": 6,
                                "delete": 0, "quarantined": 0, "conflict": 0, "failed": 0 } },
    "next_run_at": "2026-09-21T09:35:00+00:00"
  } ],
  "conflicts_pending": 2,
  "quarantine_pending": 1
}
```

#### `bcs/sync-run` — WRITE (memicu run untuk satu koneksi)

Input:
```json
{ "connection_id": 3, "reason": "manual" }
```
- `reason`: enum `manual|cron|webhook` (default `manual` bila dipanggil agent).
- Idempotent: bila ada run `running` untuk koneksi → ditolak `isError` dengan pesan
  run yang sedang berjalan (tidak pernah dua run paralel).
- Run yang dihasilkan mengikuti rantai guard §4 penuh — **agent tidak bisa memaksa
  apply melewati threshold** dengan memanggil tool ini.

#### `bcs/sync-approve-run` — WRITE (gerbang approval untuk run tertahan)

Input:
```json
{ "run_id": 57, "diff_hash": "<sha1>", "confirmation_token": "BCS-XXXXX" }
```
- Hanya untuk run ber-state `awaiting_approval`. Token one-time di-generate di layar
  admin (reuse `BCS_Approval_Token`, terikat `diff_hash` — generalisasi dari `plan_hash`).
- Analog `bcs/approve-plan`: transisi state run DULU (atomik), baru konsumsi token.

Ability lama `bcs/audit-log` menerima filter baru `source_type=sync` sehingga agent
bisa membaca jejak run. `bcs/job-status` tidak berubah (sync tidak memakai entitas job
batch; punya entitas run sendiri — alasan di §3.2).

### 1.2 WP-CLI worker (jalur cron)

```
wp bcs sync run --connection-id=<id>     # satu run (dipakai system cron)
wp bcs sync run-due                       # semua koneksi yang jatuh tempo
wp bcs sync test-connection --connection-id=<id>   # fetch+parse tanpa diff/apply
```
- System cron VPS menjalankan `wp bcs sync run-due` tiap menit (crontab RunCloud);
  plugin TIDAK memasang WP-Cron sendiri (riset Area 5: WP-Cron tidak andal).
- Setiap proses worker memegang **lock per koneksi** (§6.2) — aman dipanggil tiap menit.

### 1.3 Layar admin baru (satu submenu: "Sync")

`admin.php?page=bcs-sync` — empat tab:

1. **Connections** — daftar koneksi, status, tombol *Test connection* (fetch + parse +
   struktur kolom, tanpa apply), form add/edit (mengikuti kontrak sheet §7).
2. **Runs** — riwayat run per koneksi: state, ringkasan diff, durasi, link detail
   (tabel operasi run, quarantine, konflik).
3. **Quarantine** — baris tertahan validasi field-level + alasan + tombol
   *Apply anyway* / *Discard* (bercap `manage_woocommerce` + nonce).
4. **Conflicts** — produk dengan `_bcs_conflict_flag`; tombol *Sheet wins* /
   *Keep WP version* (resolve manual, tercatat di audit).

Semua teks Inggris, escaping penuh, nonces di semua aksi POST — mengikuti pola
`BCS_Admin` yang sudah lolos QA.

---

## 2. Model data

### 2.1 `{$wpdb->prefix}bcs_connections`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | BIGINT PK AI | |
| `name` | VARCHAR(120) | label manusia |
| `fetcher` | VARCHAR(20) | `csv_export` \| `sheets_api` |
| `sheet_config` | LONGTEXT JSON | csv_export: `{url,gid}`; sheets_api: `{spreadsheet_id,range}` |
| `credentials` | LONGTEXT | csv_export: NULL; sheets_api: SA key JSON — dienkripsi `openssl` dengan kunci turunan `AUTH_KEY` (lihat §8.4) |
| `column_mapping` | LONGTEXT JSON | sama format dengan mapping parse-source |
| `policy` | LONGTEXT JSON | `on_missing_row`, `conflict_policy=flag_only`, `auto_apply_mode` |
| `thresholds` | LONGTEXT JSON | §4.3 — semua angka per koneksi |
| `interval_minutes` | INT | minimum 1 |
| `status` | VARCHAR(12) | `active` \| `paused` \| `error_state` |
| `last_ok_run_at` | DATETIME NULL | untuk heartbeat |
| `created_at` / `updated_at` | DATETIME | |

### 2.2 `{$wpdb->prefix}bcs_sync_runs`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | BIGINT PK AI | |
| `connection_id` | BIGINT FK | |
| `trigger` | VARCHAR(8) | `cron` \| `manual` \| `webhook` |
| `state` | VARCHAR(20) | §5 |
| `diff_hash` | CHAR(40) NULL | sha1 operasi run — pengikat token approval |
| `diff_summary` | LONGTEXT JSON | hitungan per jenis operasi |
| `fetched_rows` | INT | untuk guard & observability |
| `error_code` / `error_note` | VARCHAR/TEXT | abort reason (guard mana yang memicu) |
| `started_at` / `finished_at` | DATETIME | |

Detail operasi per run **tidak** diduplikasi ke tabel baru — run menulis ke
`wp_bcs_audit` (level `row`, kolom `job_id` = run_id dengan penanda kolom baru
`source VARCHAR(10)` berisi `batch|sync`) sehingga `bcs/audit-log`, layar audit,
dan rollback memakai satu mekanisme.

### 2.3 Meta baru pada produk & variasi

| Meta key | Isi |
|---|---|
| `_bcs_sync_hash` | sha1 payload kanonik baris terakhir yang di-apply |
| `_bcs_synced_at` | timestamp apply terakhir |
| `_bcs_connection_id` | koneksi pemilik baris |
| `_bcs_conflict_flag` | timestamp saat konflik terdeteksi (NULL = bersih) |

Kunci idempotency sync = (`connection_id`, SKU produk / SKU variasi). Karena state ada
di record tujuan, rollback atau intervensi manual otomatis tercermin di diff berikutnya.

### 2.4 Tidak ada tabel snapshot global

Keputusan eksplisit (riset Area 4, tier-2 change detection): diff membandingkan hash
baris sheet vs `_bcs_sync_hash` di DB. Snapshot koneksi hanya menyimpan **set SKU yang
pernah terlihat** (untuk deteksi baris hilang → flag) sebagai bagian dari kolom
`policy.seen_skus` yang di-update tiap run sukses.

---

## 3. Fetcher interface

```php
interface BCS_Sync_Fetcher {
    /**
     * @return WP_Error|array { rows: array, fetched_at: string, meta: array }
     * rows = hasil parser CSV BCS (reuse BCS_CSV_Parser) — format sama dengan pipeline batch.
     */
    public function fetch( array $config ): array;
    public function test( array $config ): array; // untuk tombol Test connection
}
```

### 3.1 `BCS_Sync_Fetcher_Csv_Export` (dev/internal)

- GET `https://docs.google.com/spreadsheets/d/{id}/export?format=csv&gid={gid}`
  via `wp_remote_get`, timeout 30 dtk, `csv_export` wajib menyimpan `gid` eksplisit.
- Validasi hasil: HTTP 200 **tidak cukup** — payload harus lolos (a) sniff `text/csv`
  ATAU karakter pertama bukan `<`, (b) parse `BCS_CSV_Parser` sukses, (c) jumlah kolom
  header == jumlah kolom mapping. Gagal satu → `WP_Error` → run abort (riset Area 1:
  sheet privat/error balas HTML ber-status 200).
- Cache-buster `_cb=<unixts>` selalu ditambahkan (perilaku cache endpoint tidak
  terverifikasi — anggap bisa stale).

### 3.2 `BCS_Sync_Fetcher_Sheets_Api` (produksi)

- Service account JSON key (disimpan terenkripsi §8.4), scope
  `https://www.googleapis.com/auth/spreadsheets.readonly`, sheet di-share ke email SA.
- Token: JWT RS256 dibangun manual (`openssl_sign`) → `POST oauth2.googleapis.com/token`
  via `wp_remote_post`; access token di-cache transient 50 menit (umur 1 jam).
- Baca: `GET sheets.googleapis.com/v4/spreadsheets/{id}/values/{range}`
  `valueRenderOption=FORMATTED_VALUE` → konversi grid → CSV-text → parser yang sama.
- Kuota 300 read/mnt/project; interval minimum 1 menit per koneksi aman.

Apps Script push (privat real-time) = implementasi fetcher `apps_script_push` **V2**
(non-goal §9). Interface di atas sudah menampungnya.

---

## 4. Rantai guard, validasi, threshold

Eksekusi run mengikuti urutan ini; **setiap tahap gagal = berhenti sebelum tahap
berikutnya**, dan tidak ada tulisan katalog sebelum semua guard lolos.

### 4.1 Guard fetch (run abort, state `failed_fetch`)

1. `fetcher->fetch()` mengembalikan `WP_Error` (jaringan/HTTP/bukan CSV).
2. Header tidak cocok struktur mapping (kolom hilang/berubah nama).
3. `fetched_rows == 0` — **selalu abort** (sheet kosong = anomali, bukan "hapus semua").
4. Skema kolom beda total dari mapping tersimpan (>30% kolom tak dikenal) →
   koneksi masuk `error_state` + admin notice (indikasi sheet salah/di-rename).

### 4.2 Guard diff (run abort bila anomali struktural)

- Jumlah SKU yang hilang dari sheet > `thresholds.missing_row_flag_pct` (default 20%
  dari `seen_skus`) → run abort `failed_guard` **meskipun policy baris-hilang hanya
  flag** — proteksi ekstra terhadap sheet ter-filter/terpotong.
- SKU duplikat intra-sheet → run abort (ambigu, tidak boleh tebak).

### 4.3 Threshold (angka per koneksi, tersimpan `thresholds` JSON)

| Lapis | Default | Efek |
|---|---|---|
| Run-level create+update | `max(50 op, 10% katalog)` | run → `awaiting_approval`; diff dirender layar Runs; token one-time |
| Run-level delete | `> 10 delete/run` | sama seperti atas |
| Field-level quarantine | harga delta ±80%; stok turun >90%; harga ≤ 0; SKU tak cocok `^[A-Za-z0-9._-]{3,64}$` | baris → tabel quarantine (tab Quarantine), **run tetap jalan** |

`awaiting_approval` tidak menahan run berikutnya selamanya: run baru men-supersede
run tertahan yang belum diapprove (diff hash berubah → token lama otomatis gugur,
konsisten perilaku plan `superseded`).

### 4.4 Policy per-baris

- `sync_action=delete` → operasi soft-delete (trash, recoverable) — dihitung ke
  threshold delete.
- `sync_action=hold` → baris diabaikan run ini (tidak dianggap hilang).
- Baris hilang tanpa penanda → operasi `flag_missing` (catat ke tab Runs; tidak menulis).
- Baris baru → `create` (mengikuti mapping + defaults koneksi, format variasi identik
  dengan fixture batch: kolom `SKU Variasi`, `Harga Variasi`, `Stok Variasi`, dst).

---

## 5. State machine run

```
pending → fetching → diffing → [guard_fail → failed_fetch|failed_guard]
        → [threshold_exceeded → awaiting_approval → (token) → approved]
        → [ok → applying → done]
                  ↘ applying → partially_failed
rollback (hanya run terakhir, state done|partially_failed) → rolled_back
```

- `applying` memakai pola executor batch: chunk 10 operasi, heartbeat tiap chunk
  (kolom `finished_at` dipakai sebagai heartbeat), guard waktu (budget 240 dtk/run;
  sisa via resume — `bcs/sync-run` ulang pada run `applying` stale >10 menit melanjutkan
  tanpa mengulang baris yang sudah ok, idempotent via `_bcs_sync_hash`).
- Transisi atomik via `UPDATE … WHERE state = <expected>` — dua trigger bersamaan
  hanya satu yang menang.
- Konsumsi token approval SETELAH transisi `awaiting_approval → approved` sukses
  (pola MAJOR-2 lama).

---

## 6. Eksekusi, lock, dan konflik

### 6.1 Writer

Semua tulisan lewat `BCS_Catalog_Writer` (aturan proyek: satu-satunya penulis produk).
Ekstensi V1-sync yang wajib (keputusan #5):

1. `update_variation( $product_id, $variation_sku, $payload )` — update field
   harga/stok/SKU-variasi; **tidak** menghapus/insert term (atribut variasi immutable
   setelah create — ubah struktur = delete+create eksplisit).
2. Snapshot `before` per variasi untuk operasi update (rollback & layar diff),
   tersimpan di payload operasi seperti pola update produk.
3. `soft_delete_variation()` — variasi ikut trash bersama induk (perilaku WC saat
   parent di-trash sudah menangani; verifikasi di test).

### 6.2 Lock per koneksi

`GET_LOCK('bcs_sync_{connection_id}', 0)` MySQL named lock (atomik server-side,
tahan terhadap multi-process PHP-FPM/CLI; lebih kuat dari transient ala `doing_cron`
yang diakui core "attempts to be atomic"). Rilis di `finally`. Worker melihat lock
terisi → skip tanpa error (cron berikutnya yang menangani).

### 6.3 Konflik (policy `flag_only` — keputusan #3)

Sebelum menulis update: bila `post_modified > _bcs_synced_at` untuk produk tsb →
operasi jadi `conflict`, produk diberi `_bcs_conflict_flag`, sync TIDAK menulis,
entri audit `conflict`. Resolve manual di tab Conflicts (*Sheet wins* = terapkan
payload + hapus flag; *Keep WP* = perbarui `_bcs_sync_hash` dari nilai WP saat ini).

### 6.4 Ownership vs edit via MCP/agent

`bcs-build-plan` dan jalur batch menolak eksplisit operasi yang mengubah field milik
koneksi aktif pada baris ber-SKU ter-own: pesan menyebut connection id & field.
(Cek murah: SKU ada di `seen_skus` koneksi aktif → field `regular_price`,`stock_quantity`,
`sale_price`, field variasi → tolak `isError`.) Ini mencegah "conflict generator"
(penulis ganda) sebelum terjadi — kegagalan loud, bukan tertimpa senyap.

---

## 7. Kontrak sheet (kompatibel pipeline batch)

- Header & multi-value identik dengan fixture `sample-fashion-30.csv` (pipe `|` untuk
  opsi atribut, `Induk > Anak` kategori, kolom variasi berdampingan) — parser yang sama
  dipakai ulang, tanpa format kedua.
- Kolom tambahan khusus sync (opsional, di-mapping per koneksi):
  `sync_action` (`delete|hold`), `sync_note` (free text → audit).
- **Gambar (V1.1, gate1 #4 dibuka).** Nilai numerik di kolom `URL Gambar` tetap
  diperlakukan sebagai `attachment_id`. Sebuah URL hanya disideload bila setting
  `allow_sideload` menyala **dan** host-nya lolos `sideload_domain_whitelist`;
  selain itu dilewati dengan peringatan audit, bukan pembatalan run. Hanya `https`;
  IP literal dan host internal ditolak; ukuran dibatasi `max_attachment_bytes`,
  unduhan dibatasi `sideload_timeout`. URL sumber disimpan di `_bcs_sync_image_url`
  pada produk sehingga URL yang tidak berubah tidak pernah diunduh ulang, dan diff
  membandingkan URL sumber tersimpan itu — bukan URL attachment hasil unggah —
  supaya run yang berurutan tetap menghasilkan nol operasi.
- Baris dengan SKU kosong → error validasi (quarantine), tidak pernah auto-generate.
- Kunci: SKU produk; untuk variasi: `SKU Variasi`. Kolom kunci **read-only bagi editor
  sheet** (dokumentasi + proteksi sheet bila mau — pola FlexSync).

---

## 8. Keamanan

1. **Capability**: read tools `manage_woocommerce`; `sync-run`/`sync-approve-run`
   `manage_woocommerce`; layar admin view `edit_products`, aksi tulis
   `manage_woocommerce` + nonce. Defense-in-depth: cek ulang di service, bukan cuma
   transport (pola ability batch).
2. **Rate limit**: reuse mekanisme per-ability batch (default 60/jam untuk sync-run).
3. **Webhook V2** (non-goal V1, dicatat): HMAC-SHA256 + timestamp ±5 mnt + event id
   idempotent (pola Stripe, riset §2.2).
4. **SA key storage**: dienkripsi AES-256-CBC, kunci = `hash('sha256', AUTH_KEY . DB_PASSWORD)`
   (turunan secret instalasi, tidak disimpan di DB). `uninstall.php` menghapus tabel +
   meta + opsi sync.
5. **Tidak pernah** menulis attachment/term di luar whitelist writer yang sudah ada.
6. Audit `actor_via=sync|cron|mcp`; setiap aksi admin resolve konflict/quarantine
   tercatat `actor_via=wp_admin`.

---

## 9. Observability

- Tabel Runs = sumber kebenaran status; heartbeat koneksi: `last_ok_run_at` lebih tua
  dari 3× interval → admin notice + `error_state` setelah 2× gagal beruntun.
- Setiap abort membawa `error_code` spesifik (`fetch_not_csv`, `fetch_zero_rows`,
  `schema_drift`, `missing_rows_guard`, `threshold_run`, `threshold_delete`,
  `lock_busy`) → debugging satu kolom.
- `bcs/audit-log` filter `source=sync` untuk jejak baris.

---

## 10. Non-goals V1

1. Apps Script push / webhook (V2, fetcher ketiga).
2. Two-way sync, kolom read-back dari WP ke sheet.
3. Sync non-produk (user, order, coupon).
4. Multi-sheet / multi-tab dalam satu koneksi (satu koneksi = satu range).
5. Hard delete permanen (selalu trash).
6. Migrasi otomatis dari tool batch: batch pipeline tetap berdiri sendiri.

---

## 11. Acceptance criteria

| AC | Kriteria | Uji |
|---|---|---|
| S1 | Koneksi aktif dengan interval 1 mnt memantau sheet; edit 1 harga → run ≤2 mnt menerapkan update; audit `actor_via=sync` ber-snapshot before | E2E: ubah sel, tunggu, cek DB+audit |
| S2 | Sheet di-set privat → run abort `fetch_not_csv`/HTTP error; **0 produk berubah**; koneksi `error_state` + notice | E2E negatif |
| S3 | Sheet dikosongkan (semua baris dihapus) → abort `fetch_zero_rows`; 0 trash | E2E negatif |
| S4 | Filter aktif menyisakan 30% baris → abort `missing_rows_guard` | E2E negatif |
| S5 | Paste menimpa 400 baris (katalog 300) → `awaiting_approval`; apply hanya setelah token; tanpa token tidak pernah menulis | E2E gerbang |
| S6 | Harga satu baris diubah ×10 → baris quarantine, baris lain tetap apply | E2E unit |
| S7 | `sync_action=delete` pada 2 baris → 2 produk trash (recoverable); 30 baris delete → `awaiting_approval` | E2E |
| S8 | Baris dihapus tanpa penanda → hanya flag `flag_missing`, 0 tulisan | E2E |
| S9 | Edit manual WP lalu run sync → produk ber-flag konflik, nilai WP utuh; resolve *Sheet wins* menerapkan payload | E2E + UI |
| S10 | `bcs-build-plan` menyentuh harga SKU milik koneksi aktif → ditolak eksplisit dengan pesan connection id | E2E agent |
| S11 | Kill `wp bcs sync run` di tengah (SIGKILL) → rerun resume tanpa duplikat (`_bcs_sync_hash` idempotent); `GET_LOCK` tidak deadlock (lock auto-rilis saat koneksi mati) | Chaos |
| S12 | Rollback run terakhir → semua update/create run itu dipulihkan/trash; rollback run lama (ada run lebih baru) → ditolak | E2E |
| S13 | Update variasi: harga variasi di sheet → `_bcs_sync_hash` variasi berubah → hanya variasi itu di-update, snapshot before tercatat; hapus baris variasi → flag review, variasi tidak terhapus | E2E + unit |
| S14 | `sheets_api`: SA key salah → `fetch_*` error; key benar, sheet tidak di-share → error eksplisit; decrypt-encrypt key round-trip | Unit + E2E |
| S15 | Uninstall staging: tabel + meta + opsi sync hilang; produk tetap | Staging (pola AC-8 BCS) |

---

## 12. Urutan implementasi yang disarankan

1. Skema DB + fetcher interface + `csv_export` (dev loop cepat).
2. Diff engine + guard + state machine run (unit test padat — area paling berisiko).
3. Writer: `update_variation` + snapshot before variasi (unit).
4. Eksekusi chunked + lock + resume; WP-CLI; audit `source=sync`.
5. Threshold/approval + token generalisasi + layar Runs.
6. Layar Connections/Quarantine/Conflicts + notice.
7. `sheets_api` + enkripsi kredensial.
8. Ability MCP ×3 + update custom server (8→11 tools) + smoke test.
9. E2E suite `tests/e2e/sync-*.sh` (S1–S13) + chaos S11.

---

## 13. Sumber

`docs/research/gsheets-sync.md` (29 URL, termasuk catatan supervisi orchestrator).
Keputusan Gate-level: percakapan 2026-09-21 (transport, delete eksplisit, flag-only,
threshold 3 lapis, variasi wajib V1, jalur agent via gerbang).
