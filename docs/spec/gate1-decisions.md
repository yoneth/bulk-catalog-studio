# Gate 1 — Keputusan Orchestrator (Fase A → Fase B)

Tanggal: 21 September 2026
Status dokumen: APPROVED untuk implementasi (dengan keputusan open questions di bawah)

## Hasil review

| Artefak | Sumber | Verdict |
|---|---|---|
| Riset API Abilities + MCP Adapter | `docs/research/abilities-mcp-api.md` (980 baris, 14 sumber) | LULUS — signature terverifikasi, gotchas lengkap |
| Spesifikasi Bulk Catalog Studio | `docs/spec/bulk-catalog-studio.md` (779 baris) | LULUS — 8 ability, state machine, 12 AC terukur |

## Keputusan atas 5 open questions

1. **Google Sheets V1** → Hanya URL export CSV publik (read-only, tanpa OAuth). Sesuai rekomendasi spec.
2. **Channel approval** → Token one-time hanya di layar admin WP. Email/notifikasi ditunda V1.1.
3. **Capability write** → `manage_woocommerce` untuk V1 (konsisten dokumen big picture + AC-9). Custom cap `bcs_execute` tetap V2.
4. **Gambar** → `attachment_id_only` untuk V1. Tidak ada `sideload_url` di V1 (prinsip least-privilege; sideload + whitelist domain masuk V1.1). Alt text tetap diisi dari data CSV ke attachment yang dirujuk.
5. **Rollback produk baru** → `trash` (soft) sebagai satu-satunya opsi V1. Final.

## Catatan environment untuk Fase B (dari recon VPS)

- Webroot situs target: `/home/runcloud/webapps/cf` (app RunCloud `cf` = cf.djavaweb.com).
- WP-CLI tersedia di VPS: `/usr/local/bin/wp`.
- PHP CLI VPS: 7.4.33 → **kode WAJIB kompatibel PHP 7.4** (tanpa syntax 8.0+: no `match`, no named args, no constructor promotion, no readonly).
- MCP Adapter default server sudah aktif di situs (`/wp-json/mcp/mcp-adapter-default-server` → 401 tanpa auth).
- Deploy ke VPS adalah ranah orchestrator (Fase D) — developer scout DILARANG menyentuh VPS.
