# Runbook Uji E2E — Bulk Catalog Studio (cf.djavaweb.com)

| | |
|---|---|
| **Versi runbook** | 1.0 (Fase C — disiapkan test engineer, dieksekusi orchestrator di Fase D) |
| **Target** | https://cf.djavaweb.com — webroot `/home/runcloud/webapps/cf` |
| **Stack** | WordPress 7.1.1 + WooCommerce + plugin `bulk-catalog-studio` 1.0.0 + MCP Adapter aktif, PHP CLI 7.4 |
| **Eksekutor** | Orchestrator via SSH. WP-CLI: `/usr/local/bin/wp` |
| **Prinsip** | Uji memakai MCP calls sungguhan (STDIO `wp mcp-adapter serve` / HTTP curl dua langkah) — BUKAN memanggil fungsi PHP langsung — agar jalur yang diuji identik dengan jalur Claude produksi |
| **Sumber pola** | `docs/research/abilities-mcp-api.md` §6 (ikut polanya persis) |
| **Fixture** | `plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv` — 30 baris: 20 simple (`KML-GRS-*`), 10 variable (`DRS-KTN-*`, 6 variasi/produk = 60 variasi), **1 duplikat intra-batch disengaja** (baris 15 = SKU `KML-GRS-005` ganda dengan baris 5) |

Urutan eksekusi wajib:

```
00-prereq → 01-smoke → 02-happy-path (berhenti di gerbang approval) → 03-verify-counts → 04-rollback
           → (manual) AC-7 deaktivasi → (staging terpisah) AC-8 uninstall → (manual) AC-9/11/12
```

---

## 0. Persiapan & environment

### 0.1 Kredensial — TIDAK di-hardcode

Skrip membaca dua jalur autentikasi:

| Jalur | Variabel | Keterangan |
|---|---|---|
| STDIO (default) | `BCS_CLI_USER` (default `admin`) | User WP lokal untuk `wp mcp-adapter serve --user=...`. Wajib punya `edit_products` + `manage_woocommerce` |
| HTTP (opsional) | `BCS_WP_USER` + `BCS_APP_PASSWORD` | Application Password user MCP; **isi via env var saat eksekusi, jangan pernah ditulis di file** |

Variabel lingkungan lain:

| Variabel | Default | Fungsi |
|---|---|---|
| `BCS_SITE_URL` | `https://cf.djavaweb.com` | Endpoint HTTP MCP |
| `BCS_WP_ROOT` | `/home/runcloud/webapps/cf` | Webroot untuk `wp --path` |
| `BCS_WP_BIN` | `/usr/local/bin/wp` | Biner WP-CLI |
| `BCS_MCP_SERVER` | `mcp-adapter-default-server` | ID server MCP Adapter |
| `BCS_TRANSPORT` | `stdio` | `stdio` atau `http` |
| `BCS_FIXTURE` | `<repo>/plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv` | CSV uji (unggah ke VPS bila perlu) |
| `BCS_STATE_FILE` | `/tmp/bcs-e2e-state.json` | State antar-skrip (job_id, plan_id, plan_hash, counts) |
| `BCS_APPROVAL_TOKEN` | (kosong) | Token dari Mas Rio / layar admin — prioritas tertinggi |
| `BCS_TOKEN_HELPER` | `wpcli` | `wpcli` = generate token via `wp eval` helper; `none` = paksa berhenti & tunggu token manusia |

### 0.2 Contoh eksekusi dari orchestrator (SSH)

```bash
cd /home/runcloud/webapps/cf/bcs-e2e   # atau lokasi repo yang di-unggah
bash tests/e2e/00-prereq.sh
BCS_APPROVAL_TOKEN='BCS-XXXXX' bash tests/e2e/02-happy-path.sh   # token dari Mas Rio
# ATAU, bila helper diizinkan:
bash tests/e2e/02-happy-path.sh                                   # pakai wp eval helper
```

Output tiap skrip: JSON Lines `{"ts","script","step","status","detail"}` per langkah + ringkasan
`{"script","summary","steps","failed","stopped"}`. Exit code: `0` = PASS, `1` = ada FAIL, `2` = STOP/abort.

### 0.3 Kondisi awal yang diharapkan

- `bulk-catalog-studio` dan `woocommerce` aktif; permalink **bukan** Plain.
- Endpoint `https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server` balas **401** tanpa auth.
- 3 tabel `wp_bcs_jobs`, `wp_bcs_plans`, `wp_bcs_audit` ada (aktivasi sukses).
- 8 ability `bcs/*` terdaftar (`wp eval 'var_dump(array_keys(wp_get_abilities()));'` memuatnya).

---

## 1. Pra-cek — `tests/e2e/00-prereq.sh` (read-only)

Jalankan dulu dan pastikan **semua PASS, tanpa STOP**:

```bash
bash tests/e2e/00-prereq.sh
```

Cek yang dilakukan: tooling (jq/curl) → WP reachable → plugin & WooCommerce aktif → permalink →
MCP server terdaftar → `bcs/parse-source` ada di registry → 3 tabel `bcs_*` → fixture ada →
HTTP 401 tanpa auth → (opsional) initialize HTTP menghasilkan `Mcp-Session-Id`.

**Manual jika perlu diagnosis:**

```bash
wp --path=/home/runcloud/webapps/cf plugin list --status=active
wp --path=/home/runcloud/webapps/cf option get permalink_structure
wp --path=/home/runcloud/webapps/cf mcp-adapter list --format=json | jq '.[].id'
wp --path=/home/runcloud/webapps/cf eval 'var_dump(wp_has_ability("bcs/parse-source"));'
```

**Abort:** ada langkah STOP → perbaiki dulu, JANGAN lanjut ke smoke.

---

## 2. Smoke test — `tests/e2e/01-smoke.sh` (read-only)

```bash
bash tests/e2e/01-smoke.sh
```

Yang diuji:

1. `tools/list` JSON-RPC balas valid (3 meta-tool adapter: discover/get-info/execute).
2. `tools/call` → `mcp-adapter-discover-abilities` menampilkan **tepat 8** ability `bcs/`:
   `parse-source, check-duplicates, build-plan, approve-plan, execute-batch, job-status, rollback-batch, audit-log`.
3. Bila `BCS_WP_USER`+`BCS_APP_PASSWORD` di-set: jalur HTTP (initialize → `Mcp-Session-Id` → discover)
   juga menampilkan 8 ability yang sama.

Pola manual (ikut §6 riset):

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' | \
  wp --path=/home/runcloud/webapps/cf mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

**Abort (keras):** jumlah ability ≠ 8 → STOP, jangan lanjut ke happy path — ability write yang
sebagian terdaftar berarti plugin half-loaded.

---

## 3. Happy path AC-1 — `tests/e2e/02-happy-path.sh`

```bash
bash tests/e2e/02-happy-path.sh
```

Skrip menjalankan alur §4 spec secara berurutan. Ringkasan tahap:

| # | Panggilan MCP | Input inti | Harapan |
|---|---|---|---|
| 0 | (`wp eval` seed, bukan MCP) | Buat produk existing SKU `KML-GRS-003` bila belum ada | bahan uji duplikat-vs-katalog (AC-10) |
| 1 | `bcs/parse-source` | `inline_csv` + isi fixture + `column_mapping` (Nama→name, …, Ukuran→attribute:pa_ukuran, Warna→attribute:pa_warna, SKU Variasi→variation_sku, …) | `status=parsed`, `job_id` baru, `total_rows=30` |
| 2 | `bcs/check-duplicates` | `{job_id, strategy:sku+title, on_duplicate:flag_only}` | `intra_batch_duplicates ≥ 1` (baris 15 vs 5), `existing_matches ≥ 1` (baris 3 vs seed) |
| 3 | `bcs/build-plan` | `{job_id, on_duplicate:skip, defaults:{status:draft, manage_stock:true, attribute_scope:global_pa}, image_policy:{mode:attachment_id_only}}` | `plan_id`, `plan_hash` (40 char), `summary`: create 18 simple + 10 variable, **60 variasi**, 2 skip |
| 4 | `bcs/execute-batch` **sebelum approval** | `{plan_id, dry_run:true}` | **DITOLAK** (AC-4); job tetap `planned` |
| 5 | `bcs/approve-plan` | `{plan_id, plan_hash, confirmation_token}` | `status=approved` |
| 6 | `bcs/execute-batch` dry run | `{plan_id, dry_run:true}` | `done`, `failed=0`, **0 produk tertulis** (cek SQL `_bcs_plan_id`) |
| 7 | `bcs/execute-batch` sungguhan | `{plan_id}` | `done`, `products_created=28`, `variations_created≥60`, `skipped=2`, `failed=0` |
| 8 | `bcs/job-status` | `{job_id}` | `done` + counter cocok (AC-1) |
| 9 | `bcs/audit-log` | `{job_id, level:row, limit:200}` | entri `create_product` ber-`product_id`, `actor_via=mcp` (AC-5) |

Skrip **idempoten + resumable**: state (`job_id`, `plan_id`, `plan_hash`, stage) disimpan di
`BCS_STATE_FILE`; rerun melanjutkan dari stage terakhir, tidak membuat job ganda.

### 3.1 GERBANG APPROVAL — titik berhenti wajib (baca sebelum eksekusi)

Token one-time **hanya tampil di layar admin WP** (`Products → Bulk Catalog Studio → Job → plan →
"Generate token approval"`, URL `diff_preview_url` ada di state file). Token kedaluwarsa 24 jam,
single-use, terikat `plan_hash`.

Saat skrip sampai di gerbang ini, ia berhenti di salah satu dari tiga jalur (urutan prioritas):

1. **`BCS_APPROVAL_TOKEN` env** — orchestrator meminta token dari **Mas Rio** (manual di layar
   admin), lalu rerun: `BCS_APPROVAL_TOKEN='BCS-XXXXX' bash tests/e2e/02-happy-path.sh`.
2. **Helper wp-cli (default)** — bila `BCS_TOKEN_HELPER=wpcli` (default), skrip membuat token via:
   ```bash
   wp --path=/home/runcloud/webapps/cf eval '
   $t = BCS_Approval_Token::generate( (int) $args[0], (string) $args[1] );
   echo is_wp_error( $t ) ? "ERR:" . $t->get_error_message() : $t;' -- <plan_id> '<plan_hash>'
   ```
   Helper ini setara "generate token di layar admin" (fungsinya sama persis) dan hanya untuk
   automasi uji; **buat produksi tetap pakai jalur manusia**.
3. **`BCS_TOKEN_HELPER=none`** — skrip berhenti `STOP approval-token` (exit 2) dengan instruksi;
   state tersimpan; rerun dengan token melanjutkan tanpa mengulang parse/plan.

**Abort:** approve gagal (`note` berisi alasan) → generate token baru, rerun (token lama single-use).

### 3.2 Hasil akhir yang diharapkan

- 28 produk draft baru (18 simple `KML-GRS-*`, 10 variable `DRS-KTN-*`), 60 variasi.
- Baris 3 & 15 di-skip (duplikat), produk seed `KML-GRS-003` (baris 5 setara) tetap utuh.
- Semua produk muncul di **Products** admin WP biasa dan editable tanpa AI (AC-2 — cek cepat:
  buka `DRS-KTN-001` di admin; `03-verify-counts.sh` juga memverifikasi via `WC_Product` CRUD).

---

## 4. Verifikasi jumlah — `tests/e2e/03-verify-counts.sh` (read-only)

```bash
bash tests/e2e/03-verify-counts.sh
```

Verifikasi ganda (dua sumber harus cocok):

```bash
# via wp post list
wp --path=/home/runcloud/webapps/cf post list --post_type=product --post_status=draft --format=count
# via SQL (produk/variasi bertag _bcs_job_id = job E2E)
wp --path=/home/runcloud/webapps/cf db query "SELECT COUNT(DISTINCT p.ID) FROM wp_posts p INNER JOIN wp_postmeta m ON m.post_id=p.ID WHERE p.post_type='product' AND p.post_status NOT IN ('trash') AND m.meta_key='_bcs_job_id' AND m.meta_value='<JOB_ID>';"
wp --path=/home/runcloud/webapps/cf db query "SELECT COUNT(*) FROM wp_posts v INNER JOIN wp_postmeta m ON m.post_id=v.ID WHERE v.post_type='product_variation' AND v.post_status NOT IN ('trash') AND m.meta_key='_bcs_job_id' AND m.meta_value='<JOB_ID>';"
```

Plus: SKU spot-check (`DRS-KTN-001/010`, `KML-GRS-001`), dedup intra-batch (`KML-GRS-005` hanya
1 produk), term `pa_ukuran` (S/M/L) & `pa_warna`, dan pembacaan native via `WC_Product` (AC-2).

---

## 5. Rollback — `tests/e2e/04-rollback.sh` (AC-6)

```bash
bash tests/e2e/04-rollback.sh
```

- `bcs/rollback-batch` `{job_id, confirm:true, scope:"all"}` → `status=rolled_back`;
  28 produk + 60 variasi → **trash** (soft, recoverable — bukan delete permanen).
- Verifikasi: SQL `post_status='trash'` ber-tag `_bcs_job_id` == jumlah buatan job; `wp post list
  --post_status=trash`; audit berisi entri `rolled_back` per baris; produk di luar job (seed)
  **tidak ikut** ter-trash.
- Idempoten: job `rolled_back` → skrip hanya verifikasi ulang.
- Catatan sub-kasus *update→restore snapshot before*: kebijakan happy path default `skip`, jadi
  verifikasi restore nilai `before` dilakukan terpisah (opsi QA: rerun alur dengan produk existing
  dan `on_duplicate: update` di build-plan, lalu rollback dan bandingkan 5 field dengan snapshot
  `before` di plan JSON — lihat §7 AC-6).

Setelah 04 lulus, katalog kembali bersih; **trash boleh dikosongkan manual oleh Mas Rio**.

---

## 6. AC-7 deaktivasi (di cf, reversible) & AC-8 uninstall (STAGING SAJA)

### 6.1 AC-7 — deaktivasi tanpa kehilangan data (BOLEH di cf)

```bash
wp --path=/home/runcloud/webapps/cf plugin deactivate bulk-catalog-studio
wp --path=/home/runcloud/webapps/cf db query "SHOW TABLES LIKE '%bcs_%';"          # 3 tabel masih ada
wp --path=/home/runcloud/webapps/cf option get bcs_db_version                       # masih ada
wp --path=/home/runcloud/webapps/cf post list --post_type=product --format=count    # produk utuh
wp --path=/home/runcloud/webapps/cf plugin activate bulk-catalog-studio
# job lama masih terbaca:
echo '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability":"bcs/job-status","input":{}}}}' | \
  wp --path=/home/runcloud/webapps/cf mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

Lulus bila: tidak ada data hilang; `bcs/job-status` masih membaca job E2E.

### 6.2 AC-8 — uninstall bersih (HANYA di staging terpisah — JANGAN di cf)

Siapkan staging (clone situs), lalu:

```bash
wp --path=/path/ke/staging plugin uninstall bulk-catalog-studio
wp --path=/path/ke/staging db query "SHOW TABLES LIKE '%bcs_%';"        # HARUS kosong
wp --path=/path/ke/staging option get bcs_db_version                    # HARUS kosong/error
wp --path=/path/ke/staging post list --post_type=product --format=count # produk TETAP ada (tidak terhapus)
```

Lulus bila: tabel `bcs_*` + options `bcs_*` hilang; produk/attachment tidak tersentuh.

---

## 7. Matriks AC-1..AC-12 → cara uji konkret

| AC | Kriteria (spec §6) | Cara uji konkret | Lokasi |
|---|---|---|---|
| AC-1 | ≥30 produk + variasi dari 1 CSV | `02-happy-path.sh` (alur MCP penuh) + `03-verify-counts.sh` (`job-status=done`, DB `_bcs_job_id`=28 produk/60 variasi, `wp post list`) | otomatis |
| AC-2 | Produk 100% native & editable | `03-verify-counts.sh` langkah `ac2-native-wc` (WC_Product CRUD) + buka 3 sampel di admin WP biasa, edit & publish manual | otomatis + manual |
| AC-3 | Preview sebelum apply | Buka `diff_preview_url` dari state file (`jq .diff_preview_url /tmp/bcs-e2e-state.json`); layar menampilkan create/update/skip + before/after + tombol token | manual (admin) |
| AC-4 | Approval manual wajib | `02-happy-path.sh` langkah `execute-before-approve-rejected` (execute pada plan `planned` ditolak); salah token → `approve-plan` gagal tercatat; token kedua kali → rerun approve menghasilkan error | otomatis |
| AC-5 | Log perubahan | `02-happy-path.sh` langkah `audit-log` + `04-rollback.sh` langkah `audit-rolled-back` | otomatis |
| AC-6 | Rollback | `04-rollback.sh` (trash 28+60, 0 produk hidup, seed tak tersentuh); sub-kasus update→restore: jalankan alur varian `on_duplicate: update` di staging, rollback, bandingkan 5 field dengan `before` di `wp_bcs_plans.plan_json` | otomatis + varian staging |
| AC-7 | Deaktivasi tanpa kehilangan data | §6.1 runbook (deactivate → cek tabel/option/produk → activate → `bcs/job-status`) | manual wp-cli |
| AC-8 | Uninstall bersih | §6.2 runbook di **staging**: `wp plugin uninstall` → tabel/options hilang, produk tetap | manual, staging |
| AC-9 | Tulis ber-permission + audit | Dengan `--user=<editor tanpa manage_woocommerce>`: `echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability":"bcs/execute-batch","input":{"plan_id":<plan>}}}}' \| wp mcp-adapter serve --user=editor …` → harus ditolak; 8 ability dengan `--user=admin` → lolos + entri audit (`actor_via=mcp`) | manual wp-cli |
| AC-10 | Deteksi duplikasi | `02-happy-path.sh` langkah `check-duplicates` (1 intra + 1 existing) + `03-verify-counts.sh` langkah `ac10-intra-dedup` (SKU ganda jadi 1 produk) | otomatis |
| AC-11 | Idempotency & resume | Di staging: `timeout 20 bash -c 'echo <execute-batch JSON> \| wp mcp-adapter serve --user=admin …'` (kill di tengah) → tunggu >5 menit → panggil ulang `{plan_id, resume:true}` → tidak ada SKU ganda (cek SQL `_sku` GROUP BY HAVING >1), job berakhir `done` | manual, staging |
| AC-12 | Batas & scope | Staging: CSV 600 baris → `parse-source` ditolak (`max_rows_per_job` 500); input `{post_id: 999}` / field liar → ditolak schema (`additionalProperties:false`); verifikasi tak ada tabel non-produk tersentuh via audit + `SHOW TABLES` | manual, staging |

Catatan AC-4 token kedua kali: setelah approve sukses, rerun `approve-plan` dengan token yang sama
harus ditolak (`token_used_at` terisi) — cukup satu panggilan manual `bcs/approve-plan`.

---

## 8. Risiko & abort criteria (batalkan-jika)

### 8.1 Abort criteria keras — STOP SEGERA, jangan lanjut

| # | Kondisi | Reaksi |
|---|---|---|
| A1 | `discover-abilities` ≠ 8 ability `bcs/` | **STOP sebelum write apa pun.** Cek `wp eval 'wp_has_ability(...)'`, log debug plugin |
| A2 | Plugin / WooCommerce inactive, permalink Plain, tabel `bcs_*` hilang | STOP; perbaiki pra-syarat (00-prereq) |
| A3 | `parse-source` error / `total_rows ≠ 30` | STOP; belum ada efek katalog; review `warnings`/`errors` |
| A4 | `check-duplicates`: intra=0 atau existing=0 | STOP; AC-10 tidak terverifikasi — periksa seed & fixture |
| A5 | `build-plan`: variasi < 60 / create ≠ 28 / skip ≠ 2 | STOP; plan tidak sesuai fixture — jangan approve |
| A6 | `execute-batch` sebelum approval TIDAK ditolak | **STOP — pelanggaran keamanan gerbang (AC-4).** Laporkan, jangan eksekusi apa pun |
| A7 | dry_run: `failed > 0` atau DB ternyata tertulis | STOP; JANGAN jalankan eksekusi nyata; periksa plan/executor |
| A8 | Eksekusi nyata `partially_failed`/`failed` | Jalankan `04-rollback.sh` segera; analisis `failures[]` sebelum rerun |
| A9 | Approve gagal 2× berturut-turut | STOP; minta token baru ke Mas Rio; cek `plan_hash` di state vs layar admin |
| A10 | Rollback `failed > 0` | STOP; produk tertinggal — inventory manual via `_bcs_job_id` sebelum tindakan lanjut |

### 8.2 Risiko & mitigasi

| Risiko | Mitigasi |
|---|---|
| Job/plan ganda karena rerun | Semua skrip pakai state file; `parse-source` hanya dipanggil bila `job_id` kosong |
| Kredensial bocor di skrip/log | Application Password hanya via env `BCS_WP_USER`/`BCS_APP_PASSWORD`; skrip tidak pernah mencetaknya |
| Rate limit 60 panggilan/menit | Satu alur ≈ 10–12 panggilan; bila kena, tunggu 60 detik lalu rerun (resumable) |
| Uninstall tidak sengaja di cf | AC-8 **hanya** di §6.2 staging; tidak ada skrip yang meng-uninstall — semua 00–04 read-only/write-produk saja |
| Sinkron data uji dengan katalog asli | Semua produk hasil job bertag `_bcs_job_id` dan berstatus draft; rollback memindah ke trash sehingga tidak pernah tampil di storefront |
| Transport HTTP gagal (`-32600` Missing Mcp-Session-Id) | Pastikan initialize dulu lalu bawa header session (pola §6.2 riset sudah diikuti `lib.sh`) |
| Sesi MCP HTTP idle > 24 jam | Sesi dibuat ulang otomatis per invokasi skrip (`bcs_http_session` saat session kosong) |
| PHP CLI 7.4 (VPS) vs sintaks skrip | Skrip bash murni + `jq`/`curl`; `wp eval` hanya memakai sintaks PHP 7.4-compatible |

### 8.3 Artefak yang dihasilkan eksekusi

- `/tmp/bcs-e2e-state.json` — job_id, plan_id, plan_hash, summary, counts, stage, diff_preview_url.
- `/tmp/bcs-e2e-results-<skrip>.jsonl` — jejak PASS/FAIL/STOP per langkah (lampirkan ke laporan QA).
