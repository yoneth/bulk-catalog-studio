# Bulk Catalog Studio (BCS)

Plugin WordPress kecil yang mengubah sumber data tabular (CSV / export CSV publik
Google Sheets / rows hasil prompt Claude) menjadi **produk WooCommerce native**,
dengan pipeline 6 gerbang dan approval manusia wajib:

```
parse → duplicate-check → build-plan → HUMAN APPROVAL (token one-time) → execute → (opsional) rollback
```

Plugin mendaftarkan 8 ability `bcs/*` via **WordPress Abilities API**
(`wp_register_ability`, hook `wp_abilities_api_init`) dengan
`meta.mcp.public = true` sehingga otomatis terekspos sebagai MCP tools oleh
**MCP Adapter resmi** (default server `mcp-adapter-default-server`).

Produk ditulis 100% lewat `WC_Product` / `WC_Product_Variable` /
`WC_Product_Variation` CRUD (`WC_Product_Variable::sync()` setelah variasi) —
**tanpa** `wp_insert_post`/meta manual — sehingga hasilnya editable penuh dari
admin WooCommerce biasa, tanpa plugin ini aktif pun.

---

## 1. Install

1. Salin folder `bulk-catalog-studio/` ke `/wp-content/plugins/`.
2. Prasyarat: WordPress ≥ 6.9, PHP ≥ 7.4, WooCommerce aktif, MCP Adapter aktif
   (endpoint `/wp-json/mcp/mcp-adapter-default-server` merespons 401 tanpa auth).
3. Aktifkan plugin → tabel `bcs_jobs`, `bcs_plans`, `bcs_audit` dibuat otomatis
   (dbDelta) beserta option `bcs_settings`.
4. Halaman admin: **Bulk Catalog** (menu `admin.php?page=bcs-jobs`).

## 2. Konek Claude (MCP)

Buat user khusus MCP (bukan administrator), mis. `mcp-catalog`, dengan capability:
`read`, `edit_products`, `manage_product_terms`, `manage_woocommerce`.
Lalu buat **Application Password** (Users → Profile → Application Passwords).

Konfigurasi `mcpServers` (Claude Desktop / Claude Code) memakai proxy resmi
`@automattic/mcp-wordpress-remote`:

```json
{
  "mcpServers": {
    "wordpress-mcp-server": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server",
        "OAUTH_ENABLED": "false",
        "WP_API_USERNAME": "mcp-catalog",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Catatan:

| Env | Aturan |
|---|---|
| `WP_API_URL` | URL endpoint **penuh** default server. Domain telanjang → endpoint legacy `wordpress-mcp` (deprecated). |
| `OAUTH_ENABLED` | `false` — wajib agar memakai Application Password, bukan OAuth. |
| `WP_API_USERNAME` | Username login WP (bukan email). |
| `WP_API_PASSWORD` | Application Password (bukan password akun, bukan REST key WooCommerce). |

Setelah connect, ability ditemukan lewat meta-tool
`mcp-adapter-discover-abilities` lalu dieksekusi via
`mcp-adapter-execute-ability` (perilaku default server — ability tidak muncul
satu per satu di `tools/list`).

## 3. Contoh alur 1 job (CSV 30 produk fashion)

Fixture contoh: `tests/fixtures/sample-fashion-30.csv` (20 simple + 10 variable
ukuran×warna ≈ 60 variasi, 2 duplikat SKU) dan
`tests/fixtures/sample-prompt-rows.json` (contoh rows inline dari Claude).

1. **`bcs-parse-source`** — kirim CSV (inline `csv_text`, `attachment_id`, atau
   `url` export CSV publik Google Sheets) + `column_mapping`.
   Output: `job_id`, `source_hash`, `total_rows`, warnings.
2. **`bcs-check-duplicates`** — `{ job_id, strategy: "sku+title", on_duplicate: "flag_only" }`.
3. Manusia memutuskan nasib duplikat; Claude memanggil
   **`bcs-build-plan`** — `{ job_id, on_duplicate: "update", row_overrides: [{row_index: 19, op: "skip"}] }`.
   Output: `plan_id`, `plan_hash`, `summary`, `diff_preview_url`.
4. Manusia membuka **wp-admin → Bulk Catalog → Job → Diff preview**, memeriksa
   tabel create/update/skip + before/after, lalu klik **Generate token approval**
   → token `BCS-XXXXX` tampil **SEKALI** (disimpan sebagai hash; kedaluwarsa
   24 jam; single-use; terikat `plan_hash`).
5. Claude memanggil **`bcs-approve-plan`** dengan token dari manusia — ATAU
   manusia klik tombol **Approve** langsung di layar (pilih salah satu).
6. Claude memanggil **`bcs-execute-batch`** — opsional `dry_run: true` untuk
   simulasi. Server menulis dalam chunk 10 operasi + heartbeat; mendukung
   `resume: true` bila request mati (idempotent per `row_key`).
7. Verifikasi: **`bcs-job-status`** (`stale` flag) + **`bcs-audit-log`** (level
   `row` untuk jejak per produk/variasi).
8. Bila perlu membatalkan: **`bcs-rollback-batch`** dengan `confirm: true` dan
   `scope: all|created_only|updated_only`. Produk baru → trash (soft) beserta
   variasinya; produk update → dikembalikan ke snapshot `before`.

## 4. Format CSV

- Delimiter: koma, titik-koma, atau `tab`.
- Baris pertama biasanya header; mapping via `column_mapping`
  (`"Nama": "name"`, dst). Tanpa mapping, header yang sama dengan nama field
  kanonik terdeteksi otomatis.
- Field kanonik: `name, description, short_description, sku, regular_price,
  sale_price, stock_quantity, weight, length, width, height, categories, tags,
  attribute:<slug>, variation_sku, variation_attribute:<slug>,
  variation_regular_price, variation_stock_quantity, image_url, image_alt,
  seo_title, seo_description`. Juga diterima bentuk `dimensions.length` dst.
- **Multi-nilai produk variabel** dipisah pipe `|` (satu baris = satu produk):
  `attribute:pa_ukuran = S|M|L`, `variation_sku = SKU-S-MRH|SKU-S-BRU|…`,
  `variation_attribute:pa_ukuran = S|S|M|M|L|L` (paralel dengan SKU variasi).
  Tanpa kolom `variation_attribute:*`, kombinasi variasi dibentuk dari
  cartesian product opsi atribut.
- Kategori hierarki: `Wanita > Dress`, multi-kategori dipisah koma.
- Gambar: V1 hanya `attachment_id` (nilai numerik di kolom `image_url` /
  `image_id`). URL gambar diabaikan + warning (keputusan gate1 #4).
- Batas: 500 baris/job hard, default setting 100 baris; inline 2 MB; attachment 10 MB.

## 5. Batas V1 (keputusan gate1 — `docs/spec/gate1-decisions.md`)

1. Google Sheets: hanya URL export CSV **publik** (tanpa OAuth).
2. Approval: token one-time hanya di layar admin WP (tanpa email — V1.1).
3. Capability write: `manage_woocommerce` (custom cap `bcs_execute` → V2).
4. Gambar: `attachment_id_only` — tidak ada sideload URL (V1.1). Alt text tetap
   diisi ke attachment yang dirujuk.
5. Rollback produk baru: **trash** (soft) sebagai satu-satunya opsi.
6. Operasi `update_product` tidak menyentuh struktur variasi/atribut produk
   existing di V1 (variasi hanya untuk create) — menjaga keakuratan rollback.
7. Non-goals lain: agentic checkout, headless storefront, multi-vendor, AI
   copywriting di sisi plugin, dsb. (spec §7).

## 6. Keamanan

- Semua input ability strict: `additionalProperties: false`.
- `permission_callback` per ability + re-check di dalam `execute_callback`
  (defense in depth), rate limit 60 panggilan/user/menit (transient).
- Transisi state machine dipaksa; transisi invalid → `WP_Error` + audit.
- Semua query kustom memakai `$wpdb->prepare`; semua output admin di-escape.
- Semua tulis produk lewat satu service (`BCS_Catalog_Writer`) dengan whitelist
  meta (`_bcs_*`, meta SEO Yoast/Rank Math, `_wp_attachment_image_alt`).
- Audit append-only di `bcs_audit` (level call + row) dengan `actor_via`
  (`mcp` / `wp_admin` / `wp_cli` / `system`).
- Deactivate tidak menghapus apa pun; uninstall hanya drop tabel `bcs_*` +
  options/transients `bcs_*`.

## 7. Struktur kode

```
bulk-catalog-studio.php          bootstrap + hooks aktivasi + registrasi ability
uninstall.php                    drop tabel + options saat uninstall
readme.txt                       readme standar WP
README.md                        dokumen ini
includes/
  class-bcs-install.php          dbDelta 3 tabel (spec §3)
  class-bcs-settings.php         defaults + helper settings
  class-bcs-schemas.php          input/output JSON Schema 8 ability
  class-bcs-abilities.php        registrar ability + execute callbacks
  class-bcs-csv-parser.php       parser + normalizer CSV
  class-bcs-duplicate-checker.php duplikat intra-batch + vs katalog
  class-bcs-plan-builder.php     plan materialized + snapshot before
  class-bcs-approval-token.php   token one-time (hash, expire, single-use)
  class-bcs-catalog-writer.php   SATU service tulis produk (WC CRUD)
  class-bcs-executor.php         chunked execution + dry_run + resume
  class-bcs-rollback-service.php trash/restore per plan
  class-bcs-job-manager.php      state machine + CRUD job
  class-bcs-audit-logger.php     audit call/row
admin/
  class-bcs-admin.php            halaman admin: jobs, diff preview, token, audit
tests/fixtures/
  sample-fashion-30.csv          fixture 30 baris (20 simple + 10 variable)
  sample-prompt-rows.json        contoh rows inline dari Claude
```
