# Spesifikasi Fitur: AI Bulk Catalog Studio (MCP Claude)

| | |
|---|---|
| **Proyek** | WooCommerce Store Kit — cf.djavaweb.com |
| **Versi** | 1.0 (draft untuk fase developer scout) |
| **Status** | Spec-of-record, siap eksekusi tanpa pertanyaan tambahan |
| **Fitur induk** | P0 #1 big picture: input CSV/Google Sheet/prompt → produk WooCommerce NATIVE |
| **Arsitektur terkunci** | Plugin WordPress kecil yang register abilities via WordPress Abilities API (`wp_register_ability`) dengan `meta.mcp.public = true`, diekspos otomatis sebagai MCP tools oleh WordPress MCP Adapter resmi (sudah aktif di https://cf.djavaweb.com). Claude konek via `@automattic/mcp-wordpress-remote` + Application Password user khusus. |
| **Larangan eksplisit** | Tidak ada aksi tulis MCP tanpa approval manual. Novamira tidak dipakai. |

## 0. Ringkasan desain

AI Bulk Catalog Studio (BCS) adalah plugin WordPress dengan namespace ability `bcs/*` yang mengubah sumber data tabular (CSV / Google Sheet via export CSV / rows hasil prompt) menjadi **produk WooCommerce native** (CPT `product`, `product_variation`, taxonomies `product_cat`, `product_tag`, attribute taxonomies `pa_*`) — bukan HTML mentah. Seluruh operasi tulis melewati pipeline 4 gerbang:

```
parse → duplicate-check → build-plan → HUMAN APPROVAL → execute → (opsional) rollback
```

Produk dihasilkan 100% editable via admin WooCommerce biasa tanpa AI, karena penulisan dilakukan lewat `WC_Product` CRUD API resmi (bukan `wp_insert_post` + meta manual, dan bukan post_content HTML).

Referensi riset yang dipakai untuk validasi:
- MCP Adapter resmi: ability dengan `meta.mcp.public = true` otomatis jadi MCP tools di default server `mcp-adapter-default-server`, endpoint `https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server`, autentikasi Application Password via `@automattic/mcp-wordpress-remote` (https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/ , https://github.com/wordpress/mcp-adapter).
- Best practice tulis produk: `WC_Product` / `WC_Product_Variable` / `WC_Product_Variation` CRUD objects (WooCommerce ≥ 3.0) adalah cara yang benar; `wp_insert_post` + `update_post_meta` manual tidak direkomendasikan dan rapuh terhadap perubahan storage WooCommerce. Atribut variasi bertaxonomy memakai prefix `pa_`; setelah menulis variasi wajib `WC_Product_Variable::sync( $product_id )` (https://rudrastyh.com/woocommerce/create-product-variations-programmatically.html).
- Struktur variasi (SKU, harga, stok, dimensi per variasi) mengikuti kontrak Product Variations API WooCommerce (https://developer.woocommerce.com/docs/apis/rest-api/v2/product-variations/).

---

## 1. Daftar ability final

Semua ability memakai nama `bcs/<slug>` agar otomatis menjadi MCP tools `bcs-<slug>` di default server MCP Adapter. Semua `input_schema` memakai `additionalProperties: false` (strict) — vektor pertama pencegahan aksi di luar scope. Semua `execute_callback` melakukan pemeriksaan capability ulang di dalam server (jangan mengandalkan transport saja). Semua pemanggilan ability tercatat di `bcs_audit` (level call) dan `execute-batch`/`rollback-batch` tambah entri level row.

**Revisi dari draft (dengan alasan):**
1. `bcs/job-status` dibuat `job_id` opsional: tanpa `job_id` → list N job terbaru. Alasan: Claude sesi baru harus bisa menemukan job yang belum selesai tanpa state eksternal; menghemat satu tool (`list-jobs` tidak jadi ability terpisah).
2. `bcs/parse-source` mendukung 3 mode sumber (inline text, `attachment_id` media library, URL export CSV publik Google Sheet). Alasan: konteks big picture mensyaratkan CSV + Google Sheet + prompt; mode "prompt" dicover karena Claude menyusun rows sendiri lalu mengirim inline.
3. `bcs/approve-plan` menuntut `confirmation_token` one-time yang HANYA tampil di layar admin WP. Alasan: approval tetap bisa dipicu lewat MCP (workflow Claude-driven), tapi token hanya bisa diperoleh manusia dari dashboard — memenuhi larangan "tulis tanpa approval".
4. Ditambah field `dry_run` pada `bcs/execute-batch` (default `false`). Alasan: uji acceptance criteria tanpa efek samping; murah diimplementasi karena plan sudah materialized.

### 1.1 `bcs/parse-source` — read-only (menulis hanya baris job, bukan produk)

- **Deskripsi**: Ambil sumber data (CSV inline / attachment / URL), parse, normalisasi header, validasi tipe per kolom, buat job baru berstatus `parsed`, simpan hash sumber untuk idempotency.
- **Permission callback**: `current_user_can( 'edit_products' )`.
- **Write?** Tidak menulis produk; menulis 1 baris `bcs_jobs` + isi parse (disimpan di plan staging tabel `bcs_plans` versi 0). Tetap dilabeli **read-only terhadap katalog**.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "source_type": { "type": "string", "enum": ["inline_csv", "attachment", "url"] },
    "csv_text": { "type": "string", "maxLength": 2000000, "description": "Wajib jika source_type=inline_csv" },
    "attachment_id": { "type": "integer", "minimum": 1, "description": "Wajib jika source_type=attachment; harus post_type=attachment dan mime text/csv" },
    "url": { "type": "string", "format": "uri", "maxLength": 2048, "description": "Wajib jika source_type=url; hanya https, khusus Google Sheets wajib endpoint export?format=csv publik" },
    "source_name": { "type": "string", "maxLength": 200 },
    "delimiter": { "type": "string", "enum": [",", ";", "tab"], "default": "," },
    "header_row": { "type": "boolean", "default": true },
    "column_mapping": {
      "type": "object",
      "additionalProperties": { "type": "string" },
      "description": "Peta header CSV -> field kanonik: name, description, short_description, sku, regular_price, sale_price, stock_quantity, weight, length, width, height, categories, tags, attribute:<slug>, variation_sku, variation_attribute:<slug>, variation_regular_price, variation_stock_quantity, image_url, image_alt, seo_title, seo_description"
    },
    "notes": { "type": "string", "maxLength": 2000, "description": "Catatan Claude tentang asumsi mapping, disimpan di job untuk audit" }
  },
  "required": ["source_type"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "status": { "type": "string", "const": "parsed" },
    "source_hash": { "type": "string", "description": "sha1 isi sumber; deteksi upload ganda" },
    "total_rows": { "type": "integer" },
    "detected_columns": { "type": "array", "items": { "type": "string" } },
    "unmapped_columns": { "type": "array", "items": { "type": "string" } },
    "warnings": { "type": "array", "items": { "type": "string" }, "description": "Baris dilewati, kolom kosong wajib, harga non-numerik, dsb." },
    "errors": { "type": "array", "items": { "type": "string" } }
  },
  "required": ["job_id", "status", "source_hash", "total_rows", "warnings"]
}
```

### 1.2 `bcs/check-duplicates` — read-only

- **Deskripsi**: Cek duplikasi (a) antar-baris dalam job, (b) baris vs katalog existing (SKU exact; opsional nama+brand normalized). Hasil disimpan di job; tidak mengubah produk.
- **Permission callback**: `current_user_can( 'edit_products' )`.
- **Write?** Tidak.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer", "minimum": 1 },
    "strategy": {
      "type": "string",
      "enum": ["sku", "sku+title"],
      "default": "sku+title",
      "description": "sku: hanya cocokkan SKU exact (case-insensitive, trim). sku+title: tambah cocokkan nama normalized (lowercase, tanpa tanda baca)"
    },
    "on_duplicate": {
      "type": "string",
      "enum": ["flag_only", "skip", "update"],
      "default": "flag_only",
      "description": "Kebijakan default yang akan dibawa ke build-plan; manusia tetap melihat hasilnya di preview"
    }
  },
  "required": ["job_id"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "intra_batch_duplicates": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "row_indices": { "type": "array", "items": { "type": "integer" } },
          "sku": { "type": ["string", "null"] }
        },
        "required": ["row_indices"]
      }
    },
    "existing_matches": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "row_index": { "type": "integer" },
          "sku": { "type": ["string", "null"] },
          "existing_product_id": { "type": "integer" },
          "match_on": { "type": "string", "enum": ["sku", "title"] }
        },
        "required": ["row_index", "existing_product_id", "match_on"]
      }
    },
    "duplicate_count": { "type": "integer" },
    "on_duplicate_effective": { "type": "string", "enum": ["flag_only", "skip", "update"] }
  },
  "required": ["job_id", "intra_batch_duplicates", "existing_matches", "duplicate_count", "on_duplicate_effective"]
}
```

### 1.3 `bcs/build-plan` — read-only terhadap katalog (menulis plan)

- **Deskripsi**: Transform rows hasil parse + hasil duplicate-check menjadi **plan eksekusi materialized**: daftar operasi `create_product` / `update_product` lengkap dengan payload final (nama, deskripsi, short description, kategori, tag, SKU, harga, stok, berat, dimensi, atribut + variasi, image alt, SEO meta) DAN snapshot `before` untuk operasi update. Plan immutable: hash plan jadi dasar validasi approval.
- **Permission callback**: `current_user_can( 'edit_products' )`.
- **Write?** Tidak menyentuh produk; menulis 1 baris `bcs_plans` + update status job ke `planned`.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer", "minimum": 1 },
    "on_duplicate": { "type": "string", "enum": ["skip", "update"], "description": "Wajib keputusan final bila ada duplikat; 'flag_only' ditolak di tahap ini" },
    "defaults": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "status": { "type": "string", "enum": ["publish", "draft"], "default": "draft", "description": "Status produk baru; default draft agar aman" },
        "catalog_visibility": { "type": "string", "enum": ["visible", "hidden"], "default": "visible" },
        "manage_stock": { "type": "boolean", "default": true },
        "tax_class": { "type": ["string", "null"], "default": null },
        "attribute_scope": { "type": "string", "enum": ["global_pa", "custom"], "default": "global_pa", "description": "global_pa: variasi memakai taxonomy pa_* global agar reusable" },
        "seo_provider": { "type": "string", "enum": ["auto", "yoast", "rank_math", "custom_fields"], "default": "auto" },
        "max_variations_per_product": { "type": "integer", "minimum": 1, "maximum": 100, "default": 100 }
      }
    },
    "image_policy": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "mode": { "type": "string", "enum": ["attachment_id_only", "sideload_url"], "default": "attachment_id_only" },
        "set_alt_text": { "type": "boolean", "default": true }
      }
    }
  },
  "required": ["job_id", "on_duplicate"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "plan_id": { "type": "integer" },
    "plan_hash": { "type": "string", "description": "sha1 dari plan JSON; approval terikat pada hash ini" },
    "summary": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "create_simple": { "type": "integer" },
        "create_variable": { "type": "integer" },
        "create_variations": { "type": "integer" },
        "update_products": { "type": "integer" },
        "skip_duplicates": { "type": "integer" },
        "total_operations": { "type": "integer" }
      },
      "required": ["create_simple", "create_variable", "create_variations", "update_products", "skip_duplicates", "total_operations"]
    },
    "diff_preview_url": { "type": "string", "description": "URL admin preview diff: admin_url('admin.php?page=bcs-jobs&view=plan&plan=<id>')" },
    "approval_token_hint": { "type": "string", "const": "Token approval hanya ditampilkan di layar admin WP (halaman diff). Minta ke manusia.", "description": "Token TIDAK PERNAH dikembalikan via MCP" }
  },
  "required": ["job_id", "plan_id", "plan_hash", "summary", "diff_preview_url"]
}
```

### 1.4 `bcs/approve-plan` — WRITE (satu-satunya gerbang approval)

- **Deskripsi**: Validasi one-time `confirmation_token` (hanya tampil di halaman admin diff) terhadap plan, lalu transisi `planned → approved`. Token disimpan sebagai hash, terikat `plan_hash`, kedaluwarsa 24 jam, single-use.
- **Permission callback**: `current_user_can( 'manage_woocommerce' )`.
- **Write?** Ya (status job/plan + audit). Tidak menyentuh produk.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "plan_id": { "type": "integer", "minimum": 1 },
    "plan_hash": { "type": "string", "minLength": 40, "maxLength": 40, "description": "Harus identik dengan plan_hash dari build-plan; mencegah approve plan lama yang sudah berubah" },
    "confirmation_token": { "type": "string", "minLength": 6, "maxLength": 12, "pattern": "^[A-Z0-9-]+$", "description": "Token one-time yang ditampilkan HANYA di layar admin diff. Diberikan manusia ke Claude atau dipakai manusia langsung via tombol Approve di dashboard" }
  },
  "required": ["plan_id", "plan_hash", "confirmation_token"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "plan_id": { "type": "integer" },
    "job_id": { "type": "integer" },
    "status": { "type": "string", "const": "approved" },
    "approved_by": { "type": "integer", "description": "WP user ID pemilik session MCP yang memvalidasi token" },
    "approved_at": { "type": "string", "format": "date-time" },
    "note": { "type": "string", "description": "Kosong bila sukses; berisi alasan penolakan token bila gagal" }
  },
  "required": ["plan_id", "job_id", "status", "approved_at"]
}
```

### 1.5 `bcs/execute-batch` — WRITE

- **Deskripsi**: Eksekusi plan yang sudah `approved`. Server menjalankan operasi per-row lewat `WC_Product` CRUD dalam chunk internal (10 produk per iterasi, dengan guard waktu + heartbeat), hasil per-row tercatat. Idempotent: baris yang sudah sukses pada run sebelumnya tidak dieksekusi ulang.
- **Permission callback**: `current_user_can( 'manage_woocommerce' )`.
- **Write?** Ya — satu-satunya ability yang membuat/mengubah produk.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "plan_id": { "type": "integer", "minimum": 1 },
    "dry_run": { "type": "boolean", "default": false, "description": "true: jalankan validasi penuh tanpa menulis produk; hasil = simulasi" },
    "resume": { "type": "boolean", "default": false, "description": "true: lanjutkan job berstatus executing yang heartbeat-nya stale (>5 menit) karena request mati" }
  },
  "required": ["plan_id"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "plan_id": { "type": "integer" },
    "status": { "type": "string", "enum": ["done", "partially_failed", "failed"] },
    "counts": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "products_created": { "type": "integer" },
        "products_updated": { "type": "integer" },
        "variations_created": { "type": "integer" },
        "skipped": { "type": "integer" },
        "failed": { "type": "integer" },
        "total_rows": { "type": "integer" }
      },
      "required": ["products_created", "products_updated", "variations_created", "skipped", "failed", "total_rows"]
    },
    "failures": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "row_index": { "type": "integer" },
          "sku": { "type": ["string", "null"] },
          "reason": { "type": "string" }
        },
        "required": ["row_index", "reason"]
      }
    },
    "duration_ms": { "type": "integer" }
  },
  "required": ["job_id", "plan_id", "status", "counts"]
}
```

### 1.6 `bcs/job-status` — read-only

- **Deskripsi**: Tanpa `job_id`: list 20 job terbaru (id, status, counters, timestamps) — untuk resuming antar sesi Claude. Dengan `job_id`: detail satu job termasuk staleness heartbeat.
- **Permission callback**: `current_user_can( 'edit_products' )`.
- **Write?** Tidak.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer", "minimum": 1, "description": "Opsional; tanpa ini = list job terbaru" }
  },
  "required": []
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "jobs": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "job_id": { "type": "integer" },
          "status": { "type": "string", "enum": ["draft", "parsed", "planned", "approved", "executing", "done", "failed", "partially_failed", "rolled_back"] },
          "source_name": { "type": "string" },
          "total_rows": { "type": "integer" },
          "counts": {
            "type": "object",
            "additionalProperties": { "type": "integer" },
            "description": "created / updated / variations / skipped / failed"
          },
          "created_at": { "type": "string", "format": "date-time" },
          "updated_at": { "type": "string", "format": "date-time" },
          "heartbeat_at": { "type": ["string", "null"], "format": "date-time" },
          "stale": { "type": "boolean", "description": "true bila status=executing dan heartbeat > 5 menit lalu; boleh di-resume" }
        },
        "required": ["job_id", "status", "total_rows", "created_at", "updated_at"]
      }
    }
  },
  "required": ["jobs"]
}
```

### 1.7 `bcs/rollback-batch` — WRITE

- **Deskripsi**: Membatalkan efek job yang `done` / `partially_failed`. Semantik: produk **baru** → dipindah ke trash (`post_status = trash`) beserta variasinya (TIDAK dihapus permanen); produk **yang di-update** → semua field yang tersentuh dikembalikan ke snapshot `before` dari plan; variasi baru → trash. Audit menerima entri per-row `rolled_back`.
- **Permission callback**: `current_user_can( 'manage_woocommerce' )`.
- **Write?** Ya.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer", "minimum": 1 },
    "confirm": { "type": "boolean", "const": true, "description": "Harus true literal; mencegah rollback tak sengaja" },
    "scope": { "type": "string", "enum": ["all", "created_only", "updated_only"], "default": "all" }
  },
  "required": ["job_id", "confirm"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "status": { "type": "string", "const": "rolled_back" },
    "counts": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "products_trashed": { "type": "integer" },
        "products_restored": { "type": "integer", "description": "produk update yang dikembalikan ke nilai before" },
        "variations_trashed": { "type": "integer" },
        "failed": { "type": "integer" }
      },
      "required": ["products_trashed", "products_restored", "variations_trashed", "failed"]
    },
    "note": { "type": "string", "description": "Bila failed > 0, jelaskan baris mana dan mengapa" }
  },
  "required": ["job_id", "status", "counts"]
}
```

### 1.8 `bcs/audit-log` — read-only

- **Deskripsi**: Tarik entri audit untuk satu job (level call dan level row), urut waktu, paginasi. Untuk verifikasi manusia maupun Claude.
- **Permission callback**: `current_user_can( 'edit_products' )`.
- **Write?** Tidak.

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer", "minimum": 1 },
    "level": { "type": "string", "enum": ["call", "row", "all"], "default": "all" },
    "limit": { "type": "integer", "minimum": 1, "maximum": 200, "default": 50 },
    "offset": { "type": "integer", "minimum": 0, "default": 0 }
  },
  "required": ["job_id"]
}
```

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "job_id": { "type": "integer" },
    "total": { "type": "integer" },
    "entries": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "id": { "type": "integer" },
          "ts": { "type": "string", "format": "date-time" },
          "level": { "type": "string", "enum": ["call", "row"] },
          "ability": { "type": "string" },
          "actor_user_id": { "type": "integer" },
          "actor_via": { "type": "string", "enum": ["mcp", "wp_admin", "wp_cli", "system"] },
          "action": { "type": "string", "description": "contoh: create_product, update_variation, trash_product, approve_plan, rollback_row" },
          "row_index": { "type": ["integer", "null"] },
          "sku": { "type": ["string", "null"] },
          "product_id": { "type": ["integer", "null"] },
          "status": { "type": "string", "enum": ["ok", "failed", "rolled_back", "skipped"] },
          "message": { "type": "string" }
        },
        "required": ["id", "ts", "level", "ability", "actor_user_id", "action", "status"]
      }
    }
  },
  "required": ["job_id", "total", "entries"]
}
```

### 1.9 Ringkasan ability

| Ability | Mode | Permission callback | Menulis produk? |
|---|---|---|---|
| `bcs/parse-source` | read-only katalog | `edit_products` | Tidak |
| `bcs/check-duplicates` | read-only | `edit_products` | Tidak |
| `bcs/build-plan` | read-only katalog | `edit_products` | Tidak (menulis plan) |
| `bcs/approve-plan` | write (gate) | `manage_woocommerce` | Tidak (status saja) |
| `bcs/execute-batch` | write | `manage_woocommerce` | **Ya** |
| `bcs/job-status` | read-only | `edit_products` | Tidak |
| `bcs/rollback-batch` | write | `manage_woocommerce` | Ya (trash/restore) |
| `bcs/audit-log` | read-only | `edit_products` | Tidak |

---

## 2. State machine job

### 2.1 Status

```
draft → parsed → planned → approved → executing → done
                                                  → partially_failed
                                                  → failed
done             → rolled_back
partially_failed → rolled_back
executing        → executing   (resume, hanya bila heartbeat stale > 5 menit)
```

### 2.2 Transisi valid & aktor

| Dari | Ke | Dipicu oleh ability | Aktor manusia yang diperlukan |
|---|---|---|---|
| `draft` | `parsed` | `bcs/parse-source` | Tidak (sumber datang dari manusia/Claude) |
| `parsed` | `planned` | `bcs/build-plan` | Tidak |
| `planned` | `approved` | `bcs/approve-plan` **atau** tombol Approve di admin | **YA — token one-time dari layar admin** |
| `approved` | `executing` | `bcs/execute-batch` | Tidak (approval sudah terjadi) |
| `executing` | `done` / `partially_failed` / `failed` | internal server, diakhiri `bcs/execute-batch` return | Tidak |
| `executing` | `executing` | `bcs/execute-batch` dengan `resume=true`, hanya bila `stale` | Tidak |
| `done` / `partially_failed` | `rolled_back` | `bcs/rollback-batch` | Tidak (job sudah pernah di-approve) |
| `failed` | — | terminal (tidak ada produk tertulis; rollback tidak perlu) | — |

Aturan enforcement:
- Semua transisi hanya terjadi di dalam `execute_callback` server-side, setelah membaca status dari DB (bukan dari input MCP). Tidak ada ability yang menerima parameter `status`.
- Semua transisi tulis status membungkus pembacaan+penulisan dalam lock (`INSERT INTO ... WHERE status = <expected>` atau `get_option` lock atomik) untuk mencegah dua execute paralel.
- Maksimum 1 job berstatus `executing` per site. `execute-batch` menolak bila ada job `executing` yang tidak stale.

---

## 3. Model data

### 3.1 Keputusan: tabel kustom, bukan CPT/meta

Alasan:
1. **Volume & pola akses**: satu job = ratusan–ribuan baris audit append-only. CPT memicu overhead revisi, autosave, cache object, query tak perlu; meta query untuk agregat lambat.
2. **Isolation**: job/plan/audit bukan konten publik; tidak boleh bocor ke REST, feed, search, sitemap, atau tooling ekspor konten.
3. **Indexing presisi**: butuh composite index `(job_id, row_index)`, `(status, heartbeat_at)` — susah dan rapuh dengan postmeta.
4. **Uninstall bersih**: `DROP TABLE` tiga tabel jauh lebih aman daripada menyapu ribuan post/meta tanpa meninggalkan yatim.
5. Preseden resmi: WooCommerce sendiri memakai tabel kustom (Order Store kembar ke tabel HPOS sebagai preseden arsitektur) untuk data operasional high-volume.

### 3.2 `{$wpdb->prefix}bcs_jobs`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `status` | VARCHAR(20) | enum state machine §2 |
| `source_type` | VARCHAR(20) | inline_csv / attachment / url |
| `source_name` | VARCHAR(200) | label manusia |
| `source_hash` | CHAR(40) | sha1 isi sumber |
| `total_rows` | INT UNSIGNED | |
| `count_created` | INT UNSIGNED DEFAULT 0 | |
| `count_updated` | INT UNSIGNED DEFAULT 0 | |
| `count_variations` | INT UNSIGNED DEFAULT 0 | |
| `count_skipped` | INT UNSIGNED DEFAULT 0 | |
| `count_failed` | INT UNSIGNED DEFAULT 0 | |
| `active_plan_id` | BIGINT UNSIGNED NULL | plan versi terakhir |
| `mapping_json` | LONGTEXT NULL | column_mapping + defaults + notes |
| `created_by` | BIGINT UNSIGNED | WP user ID |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |
| `heartbeat_at` | DATETIME NULL | update tiap chunk saat executing |
| `last_error` | TEXT NULL | |

Index: `PRIMARY(id)`, `KEY status_hb (status, heartbeat_at)`, `KEY source_hash (source_hash)`, `KEY created_at (created_at)`.

### 3.3 `{$wpdb->prefix}bcs_plans`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `job_id` | BIGINT UNSIGNED | FK logis ke bcs_jobs |
| `version` | INT UNSIGNED | naik tiap rebuild |
| `plan_json` | LONGTEXT | daftar operasi final + snapshot `before` per operasi update + payload variasi |
| `plan_hash` | CHAR(40) | sha1 `plan_json` — approval terikat di sini |
| `status` | VARCHAR(20) | planned / approved / executed / executed_with_errors / rolled_back |
| `token_hash` | CHAR(60) NULL | hash one-time approval token (bcrypt/wordpress `wp_hash_password`) |
| `token_expires_at` | DATETIME NULL | +24 jam sejak build |
| `token_used_at` | DATETIME NULL | single-use |
| `approved_by` | BIGINT UNSIGNED NULL | |
| `approved_at` | DATETIME NULL | |
| `created_at` | DATETIME | |

Index: `PRIMARY(id)`, `KEY job_version (job_id, version)`, `KEY plan_hash (plan_hash)`.

Bentuk satu operasi di `plan_json` (kontrak, bukan kode):

```json
{
  "row_index": 11,
  "row_key": "sha1(source_hash + row_index + normalized_sku)",
  "op": "create_product",
  "product_type": "variable",
  "target_product_id": null,
  "payload": {
    "name": "Dress Katun Melati",
    "description": "<p>HTML deskripsi…</p>",
    "short_description": "Ringkas 1-2 kalimat",
    "sku": "DRS-KTN-MLT-001",
    "regular_price": "189000",
    "sale_price": null,
    "manage_stock": true,
    "stock_quantity": 24,
    "weight": "0.35",
    "dimensions": { "length": "30", "width": "25", "height": "3" },
    "categories": ["Wanita", "Wanita > Dress"],
    "tags": ["katun", "musim-panas"],
    "attributes": [
      { "name": "pa_ukuran", "options": ["S", "M", "L"], "variation": true, "visible": true },
      { "name": "pa_warna", "options": ["merah", "biru"], "variation": true, "visible": true }
    ],
    "variations": [
      {
        "sku": "DRS-KTN-MLT-001-S-MRH",
        "attributes": { "pa_ukuran": "S", "pa_warna": "merah" },
        "regular_price": "189000",
        "stock_quantity": 8,
        "weight": "0.30",
        "dimensions": { "length": "30", "width": "25", "height": "3" }
      }
    ],
    "image": { "attachment_id": 4512, "alt_text": "Dress Katun Melati warna merah" },
    "seo": { "title": "Dress Katun Melati | Toko", "description": "Meta description…" }
  },
  "before": null,
  "duplicate_check": { "status": "unique", "matched_product_id": null }
}
```

Untuk `op = "update_product"`, `before` berisi snapshot lengkap field yang akan disentuh (nilai existing dari `WC_Product`) — inilah bahan rollback. `row_key` memberi idempotency per baris (§5.4).

### 3.4 `{$wpdb->prefix}bcs_audit`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `job_id` | BIGINT UNSIGNED NULL | NULL untuk call pra-job (mis. parse gagal) |
| `plan_id` | BIGINT UNSIGNED NULL | |
| `level` | ENUM('call','row') | |
| `ability` | VARCHAR(60) | |
| `action` | VARCHAR(60) | create_product, update_variation, trash_product, approve_plan, rollback_row, … |
| `actor_user_id` | BIGINT UNSIGNED | |
| `actor_via` | ENUM('mcp','wp_admin','wp_cli','system') | |
| `row_index` | INT UNSIGNED NULL | |
| `sku` | VARCHAR(100) NULL | |
| `product_id` | BIGINT UNSIGNED NULL | |
| `status` | ENUM('ok','failed','rolled_back','skipped') | |
| `payload_digest` | CHAR(40) NULL | sha1 payload operasi (bukan isi penuh; jaga ukuran) |
| `message` | TEXT NULL | |
| `created_at` | DATETIME | |

Index: `PRIMARY(id)`, `KEY job_ts (job_id, created_at)`, `KEY product (product_id)`, `KEY level_status (level, status)`.

### 3.5 Options plugin

- `bcs_db_version` — versi skema (untuk migrasi `dbDelta`).
- `bcs_settings` — array konfigurasi: `max_rows_per_job` (default 100, max 500), `max_inline_bytes` (2 MB), `rate_limit_per_minute` (default 60), `allow_sideload` (bool), `allow_url_source` (bool).
- `bcs_rate_<user_id>_<window>` — transient sliding window rate limit (§5.3).

### 3.6 Uninstall bersih (`uninstall.php`)

1. `DROP TABLE IF EXISTS` ketiga tabel `bcs_*`.
2. `delete_option( 'bcs_db_version' )`, `delete_option( 'bcs_settings' )`.
3. Hapus semua transient berprefix `bcs_rate_`.
4. **TIDAK menghapus** CPT `product` / `product_variation`, term, term relationship, attachment, atau meta `_bcs_*` yang melekat pada produk (biarkan meta produk tetap; datanya milik katalog). Bila ingin menyingkirkan meta `_bcs_row_key` di produk, itu tugas terpisah di luar uninstall (non-goals V1).
5. Deaktivasi (bukan uninstall) **tidak melakukan apa pun terhadap data**: tabel dan settings dipertahankan; hanya execution hook yang dilepas. Ini menjamin "dinonaktifkan tanpa kehilangan data".

---

## 4. Alur end-to-end: 1 CSV berisi 30 produk fashion (10 variable, variasi ukuran/warna)

Konteks CSV: 30 baris; 20 simple (1 SKU per baris), 10 variable dengan kolom variasi `attribute:pa_ukuran` (S/M/L) dan `attribute:pa_warna` (merah/biru) → rata-rata 6 kombinasi per produk, total ±60 variasi. 2 SKU sudah ada di katalog.

| # | Aktor | Tool / MCP call | Payload inti | Hasil |
|---|---|---|---|---|
| 1 | Claude | `bcs-parse-source` | `{ "source_type": "attachment", "attachment_id": 8891, "source_name": "fashion-oktober.csv", "delimiter": ",", "column_mapping": { "Nama": "name", "Deskripsi": "description", "Ringkasan": "short_description", "SKU": "sku", "Harga": "regular_price", "Stok": "stock_quantity", "Berat(kg)": "weight", "P": "dimensions.length", "L": "dimensions.width", "T": "dimensions.height", "Kategori": "categories", "Tag": "tags", "Ukuran": "attribute:pa_ukuran", "Warna": "attribute:pa_warna", "SKU Variasi": "variation_sku", "Harga Variasi": "variation_regular_price", "Stok Variasi": "variation_stock_quantity", "URL Gambar": "image_url", "Alt Gambar": "image_alt", "SEO Title": "seo_title", "SEO Desc": "seo_description" } }` | `{ job_id: 7, total_rows: 30, warnings: ["baris 14: stok kosong → default 0", "baris 22: harga non-numerik 'seribu'"] }` |
| 2 | Claude | `bcs-check-duplicates` | `{ "job_id": 7, "strategy": "sku+title", "on_duplicate": "flag_only" }` | `{ duplicate_count: 2, existing_matches: [ {row_index: 5, sku: "DRS-KTN-MLT-001", existing_product_id: 1188, match_on: "sku"}, {row_index: 19, sku: "KML-GRS-BRU-002", existing_product_id: 1204, match_on: "sku"} ], intra_batch_duplicates: [] }` |
| 3 | Claude → manusia | (tanpa tool) | Claude merangkum: 30 produk, 28 create, 2 duplikat — "mau di-skip atau di-update?" | Keputusan manusia: baris 5 di-update, baris 19 di-skip |
| 4 | Claude | `bcs-build-plan` | `{ "job_id": 7, "on_duplicate": "update", "defaults": { "status": "draft", "manage_stock": true, "attribute_scope": "global_pa", "max_variations_per_product": 100 } }` — Claude menyaring: karena keputusan per-baris dibutuhkan, untuk baris 19 Claude rebuild plan setelah konfirmasi (atau memilih `update` lalu mengubah op jadi `skip_duplicate` di plan — implementasi: build-plan menerima daftar `row_overrides` opsional pada input; tambahkan property `row_overrides: {row_index, op: skip|update|create}` opsional ke §1.3) | `{ plan_id: 9, plan_hash: "9f2c…", summary: { create_simple: 20, create_variable: 10, create_variations: 61, update_products: 1, skip_duplicates: 1, total_operations: 32 } }` |
| 5 | Manusia | Browser wp-admin | Buka **Products → Bulk Catalog Studio → Job #7**, lihat diff preview (28 create draft + 1 update dengan before/after + 1 skip). Layar menampilkan `Token approval: BCS-7K4M2` | Manusia membaca token |
| 6 | Manusia → Claude | (chat) | "Sudah kucek, token BCS-7K4M2, lanjutkan" — ATAU manusia klik tombol Approve di layar yang sama (salah satu saja) | Token dikonsumsi sekali |
| 7 | Claude | `bcs-approve-plan` | `{ "plan_id": 9, "plan_hash": "9f2c…", "confirmation_token": "BCS-7K4M2" }` | `{ status: "approved", approved_by: 5, approved_at: "…" }` |
| 8 | Claude | `bcs-execute-batch` | `{ "plan_id": 9 }` | Server eksekusi chunk: 32 operasi, ±61 variasi → `{ status: "done", counts: { products_created: 30, products_updated: 1, variations_created: 61, skipped: 1, failed: 0, total_rows: 32 } }` |
| 9 | Claude | `bcs-job-status` | `{ "job_id": 7 }` | Verifikasi `done`, counter cocok |
| 10 | Claude | `bcs-audit-log` | `{ "job_id": 7, "level": "row", "limit": 50 }` | Jejak per-row untuk laporan ke manusia |
| 11 | Claude → manusia | (chat) | Ringkasan akhir + tautan admin + catatan "2 SKU duplikat ditangani: 1 update, 1 skip; semua produk berstatus draft, silakan review lalu publish" | Selesai |

Skenario rollback (jika manusia menemukan masalah di langkah 11):

| # | Aktor | Tool | Payload | Hasil |
|---|---|---|---|---|
| 12 | Claude | `bcs-rollback-batch` | `{ "job_id": 7, "confirm": true, "scope": "all" }` | 30 produk baru → trash; 1 produk update → field dikembalikan ke snapshot `before`; `{ counts: { products_trashed: 30, products_restored: 1, variations_trashed: 61, failed: 0 } }` |

Catatan kegagalan parsial: bila 3 variasi gagal (mis. SKU variasi duplikat), `execute-batch` tetap menyelesaikan baris lain lalu mengembalikan `status: "partially_failed"` + daftar `failures[]`; job tetap rollback-able.

---

## 5. Model keamanan

### 5.1 User khusus MCP

- Buat user WP `mcp-catalog` (role kustom `bcs_operator`) — BUKAN administrator.
- Capability role `bcs_operator`: `read`, `edit_products`, `manage_product_terms`, `upload_files` (bila sideload aktif), `manage_woocommerce` (untuk ability write; lihat 5.2).
- Tidak punya: `manage_options`, `install_plugins`, `edit_theme_options`, `edit_pages`, `delete_users`, `manage_network`.
- Autentikasi: Application Password milik `mcp-catalog`, dipakai `@automattic/mcp-wordpress-remote` (`WP_API_URL=https://cf.djavaweb.com/wp-json/mcp/mcp-adapter-default-server`). Password tidak pernah disimpan plugin.
- Mekanisme MCP Adapter default: ability private-by-default; hanya `bcs/*` dengan `meta.mcp.public = true` yang tampil. Permission check tetap jalan per-ability (transport bukan pengganti otorisasi) — sesuai best practice resmi.

### 5.2 Capability mapping per ability

| Ability | Capability | Alasan |
|---|---|---|
| parse-source, check-duplicates, build-plan, job-status, audit-log | `edit_products` | Read-only katalog; audit harus bisa dibaca editor |
| approve-plan, execute-batch, rollback-batch | `manage_woocommerce` | Menulis data komersial (harga, stok) — baseline dari dokumen big picture |

Catatan desain: `manage_woocommerce` masih luas (mencakup order, coupon). Mitigasi di 5.5 memastikan kode ability hanya bisa menyentuh produk. Custom capability `bcs_execute` sebagai baseline lebih ketat ditunda ke V2 (open question #3).

### 5.3 Rate limit & batas ukuran

| Batas | Nilai default | Enforces di |
|---|---|---|
| Baris per job | 100 (maks konfig 500) | parse-source |
| Inline CSV | 2 MB | parse-source |
| Attachment CSV | 10 MB | parse-source |
| Variasi per produk | 100 (batas WC) | build-plan |
| Panggilan ability per user | 60/menit sliding window (transient) | semua execute_callback |
| Job `executing` paralel | 1 | execute-batch |
| Chunk eksekusi | 10 produk per iterasi + guard `WP_MAX_EXECUTION_TIME` sisa waktu; sisanya dilanjutkan via resume | execute-batch |
| Approval token | single-use, 24 jam, terikat `plan_hash` | approve-plan |

### 5.4 Idempotency per baris

- `row_key = sha1( source_hash + '|' + row_index + '|' + lower(trim(sku)) )` disimpan di plan dan di meta produk `_bcs_row_key`.
- Eksekusi per-baris: cek hasil sebelumnya di `bcs_audit` untuk `(job_id, row_key, status=ok)` → bila ada, lewati (resume aman).
- `create_product` hanya jadi `update` bila duplikat terdeteksi dan kebijakan `on_duplicate=update` — tidak pernah blind-upsert.
- SKU unik diverifikasi ulang saat eksekusi (`wc_get_product_id_by_sku`) — perlindungan terhadap kondisi balapan antar approval.

### 5.5 Pencegahan penulisan di luar scope produk

1. **Strict input schema** (`additionalProperties: false`) — ability tidak pernah menerima `post_id` arbitrer, SQL, atau hook.
2. Semua penulisan produk lewat **satu service class internal** yang hanya memanggil `WC_Product`, `WC_Product_Variable`, `WC_Product_Variation` CRUD + `wc_get_product_object`; tidak ada `wp_insert_post`, `update_post_meta` langsung (kecuali meta khusus `_bcs_*` dan SEO meta yang di-whitelist), tidak ada `$wpdb->query` tulis.
3. Whitelist post type hasil tulis: `product`, `product_variation`; whitelist taxonomies: `product_cat`, `product_tag`, `pa_*` global. Selain itu → hard error + audit `failed`.
4. Guard WooCommerce aktif: `class_exists( 'WC_Product' )` — bila tidak, seluruh ability write menolak.
5. `permission_callback` diperiksa ulang di dalam setiap `execute_callback` (defense-in-depth, sesuai anjuran resmi MCP Adapter: jangan `__return_true` untuk operasi destruktif).
6. Semua write (termasuk trash saat rollback) teraudit di `bcs_audit` dengan `actor_user_id` + `actor_via`.
7. Token approval terikat `plan_hash`: plan tidak bisa dimodifikasi diam-diam setelah manusia melihat preview.

---

## 6. Acceptance criteria (checklist QA terukur)

| # | Kriteria | Cara uji | Lulus bila |
|---|---|---|---|
| AC-1 | Admin membuat ≥30 produk + variasi dari 1 CSV | Siapkan CSV 30 baris (20 simple, 10 variable ukuran×warna, ±60 variasi); jalankan alur §4 penuh via Claude | `bcs-job-status` = `done`; `count_created = 30`, `count_variations ≥ 60`; semua produk terlihat di daftar Products admin |
| AC-2 | Produk 100% native & editable tanpa AI | Buka 3 sampel produk hasil job di admin WC (1 simple, 2 variable); edit nama, harga, stok, variasi dari UI biasa; publish | Semua field tampil di UI WC standar; edit+publish sukses tanpa plugin BCS aktif pun |
| AC-3 | Preview sebelum apply | Setelah build-plan, buka `diff_preview_url` | Layar menampilkan semua operasi create/update/skip + payload final + before/after untuk update |
| AC-4 | Approval manual wajib | Coba `bcs-execute-batch` pada plan `planned` (belum approved); coba `bcs-approve-plan` tanpa/salah token; coba token kedua kali | Semua ditolak dengan pesan jelas; hanya token valid sekali yang lolos |
| AC-5 | Log perubahan | Setelah eksekusi, tarik `bcs-audit-log` level `row` | Setiap baris punya entri `create_product`/`update_variation` dengan `product_id`, `actor_user_id`, `actor_via=mcp`, status `ok`/`failed` |
| AC-6 | Rollback | Setelah AC-1 lulus, jalankan `bcs-rollback-batch` `scope=all` | 30 produk + variasi masuk trash; 1 produk yang di-update kembali bernilai persis snapshot `before` (verifikasi 5 field acak); audit berisi `rolled_back` per baris |
| AC-7 | Deaktivasi tanpa kehilangan data | Deactivate plugin → verifikasi tabel `bcs_*` & settings masih ada, produk tetap utuh → reactivate → `bcs-job-status` masih membaca job lama | Tidak ada data hilang; plugin berfungsi lagi |
| AC-8 | Uninstall bersih | Delete plugin | Tabel `bcs_jobs`, `bcs_plans`, `bcs_audit` dan options `bcs_*` hilang; produk/attachment TIDAK terhapus |
| AC-9 | Semua aksi tulis ber-permission + audit | Dengan user `mcp-catalog` coba seluruh 8 ability; lalu dengan user editor biasa (tanpa `manage_woocommerce`) coba ability write | Read ability lolos; write ability lolos hanya untuk `mcp-catalog`; editor ditolak 403; setiap write sukses menghasilkan entri audit |
| AC-10 | Deteksi duplikasi | CSV berisi (a) SKU yang sudah ada, (b) 2 baris SKU sama | `check-duplicates` menemukan keduanya (existing + intra-batch), hasil tampil di preview, kebijakan skip/update dijalankan sesuai plan |
| AC-11 | Idempotency & resume | Kill request di tengah `execute-batch` (mis. `timeout`); tunggu >5 menit; panggil ulang dengan `resume=true` | Tidak ada produk ganda; baris yang sudah sukses dilewati; job berakhir `done` |
| AC-12 | Batas & scope | CSV 600 baris; input dengan `post_id` arbitrer di payload; craft input di luar schema | parse-source menolak >500 baris; schema validation menolak field liar; tidak ada tabel non-produk tersentuh (verifikasi via audit + checksum opsional) |

Target performa (bukan hard gate, tapi diukur): job 30 produk + 60 variasi selesai dalam 1 panggilan `execute-batch` pada hosting umum tanpa `failed` karena timeout (chunk + resume menjamin dapat diselesaikan meski >1 request).

---

## 7. Non-goals V1

1. **Aksi tulis MCP tanpa approval** — larangan eksplisit big picture; tidak akan pernah ada ability write yang melewati gerbang token.
2. **Agentic checkout** / pemrosesan order / coupon / customer via BCS.
3. **Headless storefront** atau frontend apa pun dari plugin (pure admin + MCP).
4. **Multi-vendor / marketplace** (Dokan, WCFM, dsb.).
5. **Generasi/edit gambar AI** — BCS hanya mengaitkan attachment yang sudah ada atau (bila diizinkan) sideload URL; alt text berasal dari data, bukan AI.
6. **Sinkronisasi live Google Sheets dua arah** — V1 hanya export CSV publik; OAuth Sheets ditunda.
7. **AI copywriting di sisi plugin** — perbaikan teks/terjemahan adalah ranah Claude di sisi klien sebelum parse.
8. **Multi-site / multisite sync**, WP-CLI mass import di luar MCP, integrasi Novamira, MCP WooCommerce resmi sebagai jalur bulk (tetap hanya pelengkap operasi satuan).
9. **Produk eksternal/affiliate, grouped, subscription, booking** — V1 hanya `simple` dan `variable`.
10. **Custom capability `bcs_execute`** sebagai pengganti `manage_woocommerce` — V2.

---

## 8. Open questions untuk orchestrator (maks 5)

1. **Google Sheets V1**: cukup URL export CSV publik (read-only, tanpa OAuth), atau harus ada integrasi OAuth sekarang juga? Rekomendasi: publik CSV dulu.
2. **Channel approval**: token one-time hanya di layar admin, atau perlu juga dikirim via email/notifikasi ke admin saat build-plan selesai? Rekomendasi: admin screen dulu; email murah ditambah belakangan.
3. **Baseline capability write**: tetap `manage_woocommerce` (sesuai dokumen big picture) atau lock custom cap `bcs_execute` sejak V1 untuk prinsip least-privilege penuh?
4. **Gambar**: aktifkan `sideload_url` sejak V1 (butuh `upload_files` + batas domain whitelist), atau `attachment_id_only` saja di V1?
5. **Rollback produk baru**: default `trash` (soft, recoverable) sudah final, atau perlu opsi permanent delete terkontrol untuk kepatuhan tertentu?

---

## 9. Sumber riset (fetched)

- https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/ — kontrak `wp_register_ability` + `meta.mcp.public`, default server, transport HTTP + `@automattic/mcp-wordpress-remote` + Application Password, security best practices (dedicated user, permission_callback ketat, hindari `__return_true` untuk operasi destruktif).
- https://github.com/wordpress/mcp-adapter — ability private by default; opt-in via `meta.public` / `meta.mcp.public`; endpoint `/wp-json/mcp/mcp-adapter-default-server`; tiga meta-tools discover/get-info/execute.
- https://rudrastyh.com/woocommerce/create-product-variations-programmatically.html — `WC_Product_Variation` CRUD sebagai cara benar membuat variasi; atribut variasi bertaxonomy berprefix `pa_`; `wp_insert_post` tidak direkomendasikan; dedup variasi via SKU/atribut.
- https://developer.woocommerce.com/docs/apis/rest-api/v2/product-variations/ — kontrak data variasi (SKU, harga, stok, dimensi per variasi) yang diikuti plan payload.
