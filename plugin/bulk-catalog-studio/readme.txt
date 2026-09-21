=== Bulk Catalog Studio ===
Contributors: cfdjavaweb
Tags: woocommerce, mcp, ai, bulk import, catalog, abilities-api
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, mcp-adapter
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mengubah CSV / Google Sheet export / rows hasil prompt menjadi produk WooCommerce native via WordPress Abilities API + MCP, dengan pipeline approval manusia.

== Description ==

AI Bulk Catalog Studio (BCS) mendaftarkan 8 ability (`bcs/*`) ke WordPress Abilities API yang otomatis terekspos sebagai MCP tools oleh MCP Adapter resmi (default server):

1. `bcs/parse-source` — parse CSV inline / attachment / URL export CSV publik Google Sheets → job baru berstatus `parsed`.
2. `bcs/check-duplicates` — deteksi duplikat intra-batch dan vs katalog (SKU + nama normalized).
3. `bcs/build-plan` — plan eksekusi materialized (create/update/skip + payload final + snapshot `before`).
4. `bcs/approve-plan` — gerbang approval: token one-time yang HANYA tampil di layar admin WP.
5. `bcs/execute-batch` — eksekusi plan via WC_Product CRUD (chunk 10 operasi, heartbeat, dry_run, resume idempotent).
6. `bcs/job-status` — list job terbaru / detail satu job (termasuk staleness).
7. `bcs/rollback-batch` — trash produk baru (soft) + restore snapshot before untuk update.
8. `bcs/audit-log` — jejak audit level call + row per job.

Pipeline penuh: **parse → duplicate-check → build-plan → HUMAN APPROVAL → execute → (opsional) rollback**. Tidak ada aksi tulis MCP tanpa approval.

Produk hasil BCS 100% native WooCommerce (CPT `product`, `product_variation`, taxonomy `product_cat`, `product_tag`, `pa_*`) — editable dari admin WooCommerce biasa tanpa plugin aktif pun.

== Installation ==

1. Upload folder `bulk-catalog-studio` ke `/wp-content/plugins/`.
2. Pastikan WooCommerce aktif, plugin **mcp-adapter** aktif, dan WordPress ≥ 6.9.
3. Aktifkan plugin melalui menu Plugins. Tabel `bcs_jobs`, `bcs_plans`, `bcs_audit` dibuat otomatis.
4. Custom MCP server aktif di **`/wp-json/bcs-mcp/mcp`** — endpoint default `/wp-json/mcp/mcp-adapter-default-server` tidak dipakai (ter-intercept Novamira di server target).
5. Buat user khusus MCP (mis. `mcp-catalog`) dengan capability: `read`, `edit_products`, `manage_product_terms`, `manage_woocommerce`. Bukan administrator.
6. Buat Application Password untuk user tersebut (Users → Profile → Application Passwords).

== Connecting Claude ==

Konfigurasi `mcpServers` (Claude Desktop / Claude Code), memakai proxy resmi `@automattic/mcp-wordpress-remote`:

    {
      "mcpServers": {
        "bulk-catalog-studio": {
          "command": "npx",
          "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
          "env": {
            "WP_API_URL": "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp",
            "OAUTH_ENABLED": "false",
            "WP_API_USERNAME": "mcp-catalog",
            "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
          }
        }
      }
    }

Catatan penting:
- `OAUTH_ENABLED=false` wajib agar proxy memakai Application Password (bukan OAuth).
- Endpoint utama adalah custom server BCS: `https://<domain>/wp-json/bcs-mcp/mcp`. Endpoint default `/wp-json/mcp/...` TIDAK dipakai (ter-intercept Novamira di server target) — domain telanjang juga harus dihindari (resolve ke legacy `wordpress-mcp` yang deprecated).
- `WP_API_USERNAME` = username login WP (bukan email); `WP_API_PASSWORD` = Application Password (bukan password akun, bukan REST API key WooCommerce).
- Pada custom server ini 8 tool BCS tampil individual di `tools/list` (`bcs-parse-source`, dst.).
- Transport permission server kustom menuntut `manage_woocommerce` (plus re-check per ability).

== Contoh alur 1 job ==

1. Claude memanggil `bcs-parse-source` (CSV 30 produk fashion: 20 simple + 10 variable ukuran×warna ≈ 60 variasi).
2. Claude memanggil `bcs-check-duplicates` → 2 duplikat ditemukan.
3. Manusia memutuskan: baris 5 di-update, baris 19 di-skip. Claude memanggil `bcs-build-plan` dengan `on_duplicate` + `row_overrides`.
4. Manusia membuka **Bulk Catalog → Job → Diff preview** di wp-admin, memeriksa diff, klik **Generate token approval** → token `BCS-XXXXX` tampil SEKALI.
5. Claude memanggil `bcs-approve-plan` dengan token dari manusia (atau manusia klik tombol Approve).
6. Claude memanggil `bcs-execute-batch` → produk + variasi dibuat (WC_Product CRUD).
7. Claude verifikasi via `bcs-job-status` + `bcs-audit-log`.
8. Bila ada masalah: `bcs-rollback-batch` dengan `confirm: true`.

== Frequently Asked Questions ==

= Apakah plugin menulis produk tanpa approval? =

Tidak. `bcs/execute-batch` menolak plan yang belum `approved`. Approval menuntut token one-time yang hanya bisa dilihat manusia di layar admin (halaman diff), terikat `plan_hash`, kedaluwarsa 24 jam, single-use.

= Apa yang terjadi saat deactivate? =

Tidak ada data hilang (AC-7): tabel dan settings dipertahankan. Hanya uninstall (delete plugin) yang drop tabel + options `bcs_*`. Produk tidak pernah disentuh uninstall.

= Format CSV yang didukung? =

Delimiter koma / titik-koma / tab; baris header opsional dengan `column_mapping`; multi-nilai produk variabel dipisah `|` (opsi atribut, SKU variasi, harga variasi, stok variasi paralel). Mode URL hanya menerima export CSV publik dari docs.google.com (batas SSRF). Batas V1: 500 baris/job (default setting 100), inline CSV 2 MB, attachment 10 MB.

== Changelog ==

= 1.0.0 =
* Rilis awal V1 sesuai spec `docs/spec/bulk-catalog-studio.md` + keputusan gate1.
* 8 ability `bcs/*` (Abilities API + MCP Adapter, `meta.mcp.public`).
* Pipeline approval token one-time, rollback snapshot, audit per baris.
* Gambar: `attachment_id_only` (sideload ditunda V1.1 — keputusan gate1 #4).
* Retur QA-1: update tidak menyentuh status/stok tak-dikirim; rollback
  job-scoped dari audit; konsumsi token atomik single-use; SSRF guard (host
  hanya docs.google.com, redirect off, blokir IP privat); rebuild plan
  (superseded); resume tanpa double-count; konversi encoding CSV ke UTF-8.
