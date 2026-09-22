# Runbook Uji E2E — Bulk Catalog Studio (cf.djavaweb.com)

| | |
|---|---|
| **Versi runbook** | 3.0 — batch BCS + BCS Sync Fase 8–9; disiapkan test engineer, dieksekusi orchestrator |
| **Target** | https://cf.djavaweb.com — webroot `/home/runcloud/webapps/cf` |
| **Custom MCP server** | **`https://cf.djavaweb.com/wp-json/bcs-mcp/mcp`** — `serverInfo.name` = `Bulk Catalog Studio MCP` |
| **Tools** | `tools/list` mengembalikan **tepat 11 tool individual**: 8 tool batch + `bcs-sync-list-connections`, `bcs-sync-run`, `bcs-sync-approve-run` |
| **Invocation** | `tools/call` **langsung** ke nama tool individual dengan `arguments` sesuai input schema ability — custom server TIDAK memiliki meta-tool `mcp-adapter-discover-abilities` / `mcp-adapter-execute-ability` |
| **Transport** | **HANYA HTTP** dengan Application Password (dua langkah: `initialize` → tangkap `Mcp-Session-Id` → request berikutnya membawa header session). STDIO tidak dipakai untuk alur custom |
| **Stack** | WordPress 7.1.1 + WooCommerce + plugin `bulk-catalog-studio` 1.0.0 + plugin `mcp-adapter`, PHP CLI 7.4 |
| **Eksekutor** | Orchestrator via SSH. WP-CLI: `/usr/local/bin/wp` (hanya untuk pra-cek & verifikasi DB — bukan jalur MCP) |
| **Fixture** | `plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv` — 30 baris: 20 simple (`KML-GRS-*`), 10 variable (`DRS-KTN-*`, 6 variasi/produk = 60 variasi), **1 duplikat intra-batch disengaja** (baris 15 = SKU `KML-GRS-005` ganda dengan baris 5) |

Urutan eksekusi wajib:

```
00-prereq → 01-smoke → 02-happy-path (berhenti di gerbang approval) → 03-verify-counts → 04-rollback
           → (manual) AC-7 deaktivasi → (staging terpisah) AC-8 uninstall → (manual) AC-9/11/12
```

---

## 0. Persiapan & environment

### 0.1 Kredensial — TIDAK di-hardcode

Custom server memakai HTTP + Application Password. Semua skrip **wajib** dijalankan dengan:

| Variabel | Keterangan |
|---|---|
| `BCS_WP_USER` | Login WP pemilik Application Password (punya `edit_products` + `manage_woocommerce`) |
| `BCS_APP_PASSWORD` | Application Password (format `xxxx xxxx xxxx xxxx xxxx xxxx`) |

Isi via env var saat eksekusi — **jangan pernah ditulis di file, jangan di-commit**.

Variabel lingkungan lain:

| Variabel | Default | Fungsi |
|---|---|---|
| `BCS_MCP_ENDPOINT` | `https://cf.djavaweb.com/wp-json/bcs-mcp/mcp` | Endpoint custom server |
| `BCS_MCP_SERVER_NAME` | `Bulk Catalog Studio MCP` | Nilai `serverInfo.name` yang diharapkan (dicek 01-smoke) |
| `BCS_TRANSPORT` | `http` | Harus `http` (custom server tidak mendukung STDIO; `stdio` hanya helper generik lama di lib.sh dan akan di-STOP) |
| `BCS_WP_ROOT` | `/home/runcloud/webapps/cf` | Webroot untuk pra-cek `wp --path` |
| `BCS_WP_BIN` | `/usr/local/bin/wp` | Biner WP-CLI |
| `BCS_FIXTURE` | `<repo>/plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv` | CSV uji (unggah ke VPS bila perlu) |
| `BCS_STATE_FILE` | `/tmp/bcs-e2e-state.json` | State antar-skrip (job_id, plan_id, plan_hash, counts) |
| `BCS_APPROVAL_TOKEN` | (kosong) | Token dari Mas Rio / layar admin — prioritas tertinggi |
| `BCS_TOKEN_HELPER` | `wpcli` | `wpcli` = generate token via `wp eval` helper; `none` = paksa berhenti & tunggu token manusia |

### 0.2 Contoh eksekusi dari orchestrator (SSH)

```bash
cd /home/runcloud/webapps/cf/bcs-e2e   # atau lokasi repo yang di-unggah
export BCS_WP_USER='mcp-catalog'
export BCS_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'   # Application Password

bash tests/e2e/00-prereq.sh
bash tests/e2e/01-smoke.sh
BCS_APPROVAL_TOKEN='BCS-XXXXX' bash tests/e2e/02-happy-path.sh   # token dari Mas Rio
# ATAU, bila helper diizinkan:
bash tests/e2e/02-happy-path.sh                                   # pakai wp eval helper
bash tests/e2e/03-verify-counts.sh
bash tests/e2e/04-rollback.sh
```

Output tiap skrip: JSON Lines `{"ts","script","step","status","detail"}` per langkah + ringkasan
`{"script","summary","steps","failed","stopped"}`. Exit code: `0` = PASS, `1` = ada FAIL, `2` = STOP/abort.

### 0.3 Pola panggilan manual ke custom server (dua langkah, ikuti persis)

```bash
# Langkah 1 — initialize; tangkap Mcp-Session-Id dari response header
curl -s -D - -o /tmp/bcs-init.json -X POST "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp" \
  --user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"bcs-e2e","version":"1.0.0"}}}'
# → serverInfo.name di /tmp/bcs-init.json harus "Bulk Catalog Studio MCP"

SESSION=$(awk 'tolower($1)=="mcp-session-id:" {print $2}' /tmp/bcs-init-header 2>/dev/null)

# Langkah 2 — semua request wajib membawa header Mcp-Session-Id; tools/call LANGSUNG:
curl -s -X POST "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp" \
  --user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

curl -s -X POST "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp" \
  --user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"bcs-job-status","arguments":{}}}'
```

Tanpa header session → error JSON-RPC `-32600` "Missing Mcp-Session-Id header".

### 0.4 Kondisi awal yang diharapkan

- Plugin `bulk-catalog-studio`, `woocommerce`, **dan `mcp-adapter`** aktif; permalink **bukan** Plain.
- Endpoint `https://cf.djavaweb.com/wp-json/bcs-mcp/mcp` balas **401** tanpa auth.
- 5 tabel `wp_bcs_jobs`, `wp_bcs_plans`, `wp_bcs_audit`, `wp_bcs_connections`, `wp_bcs_sync_runs` ada (aktivasi sukses).
- `wp eval 'var_dump(wp_has_ability("bcs/parse-source"));'` → true (sumber registrasi 11 tool custom server).

---

## 1. Pra-cek — `tests/e2e/00-prereq.sh` (read-only)

Jalankan dulu dan pastikan **semua PASS, tanpa STOP**:

```bash
bash tests/e2e/00-prereq.sh
```

Cek yang dilakukan: tooling (jq/curl) → transport `http` → kredensial env ter-set → WP reachable →
plugin **mcp-adapter + woocommerce + bulk-catalog-studio** aktif → permalink →
`bcs/parse-source` terdaftar di registry → 5 tabel `bcs_*` → fixture ada →
endpoint custom balas 401 tanpa auth → initialize menghasilkan `Mcp-Session-Id`.

**Manual jika perlu diagnosis:**

```bash
wp --path=/home/runcloud/webapps/cf plugin list --status=active
wp --path=/home/runcloud/webapps/cf option get permalink_structure
wp --path=/home/runcloud/webapps/cf eval 'var_dump(wp_has_ability("bcs/parse-source"));'
```

**Abort:** ada langkah STOP → perbaiki dulu, JANGAN lanjut ke smoke.

---

## 2. Smoke test — `tests/e2e/01-smoke.sh` (read-only)

```bash
bash tests/e2e/01-smoke.sh
```

Yang diuji:

1. `initialize` → `Mcp-Session-Id` didapat dan **`serverInfo.name` = `Bulk Catalog Studio MCP`** (persis).
2. `tools/list` (dengan header session) → **tepat 11 tool individual** dan nama lengkap:
   8 tool batch (`bcs-parse-source, bcs-check-duplicates, bcs-build-plan, bcs-approve-plan, bcs-execute-batch, bcs-job-status, bcs-rollback-batch, bcs-audit-log`) plus `bcs-sync-list-connections, bcs-sync-run, bcs-sync-approve-run`.
3. NEGATIF: **tidak ada** meta-tool `mcp-adapter-discover-abilities` / `mcp-adapter-get-ability-info` /
   `mcp-adapter-execute-ability` di custom server (invocation memang tools/call langsung).
4. NEGATIF: tidak ada nama tool ber-slash (`bcs/…`) — custom server memakai nama dash.

**Abort (keras):** `serverInfo.name` salah, jumlah tool ≠ 11, nama tidak lengkap, atau meta-tool
ditemukan → STOP (kemungkinan mengarah ke server default adapter, bukan `bcs-mcp`); jangan lanjut
happy path.

---

## 3. Happy path AC-1 — `tests/e2e/02-happy-path.sh`

```bash
bash tests/e2e/02-happy-path.sh
```

Semua panggilan = `tools/call` langsung ke tool individual (tanpa meta-tool). Urutan tahap (spec §4):

| # | Tool | Input inti | Harapan |
|---|---|---|---|
| 0 | (`wp eval` seed, bukan MCP) | Buat produk existing SKU `KML-GRS-003` bila belum ada | bahan uji duplikat-vs-katalog (AC-10) |
| 1 | `bcs-parse-source` | `{"source_type":"inline_csv","csv_text":<isi fixture>,"column_mapping":{Nama→name, …, Ukuran→attribute:pa_ukuran, Warna→attribute:pa_warna, "SKU Variasi"→variation_sku, …}}` | `status=parsed`, `job_id` baru, `total_rows=30` |
| 2 | `bcs-check-duplicates` | `{job_id, strategy:sku+title, on_duplicate:flag_only}` | `intra_batch_duplicates ≥ 1` (baris 15 vs 5), `existing_matches ≥ 1` (baris 3 vs seed) |
| 3 | `bcs-build-plan` | `{job_id, on_duplicate:skip, defaults:{status:draft, manage_stock:true, attribute_scope:global_pa}, image_policy:{mode:attachment_id_only}}` | `plan_id`, `plan_hash` (40 char), `summary`: create 18 simple + 10 variable, **60 variasi**, 2 skip |
| 4 | `bcs-execute-batch` **sebelum approval** | `{plan_id, dry_run:true}` | **DITOLAK** (AC-4); job tetap `planned` |
| 5 | `bcs-approve-plan` | `{plan_id, plan_hash, confirmation_token}` | `status=approved` |
| 6 | `bcs-execute-batch` dry run | `{plan_id, dry_run:true}` | `done`, `failed=0`, **0 produk tertulis** (cek SQL `_bcs_plan_id`) |
| 7 | `bcs-execute-batch` sungguhan | `{plan_id}` | `done`, `products_created=28`, `variations_created≥60`, `skipped=2`, `failed=0` |
| 8 | `bcs-job-status` | `{job_id}` | `done` + counter cocok (AC-1) |
| 9 | `bcs-audit-log` | `{job_id, level:row, limit:200}` | entri `create_product` ber-`product_id`, `actor_via=mcp` (AC-5) |

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
# via tools/call langsung ke custom server (lihat pola §0.3, tool bcs-job-status)
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

- `tools/call` → `bcs-rollback-batch` `{job_id, confirm:true, scope:"all"}` → `status=rolled_back`;
  28 produk + 60 variasi → **trash** (soft, recoverable — bukan delete permanen).
- Verifikasi: SQL `post_status='trash'` ber-tag `_bcs_job_id` == jumlah buatan job; `wp post list
  --post_status=trash`; audit berisi entri `rolled_back` per baris; produk di luar job (seed)
  **tidak ikut** ter-trash.
- Idempoten: job `rolled_back` → skrip hanya verifikasi ulang.
- Catatan sub-kasus *update→restore snapshot before*: kebijakan happy path default `skip`, jadi
  verifikasi restore nilai `before` dilakukan terpisah (opsi QA: rerun alur dengan produk existing
  dan `on_duplicate: update` di `bcs-build-plan`, lalu rollback dan bandingkan 5 field dengan
  snapshot `before` di plan JSON — lihat §7 AC-6).

Setelah 04 lulus, katalog kembali bersih; **trash boleh dikosongkan manual oleh Mas Rio**.

---

## 6. AC-7 deaktivasi (di cf, reversible) & AC-8 uninstall (STAGING SAJA)

### 6.1 AC-7 — deaktivasi tanpa kehilangan data (BOLEH di cf)

```bash
wp --path=/home/runcloud/webapps/cf plugin deactivate bulk-catalog-studio
wp --path=/home/runcloud/webapps/cf db query "SHOW TABLES LIKE '%bcs_%';"          # 5 tabel masih ada
wp --path=/home/runcloud/webapps/cf option get bcs_db_version                       # masih ada
wp --path=/home/runcloud/webapps/cf post list --post_type=product --format=count    # produk utuh
wp --path=/home/runcloud/webapps/cf plugin activate bulk-catalog-studio
# job lama masih terbaca via tools/call langsung ke custom server:
curl -s -X POST "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp" \
  --user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
  -H "Content-Type: application/json" -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"bcs-job-status","arguments":{}}}'
```

Lulus bila: tidak ada data hilang; `bcs-job-status` masih membaca job E2E.

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
| AC-1 | ≥30 produk + variasi dari 1 CSV | `02-happy-path.sh` (tools/call langsung penuh) + `03-verify-counts.sh` (`bcs-job-status=done`, DB `_bcs_job_id`=28 produk/60 variasi, `wp post list`) | otomatis |
| AC-2 | Produk 100% native & editable | `03-verify-counts.sh` langkah `ac2-native-wc` (WC_Product CRUD) + buka 3 sampel di admin WP biasa, edit & publish manual | otomatis + manual |
| AC-3 | Preview sebelum apply | Buka `diff_preview_url` dari state file (`jq .diff_preview_url /tmp/bcs-e2e-state.json`); layar menampilkan create/update/skip + before/after + tombol token | manual (admin) |
| AC-4 | Approval manual wajib | `02-happy-path.sh` langkah `execute-before-approve-rejected` (`bcs-execute-batch` pada plan `planned` ditolak); salah token → `bcs-approve-plan` gagal tercatat; token kedua kali → rerun approve menghasilkan error | otomatis |
| AC-5 | Log perubahan | `02-happy-path.sh` langkah `audit-log` (`bcs-audit-log`) + `04-rollback.sh` langkah `audit-rolled-back` | otomatis |
| AC-6 | Rollback | `04-rollback.sh` (`bcs-rollback-batch`; trash 28+60, 0 produk hidup, seed tak tersentuh); sub-kasus update→restore: jalankan alur varian `on_duplicate: update` di staging, rollback, bandingkan 5 field dengan `before` di `wp_bcs_plans.plan_json` | otomatis + varian staging |
| AC-7 | Deaktivasi tanpa kehilangan data | §6.1 runbook (deactivate → cek tabel/option/produk → activate → `bcs-job-status`) | manual wp-cli + curl |
| AC-8 | Uninstall bersih | §6.2 runbook di **staging**: `wp plugin uninstall` → tabel/options hilang, produk tetap | manual, staging |
| AC-9 | Tulis ber-permission + audit | Dengan Application Password user editor (tanpa `manage_woocommerce`): `tools/call` → `bcs-execute-batch` → harus ditolak; 11 tool dengan kredensial `mcp-catalog` → lolos + entri audit (`actor_via=mcp`) | manual curl |
| AC-10 | Deteksi duplikasi | `02-happy-path.sh` langkah `check-duplicates` (`bcs-check-duplicates`; 1 intra + 1 existing) + `03-verify-counts.sh` langkah `ac10-intra-dedup` (SKU ganda jadi 1 produk) | otomatis |
| AC-11 | Idempotency & resume | Di staging: panggil `bcs-execute-batch` via curl dengan `--max-time 20` (kill di tengah) → tunggu >5 menit → panggil ulang `{plan_id, resume:true}` → tidak ada SKU ganda (SQL `_sku` GROUP BY HAVING >1), job berakhir `done` | manual, staging |
| AC-12 | Batas & scope | Staging: CSV 600 baris → `bcs-parse-source` ditolak (`max_rows_per_job` 500); input `{post_id: 999}` / field liar → ditolak schema (`additionalProperties:false`); verifikasi tak ada tabel non-produk tersentuh via audit + `SHOW TABLES` | manual, staging |

Catatan AC-4 token kedua kali: setelah approve sukses, panggil ulang `bcs-approve-plan` dengan
token yang sama harus ditolak (`token_used_at` terisi) — cukup satu panggilan curl manual.

---

## 8. Risiko & abort criteria (batalkan-jika)

### 8.1 Abort criteria keras — STOP SEGERA, jangan lanjut

| # | Kondisi | Reaksi |
|---|---|---|
| A0 | `serverInfo.name` ≠ `Bulk Catalog Studio MCP`, jumlah tool ≠ 11, nama tidak lengkap, atau meta-tool adapter muncul di tools/list | **STOP.** Endpoint salah / server belum ter-deploy dengan benar — jangan jalankan tool write |
| A1 | `bcs-parse-source`/`bcs-sync-run` dkk. tidak responsif / error persisten di custom server | Cek plugin mcp-adapter + bulk-catalog-studio aktif; cek log PHP; STOP bila tidak pulih |
| A2 | Plugin mcp-adapter / WooCommerce / bulk-catalog-studio inactive, permalink Plain, tabel `bcs_*` hilang | STOP; perbaiki pra-syarat (00-prereq) |
| A3 | `bcs-parse-source` error / `total_rows ≠ 30` | STOP; belum ada efek katalog; review `warnings`/`errors` |
| A4 | `bcs-check-duplicates`: intra=0 atau existing=0 | STOP; AC-10 tidak terverifikasi — periksa seed & fixture |
| A5 | `bcs-build-plan`: variasi < 60 / create ≠ 28 / skip ≠ 2 | STOP; plan tidak sesuai fixture — jangan approve |
| A6 | `bcs-execute-batch` sebelum approval TIDAK ditolak | **STOP — pelanggaran keamanan gerbang (AC-4).** Laporkan, jangan eksekusi apa pun |
| A7 | dry_run: `failed > 0` atau DB ternyata tertulis | STOP; JANGAN jalankan eksekusi nyata; periksa plan/executor |
| A8 | Eksekusi nyata `partially_failed`/`failed` | Jalankan `04-rollback.sh` segera; analisis `failures[]` sebelum rerun |
| A9 | Approve gagal 2× berturut-turut | STOP; minta token baru ke Mas Rio; cek `plan_hash` di state vs layar admin |
| A10 | Rollback `failed > 0` | STOP; produk tertinggal — inventory manual via `_bcs_job_id` sebelum tindakan lanjut |
| A11 | JSON-RPC `-32600` "Missing Mcp-Session-Id header" | Session hilang/kadaluarsa — rerun skrip (session dibuat ulang otomatis per run) |

### 8.2 Risiko & mitigasi

| Risiko | Mitigasi |
|---|---|
| Job/plan ganda karena rerun | Semua skrip pakai state file; `bcs-parse-source` hanya dipanggil bila `job_id` kosong |
| Kredensial bocor di skrip/log | Application Password hanya via env `BCS_WP_USER`/`BCS_APP_PASSWORD`; skrip tidak pernah mencetaknya |
| Endpoint yang salah (server default adapter, bukan bcs-mcp) | 01-smoke memvalidasi `serverInfo.name` + tepat 11 tool individual + ketiadaan meta-tool sebelum alur write |
| Rate limit 60 panggilan/menit | Satu alur ≈ 10–12 panggilan; bila kena, tunggu 60 detik lalu rerun (resumable) |
| Sesi MCP HTTP idle/kadaluarsa | Sesi dibuat ulang otomatis per run skrip (`bcs_http_session` saat session kosong); timeout request 300 s |
| Uninstall tidak sengaja di cf | AC-8 **hanya** di §6.2 staging; tidak ada skrip yang meng-uninstall — semua 00–04 read-only/write-produk saja |
| Sinkron data uji dengan katalog asli | Semua produk hasil job bertag `_bcs_job_id` dan berstatus draft; rollback memindah ke trash sehingga tidak pernah tampil di storefront |
| Helper STDIO terpakai tanpa sengaja | `BCS_TRANSPORT` default `http`; nilai lain di-STOP oleh `bcs_require_http` di semua skrip alur |
| PHP CLI 7.4 (VPS) vs sintaks skrip | Skrip bash murni + `jq`/`curl`; `wp eval` hanya memakai sintaks PHP 7.4-compatible |

### 8.3 Artefak yang dihasilkan eksekusi

- `/tmp/bcs-e2e-state.json` — job_id, plan_id, plan_hash, summary, counts, stage, diff_preview_url.
- `/tmp/bcs-e2e-results-<skrip>.jsonl` — jejak PASS/FAIL/STOP per langkah (lampirkan ke laporan QA).
- `/tmp/bcs-e2e-init.json` — body response initialize (bukti `serverInfo.name`).

---

# 9. Runbook BCS Sync Fase 8–9 (CSV + Sheets API)

Bagian ini adalah alur baru yang terpisah dari batch E2E pada bagian 1–8. Enam skrip
`sync-00-prereq.sh` sampai `sync-05-agent-gate.sh` bersifat **resumable** dan menyimpan
state ke `/tmp/bcs-sync-e2e-state.json`. Skrip tidak dijalankan selama development ini;
runbook ini menjadi instruksi eksekusi untuk orchestrator pada environment yang sudah
disiapkan.

## 9.1 Urutan dan cakupan

```text
sync-00-prereq
  → sync-01-csv-connection (fetch, threshold, approval, apply)
  → sync-02-guards (invalid/empty/missing/price/delete)
  → sync-03-conflict-rollback (S9 + S12)
  → sync-04-chaos (S11 SIGKILL/resume/idempotency)
  → sync-05-agent-gate (S10 tools/call build-plan rejection)
```

Semua skrip menulis JSONL ke `/tmp/bcs-sync-e2e-results-<skrip>.jsonl`. Exit code `0`
berarti PASS, `1` berarti FAIL, dan `2` berarti STOP/abort. Jalankan ulang skrip yang
berhenti setelah penyebabnya diperbaiki; jangan menghapus state kecuali memang ingin
memulai fixture baru.

## 9.2 Environment wajib

```bash
export BCS_WP_USER='user-application-password'
export BCS_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
export BCS_WP_ROOT='/home/runcloud/webapps/cf'
export BCS_WP_BIN='/usr/local/bin/wp'
export BCS_MCP_ENDPOINT='https://cf.djavaweb.com/wp-json/bcs-mcp/mcp'
export BCS_TRANSPORT=http

# URL CSV export terkontrol; setiap URL harus sudah dapat diakses worker.
export BCS_SYNC_CONN_CSV_URL='https://.../export?format=csv&gid=0'
export BCS_SYNC_CONN_EMPTY_URL='https://...'
export BCS_SYNC_CONN_MISSING_URL='https://...'
export BCS_SYNC_CONN_PRICE_URL='https://...'
export BCS_SYNC_CONN_DELETE_URL='https://...'
export BCS_SYNC_CONN_ROLLBACK_URL='https://...'
export BCS_SYNC_CONN_CHAOS_URL='https://...'

# Opsional; default semua GID = 0.
export BCS_SYNC_CONN_CSV_GID=0
export BCS_SYNC_CONN_EMPTY_GID=0
export BCS_SYNC_CONN_MISSING_GID=0
export BCS_SYNC_CONN_PRICE_GID=0
export BCS_SYNC_CONN_DELETE_GID=0
export BCS_SYNC_CONN_ROLLBACK_GID=0
export BCS_SYNC_CONN_CHAOS_GID=0

# SKU yang diedit manual sync-03 untuk memancing konflik. Wajib ada di fixture
# main DAN rollback. Default skrip kini KML-GRS-001; ubah hanya bila fixture
# diganti.
export BCS_SYNC_CONFLICT_SKU='KML-GRS-001'
export BCS_SYNC_CONFLICT_PRICE='120'
```

Nilai GID dan URL untuk fixture Google Sheets tidak perlu disusun tangan.
`gen-sync-env.sh` memverifikasi keenam tab sel-per-sel lawan fixture publik di
`https://cf.djavaweb.com/bcs-e2e/` lalu menulis blok `BCS_SYNC_CONN_*` di atas;
ia menolak menulis env bila ada satu tab saja yang tidak cocok. Perbandingannya
harus sampai ke level sel karena `price` dan `delete` punya jumlah baris yang
sama persis dengan `main`, dan angka dibandingkan sebagai nilai — Sheets menulis
ulang `0.30` menjadi `0.3` saat ekspor, dan itu bukan cacat fixture.

Jangan menaruh Application Password, service-account JSON, private key, atau URL
bertoken ke file skrip/state/commit. Kredensial Sheets API untuk unit test lokal
boleh disediakan melalui environment/file sementara yang tidak di-commit.

## 9.3 Pra-syarat dan eksekusi

```bash
bash tests/e2e/sync-00-prereq.sh
bash tests/e2e/sync-01-csv-connection.sh
bash tests/e2e/sync-02-guards.sh
bash tests/e2e/sync-03-conflict-rollback.sh
bash tests/e2e/sync-04-chaos.sh
bash tests/e2e/sync-05-agent-gate.sh
```

`sync-00` harus memastikan plugin aktif, dua tabel sync, `bcs_db_version >= 1.2.0`,
WP-CLI `bcs sync`, initialize MCP, dan tepat **11 nama tool dash**. Semua fixture URL
harus non-kosong. Jika ada STOP, jangan lanjut ke skrip berikutnya.

`sync-01` membuat atau menggunakan ulang koneksi `csv_export`, menjalankan
`test-connection`, lalu memastikan run pertama berhenti di `awaiting_approval` tanpa
menulis produk. Token approval didapat dari layar Sync Runs/admin atau helper eksplisit
untuk test:

```bash
BCS_SYNC_TOKEN_HELPER=none bash tests/e2e/sync-01-csv-connection.sh
# Setelah token manusia tersedia:
BCS_SYNC_APPROVAL_TOKEN='BCS-XXXXX' bash tests/e2e/sync-01-csv-connection.sh
```

Helper default `wpcli` hanya boleh digunakan untuk automasi environment uji. Token
plaintext tidak disimpan ke state.

`sync-02` menjalankan guard negatif dan memulihkan URL sumber setelah setiap kasus:
`failed_fetch`, `fetch_zero_rows`, `missing_rows_guard`, quarantine harga ekstrem
sementara operasi lain tetap berjalan, serta 11 delete eksplisit yang harus tertahan di
`awaiting_approval`. Tidak boleh ada write/trash saat guard yang seharusnya abort.

`sync-03` mengubah harga satu SKU secara manual dengan `post_modified` lebih baru,
memastikan conflict flag dibuat tanpa menimpa harga WP, mengakui resolusi *keep WP*,
menjalankan fixture update, rollback hanya run terbaru, lalu memastikan rollback run
lama ditolak. Sumber asli dipulihkan pada akhir skrip.

Fixture `rollback` sengaja hanya memuat sebagian kecil katalog, sehingga ia
menghilangkan jauh lebih dari `missing_row_flag_pct` SKU yang sudah terlihat dan akan
dihentikan guard baris hilang sebelum sempat menghasilkan update yang dibutuhkan
langkah ini. Karena itu `sync-03` menaikkan `missing_row_flag_pct` koneksi menjadi
100 **hanya** selama run fixture rollback — mengikuti pola `sync-04` yang menaikkan
threshold jumlah operasi untuk fixture-nya sendiri — lalu memulihkan nilai aslinya
persis setelah run. Nilai asli disimpan di state, dan ada pemulihan defensif di akhir
skrip, sehingga run yang di-resume pun tidak meninggalkan koneksi dengan guard
longgar. Nilai 100 aman karena guard memakai `missing_pct > threshold` sedangkan
`missing_pct` maksimum 100.

`sync-04` menaikkan threshold hanya pada koneksi fixture, menjalankan worker di
background, melakukan `SIGKILL` setelah 3 detik, mempercepat heartbeat menjadi stale
hanya untuk determinisme test, lalu menjalankan worker ulang. Lulus bila run menjadi
`done`, named lock tidak deadlock, dan query `SKU GROUP BY ... HAVING COUNT(*) > 1`
tidak menemukan SKU owned ganda. Konfigurasi koneksi sebelum chaos dipulihkan.

`sync-05` memanggil langsung `tools/call` ke `bcs-parse-source`, `bcs-check-duplicates`,
dan `bcs-build-plan` dengan `regular_price` pada SKU milik koneksi aktif. Panggilan
terakhir **wajib ditolak** dan pesannya menyebut connection id serta `regular_price`.
Skrip tidak memanggil `execute-batch`.

## 9.4 Sheets API dan verifikasi lokal

Fetcher `sheets_api` diuji lokal melalui `tests/test-bcs-sync-sheets.php`: JWT RS256,
token transient 50 menit, cache hit/expiry, konversi values API ke parser CSV,
credential encryption round-trip, invalid key, HTTP/API error, zero rows, schema
mismatch/drift, rate limit, dan sharing guard. Jalankan tanpa jaringan live:

```bash
php -l plugin/bulk-catalog-studio/includes/class-bcs-sync-fetcher-sheets-api.php
php -l tests/test-bcs-sync-sheets.php
php tests/test-bcs-sync-sheets.php
```

Setelah perubahan PHP, jalankan juga seluruh test harness lokal yang tersedia dan lint
semua file PHP tersentuh. **Jangan** menjalankan live E2E, deploy, SSH, atau commit dari
sesi implementasi ini.

## 9.5 Abort criteria Sync

- `tools/list` bukan tepat 11 tool, serverInfo bukan `Bulk Catalog Studio MCP`, atau
  transport bukan HTTP → STOP.
- Credential/source fetch error, zero rows, schema drift, missing-row guard, atau
  threshold guard gagal menghentikan run → STOP; cek `error_code` dan jangan retry
  membabi buta.
- Produk tertulis sebelum approval, conflict menimpa nilai manual, rollback run lama
  diterima, chaos menghasilkan SKU ganda, atau agent gate menerima edit owned field →
  STOP dan simpan JSONL sebagai bukti.
- Setelah FAIL/STOP, sumber CSV harus dikembalikan ke `BCS_SYNC_CONN_CSV_URL` sebelum
  investigasi berikutnya. Jangan menghapus tabel atau mengosongkan trash otomatis.
