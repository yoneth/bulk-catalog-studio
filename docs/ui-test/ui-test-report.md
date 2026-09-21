# UI Testing — Bulk Catalog Studio di cf.djavaweb.com

Tanggal: 2026-09-21  
Metode: Orca embedded browser (tab khusus, tidak mengganggu tab lain), snapshot accessibility + screenshot PNG.  
Session login: `rio` (admin) via pane Orca yang sudah login.

## Ringkasan

**Verdict: PASS** — semua layar admin BCS berfungsi, data konsisten dengan hasil E2E, dan produk hasil import terbukti native & editable lewat UI WooCommerce. Dua temuan minor (bukan blocker) dicatat di bawah.

## Hasil per layar

| # | Layar / aksi | Hasil | Bukti |
|---|---|---|---|
| 1 | Menu **Bulk Catalog** (bcs-jobs) | Tampil: heading, deskripsi pipeline, tabel job | `01-jobs-list.png` |
| 2 | Baris job #1 | status `rolled_back`, sumber `sample-fashion-30.csv (inline_csv)`, 30 baris, counter `27 / 0 / 60 / 3 / 0`, timestamp, link Diff preview + Audit | snapshot |
| 3 | **Detail job** (#1) | Sumber, source hash (sha1), total baris, counter, "Catatan Claude" (notes dari parse-source), tabel Plans (#2 v1 rolled_back) + link Diff preview + "Lihat audit log" | snapshot |
| 4 | **Diff preview plan #2** | Judul `Plan #2 (v1) — rolled_back`, ringkasan `17 simple / 10 variable / 60 variasi / 0 update / 3 skip / 30 total`, `plan_hash` (103c7fde…), tabel materialized diff (Baris, Op, Tipe, SKU, Nama, Harga/Stok, Var., Target, Detail) | `02-plan-diff.png` |
| 5 | Expand detail baris | Disclosure "kategori & tag" → `Kategori: Pria > Kemeja, Tag: flanel, katun` (render per-baris benar) | snapshot |
| 6 | **Audit log** (86 entri) | Filter `all` / `call` / `row`; kolom ID, Waktu, Level, Ability, Aksi, Baris, SKU, Produk, Status, Aktor, Pesan; entri termasuk `failed` saat eksekusi ditolak sebelum approval; filter `row` → 67 entri create_product dgn product_id & aktor `#2 via mcp` | `03-audit-log.png` |
| 7 | **Products → Trash** | `Trash (28)`; daftar produk hasil job lengkap native (SKU, stok, kategori, Restore/Delete/Duplicate); All (0) | `04-products-trash.png` |
| 8 | Edit screen produk trashed | Error standar WP: "You cannot edit this item because it is in the Trash" — perilaku benar (soft-delete) | snapshot |
| 9 | **Restore produk #77** (via UI trash list) | Restore berhasil, redirect ke All Products | snapshot |
| 10 | **Edit produk variable #77** (Dress Katun Melati 01) | Editor native: Product data = **Variable product**, tab Inventory/Shipping/Attributes/**Variations**/Advanced, Default Form Values Ukuran (S/M/L) + Warna (Biru/Merah), **6 variations** (#83 L Biru, #82 L Merah, …), bulk actions lengkap | `05-product-variations.png` |
| 11 | **Edit produk simple #60** (Kemeja Flanel Pria 01) | Regular price 159000 → diisi **169000** → **Publish** → terverifikasi via `wc_get_product(60)`: `status=publish price=169000` | snapshot + WP-CLI |
| 12 | Console errors | 0 error pada halaman BCS | orca console |
| 13 | Pagination audit | 86 entri < limit 100 → 1 halaman, pagination tidak muncul (logika benar) | snapshot |

## Temuan (semua MINOR / NIT)

1. **NIT — `paged` out-of-range tidak di-clamp**: URL manual `view=audit&job=1&paged=1` (limit 100, total 86) menampilkan "Tidak ada entri." tanpa pagination untuk kembali. Filter level (`all/call/row`) me-reset `paged=0` sehingga user selalu bisa pulih; saran: clamp `paged` ke halaman terakhir yang valid atau tampilkan notice "Halaman di luar rentang". Tidak ditemukan via klik normal — hanya via URL manual.
2. **NIT — slug permalink produk restored**: setelah Restore, slug menjadi `__trashed-18` (perilaku standar `wp_trash_post`/`untrash`). Bukan bug plugin; saran dokumentasi: ingatkan user edit slug setelah restore.
3. **Info — tidak ada halaman Settings terpisah**: hanya ada satu menu `bcs-jobs` (settings tidak ada di V1; semua opsi lewat payload ability). Bukan bug.

## Konfirmasi acceptance (UI-side)

- AC-2 (produk 100% native & editable tanpa AI): **PASS** — edit harga + publish via UI WooCommerce standar; variasi terbaca penuh di tab Variations; verifikasi via `WC_Product` API. Setelah itu state dikembalikan (produk 60 & 77 di-trash lagi; trash tetap 28).
- AC-3 (preview): **PASS** — diff preview lengkap dgn plan_hash.
- AC-5 (audit): **PASS** — halaman audit + filter level + aktor.
- AC-6 (rollback visible): **PASS** — 28 produk di trash UI, recoverable.

## Artefak

- Screenshot: `docs/ui-test/01..05*.png`
- Catatan: selama pengujian, tab Orca lain milik user (Google Cloud/MFA) tidak disentuh; tab uji khusus dibuat & ditutup.
