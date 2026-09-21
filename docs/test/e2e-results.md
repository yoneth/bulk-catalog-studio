# Bulk Catalog Studio — Hasil E2E Fase D

Tanggal eksekusi: 2026-09-21  
Target: `https://cf.djavaweb.com`  
Endpoint MCP: `https://cf.djavaweb.com/wp-json/bcs-mcp/mcp`  
Server: `Bulk Catalog Studio MCP` v1.0.0

## Ringkasan

**Verdict runtime: PASS, dengan tiga acceptance test destruktif/manual yang tidak dijalankan penuh di situs target (AC-2 UI edit, AC-8 uninstall, AC-11 kill/resume nyata).** Jalur utama parse → duplicate check → preview → approval manual → dry-run → execute → audit → rollback lulus tanpa baris gagal.

- Job: `1`
- Plan aktif yang disetujui: `2`
- Input: 30 baris fixture fashion
- Duplikat: 1 grup intra-batch (2 baris) + 1 SKU existing
- Hasil plan: 27 produk dibuat, 60 variasi dibuat, 3 baris dilewati
- Eksekusi nyata: `done`, `failed=0`, durasi 4.982 ms
- Audit row setelah execute: 40 entri; 27 `create_product` via MCP
- Rollback: 27 produk + 60 variasi masuk trash; 0 produk job tersisa live
- Produk seed non-job terbukti tidak tersentuh rollback, lalu ditrash terpisah saat cleanup
- Status akhir job: `rolled_back`; plugin tetap aktif

Catatan: AC-1 dalam spesifikasi menyebut `count_created=30`, tetapi fixture acceptance sengaja berisi duplikat dan test menambahkan satu SKU existing. Kebijakan konservatif `skip` melewati ketiga baris tersebut; karenanya hasil yang benar untuk fixture ini adalah 27 produk + 60 variasi dari 30 baris sumber.

## Bukti smoke dan transport

| Pemeriksaan | Hasil |
|---|---|
| WordPress 7.1.1 dapat dijangkau | PASS |
| WooCommerce, mcp-adapter, dan BCS aktif | PASS |
| Tiga tabel `wp_bcs_*` tersedia | PASS |
| Endpoint tanpa autentikasi | HTTP 401, PASS |
| MCP initialize dengan Application Password | HTTP 200 + session ID, PASS |
| `serverInfo.name` | `Bulk Catalog Studio MCP`, PASS |
| `tools/list` | Tepat 8 tool individual `bcs-*`, PASS |
| Meta-tool/default route Novamira tidak digunakan | PASS |
| `@automattic/mcp-wordpress-remote` initialize + tools/list | PASS |

## Acceptance criteria

| AC | Status | Bukti |
|---|---|---|
| AC-1 bulk import | **PASS (disesuaikan fixture)** | Job `done`; 30 baris diproses; 27 produk + 60 variasi; 3 intentional skips; 0 gagal. |
| AC-2 WooCommerce native | **PASS API / UI manual belum dilakukan** | Tiga SKU terbaca; produk variable `DRS-KTN-001` terbaca sebagai `WC_Product` dengan 6 child; atribut global `pa_ukuran` dan `pa_warna` tersedia. Edit/publish lewat UI belum dijalankan karena data kemudian di-rollback. |
| AC-3 preview | **PASS** | Plan #2 menampilkan diff di admin; manusia mengambil token dari halaman preview sebelum approval. |
| AC-4 approval wajib | **PASS** | Execute nyata sebelum approval ditolak; token valid manual lolos sekali; percobaan ulang ditolak pada state terminal. Token tidak pernah dicatat di laporan/repo. |
| AC-5 audit | **PASS** | 40 audit row; 27 `create_product` memiliki `product_id` dan `actor_via=mcp`; rollback menghasilkan 27 entri `rolled_back`. |
| AC-6 rollback | **PASS untuk create-only** | 27 produk + 60 variasi di trash, 0 produk job live, seed non-job aman. Restore snapshot update tidak terpicu karena policy plan adalah `skip`; telah dicakup harness integration. |
| AC-7 deactivate/reactivate | **PASS runtime** | Sebelum/saat nonaktif/setelah aktif tetap `88 products+variations / 1 job / 2 plans / 84 audit`; plugin berfungsi kembali. |
| AC-8 uninstall | **NOT RUN di cf** | Runbook melarang uninstall pada target. `uninstall.php` direview dan lint; pengujian destruktif wajib dilakukan di staging terpisah. |
| AC-9 permission | **PASS, mode lebih ketat** | `mcpcatalog` (shop_manager) menjalankan seluruh alur; editor sementara ditolak pada transport dengan HTTP 403 lalu user dihapus. Custom server tidak mengekspos read tools kepada editor tanpa `manage_woocommerce`. |
| AC-10 duplicate detection | **PASS** | `duplicate_count=2`: satu grup intra-batch + satu existing; preview menerapkan policy skip; tidak ada produk ganda. |
| AC-11 idempotency/resume | **PASS harness; kill/resume nyata NOT RUN** | Re-execute plan terminal ditolak `isError=true`; jumlah post tetap 88→88. Harness R10 memvalidasi resume tanpa double-count. Kill request + tunggu 5 menit tidak dijalankan di target. |
| AC-12 limits/schema | **PASS runtime + harness** | CSV 600 baris ditolak `isError=true` dengan batas 500. Strict schema/additionalProperties dan field liar tercakup harness. |

## Temuan runtime dan perbaikan

1. **Harness scalar WP-CLI:** `wp db query` menambah baris kosong; `tail -n 1` membuang hasil. Helper diubah untuk mengambil baris non-kosong terakhir.
2. **State response Bash:** assignment `BCS_LAST_RESPONSE` hilang di command substitution subshell. Respons lengkap kini disimpan di file sementara lintas-subshell.
3. **Ekspektasi fixture:** seed existing + dua anggota grup duplikat berarti 27 create/3 skip, bukan 28/2.
4. **Tes AC-4:** negative test semula memakai `dry_run=true`; diperbaiki menjadi execute nyata. Dry-run bukan aksi tulis dan tetap dipakai setelah approval.
5. **Bug output rollback:** callback sukses mengembalikan `note=null`, tetapi output schema mewajibkan string. Service diperbaiki agar mengembalikan string kosong. Karena validasi output terjadi setelah callback, rollback pertama sudah selesai; verifikasi idempoten mengonfirmasi hasilnya. Ditambah test regresi `note` selalu string.

## Test suite final

- PHP lint: seluruh file plugin + harness lulus.
- Parser: **33 PASS**.
- Integration: **135 PASS**.
- Custom MCP server: **26 PASS**.
- Missing dependency guards: **7 PASS**.
- Total: **201 PASS / 0 FAIL**.
- Skrip shell: `bash -n tests/e2e/*.sh` lulus.

## Status akhir dan pekerjaan opsional

Sistem siap dipakai Claude melalui `@automattic/mcp-wordpress-remote` dengan endpoint custom `bcs-mcp`. Tiga pengujian yang sengaja belum dilakukan penuh:

1. Edit/publish tiga sampel lewat WooCommerce Admin (AC-2 UI manual).
2. Uninstall bersih pada staging disposable (AC-8).
3. Kill request, tunggu heartbeat stale >5 menit, lalu resume nyata (AC-11 chaos test).
