# Gate 1 — Keputusan Orchestrator: BCS Sync

Tanggal: 21 September 2026
Status dokumen: APPROVED untuk implementasi bertahap (fase 1–4 worker pertama; fase 5–9 menyusul setelah review).

## Hasil review

| Artefak | Sumber | Verdict |
|---|---|---|
| Riset Google Sheets sync | `docs/research/gsheets-sync.md` (341 baris, 29 sumber) | LULUS — 6/6 klaim kritis terverifikasi independen orchestrator; 8 klaim ditandai tidak terverifikasi secara jujur |
| Spesifikasi BCS Sync | `docs/spec/bcs-sync.md` (419 baris) | LULUS — 3 ability baru, state machine run, rantai guard, 15 AC terukur |

## Keputusan Gate-level (dari percakapan dengan Mas Rio, 2026-09-21)

1. **Transport** → Fetcher interface dengan dua implementasi V1: `csv_export` (dev/internal,
   nol setup) dan `sheets_api` (produksi, kontrak API resmi, privat). Apps Script push = V2.
   Real-time bukan kebutuhan V1 (Drive push dibatch 3 menit; polling 1 menit memadai).
2. **Delete** → Hanya via kolom `sync_action=delete` eksplisit (soft/trash). Baris hilang
   dari sheet = flag review, tidak pernah auto-delete.
3. **Konflik** → `flag_only` (keputusan #3): edit manual WP terdeteksi via
   `post_modified > _bcs_synced_at` → produk ber-flag, sync tidak menulis, resolve manual
   di layar Conflicts.
4. **Threshold 3 lapis** (per koneksi, bukan konstanta):
   - run create+update: tahan bila > `max(50 op, 10% katalog)`;
   - run delete: tahan bila > 10/run;
   - field-level quarantine: harga delta ±80%, stok turun >90%, harga ≤ 0, SKU liar.
5. **Update variasi wajib V1** (keputusan #5): writer `update_variation` + snapshot before
   per variasi + diff level baris variasi. Konsekuensi diterima: scope writer bertambah.

## Keputusan turunan yang diambil orchestrator saat penulisan spec (disetujui di gate ini)

6. **Lock** → `GET_LOCK('bcs_sync_{id}', 0)` MySQL named lock (atomik server-side),
   bukan transient (core mengakui transient lock "attempts to be atomic"). Rilis di `finally`.
7. **Run supersede** → run `awaiting_approval` yang belum diapprove digantikan run baru;
   `diff_hash` berubah → token lama otomatis gugur (paralel perilaku plan `superseded`).
8. **SA key** → disimpan terenkripsi AES-256-CBC, kunci turunan
   `hash('sha256', AUTH_KEY . DB_PASSWORD)`; tidak ada secret plaintext di DB.
9. **Atribut variasi immutable setelah create** — ubah struktur variasi = delete + create
   eksplisit; update variasi hanya field skalar (harga/stok/SKU). Menjaga snapshot before
   tetap sederhana.
10. **Guard missing-rows tambahan** → run abort bila SKU hilang > 20% dari `seen_skus`,
    di atas policy flag-only (proteksi sheet ter-filter/terpotong).
11. **Jalur data agent** → edit produk via agent tetap jalur batch BCS (plan → approval →
    execute); `bcs-build-plan` **menolak eksplisit** operasi pada field milik koneksi aktif
    (cek `seen_skus`) — kegagalan loud, mencegah penulis ganda.
12. **Scheduling** → WP-CLI worker `wp bcs sync run-due` via system cron VPS tiap menit;
    plugin tidak memasang WP-Cron sendiri.
13. **Audit** → kolom baru `source` (`batch|sync`) di `wp_bcs_audit`; `job_id` dipakai ulang
    untuk run id. Satu mekanisme audit/rollback untuk dua pipeline.
14. **Approval run** → generalisasi `BCS_Approval_Token`: token terikat `diff_hash` run,
    transisi state run atomik dulu, baru konsumsi token (pola MAJOR-2).

## Lingkup worker pertama (fase 1–4 dari §12 spec)

Skema DB (`bcs_connections`, `bcs_sync_runs`, kolom `source` audit, meta baru) →
fetcher interface + `csv_export` → diff engine + guard + state machine run →
writer `update_variation` + snapshot before variasi → unit test harness
(`tests/test-bcs-sync.php`, pola stub WP) → `php -l` bersih.

## Larangan untuk worker (tetap berlaku semua fase)

- JANGAN sentuh VPS/SSH; deploy ranah orchestrator.
- JANGAN commit ke git — orchestrator review dulu.
- PHP 7.4 compatible ketat: dilarang `match`, enum, readonly, named args, union types,
  `str_contains`/`str_starts_with`/`str_ends_with`, arrow-fn aman (7.4) tapi konsisten gaya lama.
- WordPress coding standards: `$wpdb->prepare`, escaping, nonce, text domain
  `bulk-catalog-studio`; teks user-facing bahasa Inggris.
- Jangan ubah perilaku 8 ability existing sebelum smoke ulang harness MCP server.
