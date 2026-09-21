# Riset Teknis: BCS Sync (Google Sheets → WooCommerce via Bulk Catalog Studio)

> **Status:** Riset tahap perencanaan (fitur belum diimplementasikan).
> **Metode:** Web research via TinyFish (search → fetch). Semua klaim penting dilengkapi URL sumber yang berhasil di-fetch pada tanggal riset. Klaim yang GAGAL diverifikasi ditandai eksplisit dengan ⚠️ **TIDAK TERVERIFIKASI**.
> **Arah sinkronisasi:** one-way — Google Sheets adalah sumber (source of truth), katalog WooCommerce adalah tujuan (destination).

---

## Area 1 — Perilaku CSV Export Publik Google Sheets

### 1.1 Format URL export dan perilaku `gid`

- Format URL yang benar:
  `https://docs.google.com/spreadsheets/d/{spreadsheet_id}/export?format=csv&gid={gid}`
  Parameter `format` mendukung `csv`, `tsv`, `xlsx` (default bila tidak diisi), `pdf`, `ods`, dan `zip` (HTML ter-zip).
  Sumber: <https://spreadsheet.dev/comprehensive-guide-export-google-sheets-to-pdf-excel-csv-apps-script>, <https://community.latenode.com/t/how-to-extract-data-from-google-sheets-using-python-without-authentication/32545>
- `gid` mengidentifikasi **sheet (tab) spesifik** di dalam spreadsheet. Nilainya bisa dibaca dari URL editor (`.../edit#gid=1648375567`) atau via Apps Script `sheet.getSheetId()`.
  Sumber: <https://technonotes.no/posts/exportsheettopdf/>, <https://spreadsheet.dev/comprehensive-guide-export-google-sheets-to-pdf-excel-csv-apps-script>
- **Jika `gid` dihilangkan pada export CSV, hasilnya sheet pertama** — laporan lapangan pengguna yang salah ambil data "berminggu-minggu" karena ini.
  Sumber: <https://community.latenode.com/t/how-to-extract-data-from-google-sheets-using-python-without-authentication/32545>
- Dokumentasi export ini **tidak resmi** (Google tidak mempublikasikan kontrak endpoint `/export`); parameter diketahui dari praktik komunitas dan bisa berubah tanpa pengumuman.
  Sumber: <https://spreadsheet.dev/comprehensive-guide-export-google-sheets-to-pdf-excel-csv-apps-script> ("there isn't any official documentation for any of these parameters")

### 1.2 Perilaku saat sheet TIDAK publik (kunci untuk guard anti mass-delete)

- Sheet harus di-share minimal **"Anyone with the link can view"**; bila tidak, export URL mengembalikan **error/denied — bukan data**. Praktisi melaporkan mendapat halaman error/akses alih-alih CSV.
  Sumber: <https://community.latenode.com/t/how-to-extract-data-from-google-sheets-using-python-without-authentication/32545>
- Insiden nyata (Nov 2020): sheet yang "published to the web" mendadak **mengalihkan URL CSV/TSV ke halaman login Google** sementara URL `pubhtml` masih jalan. Berlaku lintas akun konsumen dan Workspace, dan baru pulih setelah ±1 minggu. Ini bukti kuat bahwa **endpoint export publik bisa berubah perilaku tanpa notis** — guard wajib ada.
  Sumber: <https://support.google.com/drive/thread/83705117/spreadsheet-published-to-the-web-csv-download-not-working-results-in-access-request-page?hl=en>
- Insiden serupa terulang (Agu 2025): "publish to web" CSV/TSV gagal sedangkan HTML masih accessible; workaround komunitas memakai endpoint alternatif `.../gviz/tq?tqx=out:csv` (dengan `&sheet=[Sheet Name]` untuk sheet tertentu) — syaratnya share "Anyone".
  Sumber: <https://support.google.com/docs/thread/367834351/published-spreadsheet-to-web-html-accessible-but-tsv-csv-download-not-working>
- **Implikasi desain guard:** respons gagal bisa berupa **HTML (halaman login/akses) yang status-nya tidak selalu 4xx**, sehingga pengecekan berdasarkan status code saja tidak cukup. Guard minimal: (a) validasi konten terparse sebagai CSV dengan header kolom yang diharapkan, (b) ⚠️ **TIDAK TERVERIFIKASI**: nilai persis header `Content-Type` yang dikembalikan endpoint `/export` (diduga `text/csv`, tapi tidak ada sumber yang berhasil di-fetch yang membuktikannya) — gunakan sniffing konten, jangan percaya header saja.

### 1.3 Rate limit, ukuran, encoding, formula

- **Rate limit export CSV publik tidak dipublikasikan secara resmi.** Praktisi melaporkan error `429` bila menarik terlalu sering, dan merekomendasikan delay antar-request.
  Sumber: <https://community.latenode.com/t/how-to-extract-data-from-google-sheets-using-python-without-authentication/32545> (bukti komunitas, bukan angka resmi). ⚠️ **TIDAK TERVERIFIKASI**: angka QPS/RPM pasti untuk endpoint `/export`; angka resmi hanya ada untuk Sheets API (lihat Area 3).
- **Batas data spreadsheet:** maksimum **10 juta cell atau 18.278 kolom** per spreadsheet (berlaku juga untuk hasil konversi/impor Excel/CSV). Ini batas praktis atas jumlah baris katalog.
  Sumber: <https://support.google.com/drive/answer/37603?hl=en>
- **Encoding:** CSV/TSV hasil export **selalu UTF-8 tanpa BOM**, tanpa opsi setting. Parser PHP di sisi WP sebaiknya tidak mengharapkan BOM dan membaca file sebagai UTF-8.
  Sumber: <https://support.google.com/docs/thread/160652357/export-sheet-as-tsv-encoding-utf-8-with-bom?hl=en>
- ⚠️ **TIDAK TERVERIFIKASI**: perilaku formula pada endpoint `/export` (apakah keluar hasil kalkulasi atau string formula). Yang terverifikasi adalah perilaku **API**: `spreadsheets.values.get` default `valueRenderOption=FORMATTED_VALUE` (nilai terformat, bukan formula). Sumber: <https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets.values/get>
- ⚠️ **TIDAK TERVERIFIKASI**: TTL cache endpoint export dan perlunya cache-buster. Tidak ditemukan sumber yang berhasil di-fetch yang membahas header cache endpoint `/export` secara spesifik. (Laporan komunitas tentang "csv download link works only once" ada di forum Neo4j tetapi tidak berhasil di-fetch.)

### 1.4 Ringkasan keputusan Area 1

| Topik | Temuan berbukti | Implikasi BCS Sync |
|---|---|---|
| URL export | `/export?format=csv&gid=` resmi-dipakai-luas tapi tidak berkontrak | Simpan `spreadsheet_id` + `gid` terpisah; uji koneksi saat save setting |
| Sheet privat | Respons = HTML error/login, bisa 200 | Guard: parse CSV + validasi header kolom sebelum proses |
| Endpoint rapuh | 2 insiden Google-wide (2020, 2025) | Health-check berkala + tombol "Test connection" + error state eksplisit |
| Encoding | UTF-8 tanpa BOM | Parser: `mb_convert_encoding` tidak perlu; jangan trim BOM |
| Batas | 10M cell | Aman untuk katalog ribuan SKU |

---

## Area 2 — Apps Script sebagai Proxy Privat + Push Real-time

### 2.1 Trigger `onEdit` / `onChange`: simple vs installable

- **Simple trigger** `onEdit(e)`: berjalan otomatis saat user mengubah nilai cell, TAPI: maksimal **30 detik** per eksekusi; tidak boleh memanggil service yang butuh otorisasi (termasuk `UrlFetchApp` ke endpoint eksternal dengan header — service eksternal butuh otorisasi); **tidak terpicu oleh edit dari script/API** (`Range.setValue()` tidak memicunya); dan **hanya mengantre sampai 2 trigger event** (eksekusi beruntun bisa terlewati).
  Sumber: <https://developers.google.com/apps-script/guides/triggers>
- **Installable trigger** (`onEdit` maupun `onChange`): berjalan dengan `authMode: FULL` (boleh akses service berotorisasi), punya `triggerUid`, dan tersedia trigger **Change** dengan `changeType` (`EDIT`, `INSERT_ROW`, `REMOVE_ROW`, `FORMAT`, `OTHER`, dll). Maksimum **20 trigger per user per script**.
  Sumber: <https://developers.google.com/apps-script/guides/triggers>, <https://developers.google.com/apps-script/guides/triggers/events>, <https://developers.google.com/apps-script/guides/services/quotas>
- **Perilaku paste massal:** event object `onEdit` berisi `range` yang mencakup **seluruh range yang diedit** (bisa ratusan baris), sedangkan `value`/`oldValue` **hanya tersedia bila range yang diedit satu cell**. Artinya handler yang benar harus membaca `e.range` (row/column count) — bukan `e.value`.
  Sumber: <https://developers.google.com/apps-script/guides/triggers/events>
- **Implikasi:** push dari onEdit **tidak bisa dijamin real-time per-cell** (antrean hanya 2 event pada simple trigger, bisa skip saat paste besar). Desain yang benar: trigger hanya menandai "data berubah" (dirty-flag), dan push dilakukan oleh mekanisme lain (lihat 2.4).

### 2.2 Web App `doPost` + HMAC

- Pola deployment: `doPost(e)` di-deploy sebagai **Web App** (`Deploy > New deployment`), **Execute as: Me**, **Who has access: Anyone** agar bisa menerima POST anonim dari server WP. Kesalahan umum: akses tertinggal "Only myself" → semua request ditolak 401 diam-diam; dan URL `dev` berubah tiap save — yang dipakai harus URL deployment `prod` (`.../exec`).
  Sumber: <https://magesheet.com/blog/apps-script-webhooks-doget>
- Payload POST dibaca dari `e.postData.contents` (string JSON mentah). Untuk HMAC, tanda tangani **raw body** — bukan hasil parse/re-serialize.
  Sumber: <https://magesheet.com/blog/apps-script-webhooks-doget>
- **HMAC-SHA256 di Apps Script:** `Utilities.computeHmacSha256Signature(value, key[, charset])` mengembalikan `Byte[]`; konversi ke hex dilakukan manual (`('0'+(b & 0xff).toString(16)).slice(-2)`). Simpan secret di `PropertiesService.getScriptProperties()` — jangan hardcode. Sisi receiver memverifikasi dengan menghitung ulang hash atas body dan membandingkan header tanda tangan.
  Sumber: <https://developers.google.com/apps-script/reference/utilities/utilities>, <https://magesheet.com/blog/apps-script-webhooks-doget>
- **Anti-replay dengan timestamp** (pola industri, Stripe): timestamp dimasukkan ke dalam payload yang ditandatangani, sehingga replay dengan timestamp lama terdeteksi; receiver menolak bila timestamp lebih tua dari toleransi (default Stripe **5 menit**; jangan set 0). Ini pola yang bisa ditiru untuk push Apps Script → WordPress.
  Sumber: <https://docs.stripe.com/webhooks> (bagian "Preventing replay attacks")
- **Idempotensi receiver:** simpan ID event yang sudah diproses di `CacheService` (TTL maksimum cache 6 jam) + sheet tersembunyi untuk proteksi jangka panjang; percaya ID event, bukan timestamp.
  Sumber: <https://magesheet.com/blog/apps-script-webhooks-doget>

### 2.3 Kuota & batas Apps Script

| Kuota | Consumer (gmail.com) | Google Workspace |
|---|---|---|
| URL Fetch calls (keluar) | 20.000/hari | 100.000/hari |
| Script runtime | **6 menit / eksekusi** | 6 menit / eksekusi |
| Total runtime trigger | 90 menit/hari | 6 jam/hari |
| Trigger | 20 / user / script | 20 / user / script |
| Eksekusi simultan | 30 / user | 30 / user |
| URL Fetch response size | 50 MB/call | 50 MB/call |

Sumber: <https://developers.google.com/apps-script/guides/services/quotas>
Catatan tambahan terverifikasi: webhook **inbound** (`doPost`) tidak menghitung kuota UrlFetch, tetapi tetap menghitung kuota runtime harian; cold-start request pertama bisa **5–8 detik**.
Sumber: <https://magesheet.com/blog/apps-script-webhooks-doget>

### 2.4 Pola debounce/batch push

- Pola yang didukung bukti: **queue-and-drain** — handler trigger (onEdit/onChange) hanya menulis/menandai baris ke antrean (sheet tersembunyi/CacheService) dan langsung return; **time-driven trigger** (mis. tiap 1 menit) mengosongkan antrean dalam satu `UrlFetchApp.fetch` ke WordPress. Pola ini memisahkan latensi respons dari proses berat dan otomatis menggabungkan banyak edit menjadi satu payload.
  Sumber: <https://magesheet.com/blog/apps-script-webhooks-doget> (pola "thin handler + drain queue every minute")
- Alternatif tanpa push sama sekali: Apps Script time-driven trigger melakukan **pull** berkala ke WordPress. Ini menghindari seluruh kompleksitas HMAC-replay dan dipakai produk komersial (FlexSync memakai Apps Script di sheet untuk menjadikan sync "live" ketimbang terjadwal).
  Sumber: <https://flexsync.app/connect-google-sheets-to-shopify/>
- ⚠️ **TIDAK TERVERIFIKASI**: implementasi debounce spesifik (mis. debounce via timestamp `CacheService` + `Utilities.sleep`) — sumber khusus (Stack Overflow/Medium) gagal di-fetch (bot-blocked). Queue-and-drain di atas adalah padanan yang terbukti.

### 2.5 Ringkasan keputusan Area 2

| Topik | Temuan berbukti | Implikasi BCS Sync |
|---|---|---|
| onEdit simple | Antrean 2 event, 30 dtk, tanpa otorisasi | Jangan taruh push di simple trigger |
| onEdit/Change installable | `e.range` multi-cell, authMode FULL | Handler baca range; hanya set dirty-flag |
| Push ke WP | doPost + HMAC + timestamp anti-replay | Secret di ScriptProperties; raw body ditandatangani |
| Kuota | 6 mnt/eksekusi; UrlFetch 20rb/100rb per hari | Push berbasis queue-and-drain per menit aman jauh di bawah kuota |
| Keandalan | Cold start 5–8 dtk; 401 diam-diam | WP harus tolerate timeout; log semua reject |

---

## Area 3 — Google Sheets API v4 via Service Account dari WordPress

### 3.1 Alur service account

- Buat service account di Google Cloud project → buat **key JSON** (file hanya bisa diunduh sekali; simpan aman). **Share spreadsheet ke email service account** via dialog Share biasa (level Viewer/Editor); SA tidak punya inbox, jadi hilangkan "Notify people". Tidak perlu domain-wide delegation untuk akses file spesifik.
  Sumber: <https://developers.google.com/workspace/guides/create-credentials>
- Dokumen resmi menegaskan: untuk membaca file spesifik seperti Google Sheet, cukup share langsung ke email SA; IAM role tidak memberi akses aset Workspace.
  Sumber: <https://developers.google.com/workspace/guides/create-credentials>

### 3.2 Token exchange JWT → access token (feasibility REST manual tanpa library)

- Klaim JWT (header): `{"alg":"RS256","typ":"JWT"}` (opsional `kid`). Klaim wajib: `iss` (email SA), `scope` (dipisah spasi — untuk read-only: `https://www.googleapis.com/auth/spreadsheets.readonly`), `aud` = `https://oauth2.googleapis.com/token`, `iat`, `exp` (**maksimum 1 jam setelah iat**). Signature = **RS256** (SHA256withRSA) atas `base64url(header).base64url(claim)` dengan private key.
  Sumber: <https://developers.google.com/identity/protocols/oauth2/service-account>
- Token request: `POST https://oauth2.googleapis.com/token`, body `application/x-www-form-urlencoded`, parameter `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` dan `assertion=<JWT>`. Access token yang terbentuk dipakai sebagai `Authorization: Bearer`; setelah kedaluwarsa (~1 jam), buat JWT baru.
  Sumber: <https://developers.google.com/identity/protocols/oauth2/service-account>
- **Feasibility PHP native**: Google mendokumentasikan alur penuh ini dalam bentuk bash murni (buat JWT → sign dengan `openssl dgst -sha256 -sign` → `curl` POST form-urlencoded ke endpoint token) pada dokumen IAP — bukti bahwa seluruh mekanisme hanya butuh RSA-sign dan HTTP POST, yang di PHP native tersedia via `openssl_sign()` + `wp_remote_post()`. (Implementasi PHP-nya adalah inferensi desain; mekanisme protokolnya terverifikasi.)
  Sumber: <https://docs.cloud.google.com/iap/docs/authentication-howto>
- Catatan: Google "sangat merekomendasikan" client library karena kompleksitas/risiko penandatanganan JWT, dan menawarkan opsi tanpa library sejak beberapa API menerima signed JWT langsung sebagai bearer — untuk **Sheets API**, pembacaan tetap standar via access token.
  Sumber: <https://developers.google.com/identity/protocols/oauth2/service-account>

### 3.3 Endpoint baca

- `GET https://sheets.googleapis.com/v4/spreadsheets/{spreadsheetId}/values/{range}` — range A1 notation; scope yang dibutuhkan salah satu antara lain `https://www.googleapis.com/auth/spreadsheets.readonly`; default `valueRenderOption=FORMATTED_VALUE` (nilai terformat, bukan formula string).
  Sumber: <https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets.values/get>

### 3.4 Kuota & biaya

| Kuota Sheets API | Nilai |
|---|---|
| Read requests | **300/menit/project**, 60/menit/user/project |
| Write requests | 300/menit/project, 60/menit/user/project |
| Refill | tiap menit; 429 → exponential backoff |
| Rekomendasi payload | ≤ 2 MB per request |
| Timeout per request | 180 detik |
| Biaya | "All standard use ... available at no additional cost" (penagihan untuk kelebihan kuota direncanakan mulai 2026) |
| Service account | dihitung sebagai satu akun |

Sumber: <https://developers.google.com/workspace/sheets/api/limits>
Untuk kasus BCS Sync (pull 1x per menit atau lebih jarang), 300 read/menit/project adalah ruang yang sangat lega.

### 3.5 Push notification tidak tersedia

- **Sheets API v4 tidak menyediakan push/watch untuk spreadsheet**; push notification hanya ada di Drive API dan pengirimannya **dibatasi/dibatch ±3 menit**, dan `modifiedTime` di backend juga hanya refresh ±3 menit.
  Sumber: <https://github.com/googleapis/google-api-go-client/issues/444>
- Implikasi: "real-time" via jalur API murni tidak ada; pilihan nyata adalah (a) Apps Script push (Area 2) atau (b) polling berkala via API (Area 3) / CSV export (Area 1).

---

## Area 4 — Pola Sync Engine Production-grade

### 4.1 Ownership & arah sinkronisasi

- Keputusan paling penting: **satu owner per entitas**. Ketika dua sistem sama-sama boleh menulis entitas yang sama, you have built a conflict generator; last-write-wins menghancurkan data diam-diam. Solusinya bukan conflict-resolution yang lebih pintar, tapi **menghapus konflik dengan tabel ownership** (entity → owner → siapa boleh create/update).
  Sumber: <https://dzone.com/articles/data-sync-design>
- Airtable memodelkan hal yang sama: sync satu arah adalah default; **two-way sync adalah fitur tambahan yang harus diaktifkan eksplisit** (toggle "Allow edits from other bases") — arah one-way adalah baseline yang aman.
  Sumber: <https://support.airtable.com/articles/8435404541-two-way-syncing-in-airtable>
- Implikasi BCS Sync: one-way Sheets→Woo berarti **field katalog yang di-manage sync tidak boleh diedit manual di Woo** (atau edit manual akan tertimpa di run berikutnya). Komunikasi ini penting di UI plugin.

### 4.2 Key-based diff, idempotency, soft-delete

- **Setiap write harus membawa kunci stabil dari sumber** (natural key — untuk katalog: SKU atau row-key), dan destination memperlakukan kunci itu unik (`upsert`). Pola "create tanpa kunci" menduplikasi data pada retry — bug klasik sync.
  Sumber: <https://dzone.com/articles/data-sync-design>
- Bukti industri produk: FlexSync (app Sheets↔Shopify) mengunci kolom **Product ID / Variant ID sebagai read-only karena kolom ID inilah yang dipakai memetakan setiap baris sheet ke produk yang benar** — mengeditnya "would break the connection". Field lain diklasifikasikan editable vs read-only secara eksplisit.
  Sumber: <https://flexsync.app/connect-google-sheets-to-shopify/>
- **Change detection tiga tingkat** (urut preferensi): (1) watermark `updated_at` dari sumber; (2) bila tidak ada timestamp, **hash per record** (mis. SHA-256 dari JSON kanonik) dibandingkan terhadap hash yang disimpan **per record** — hanya record yang hash-nya berubah yang ditulis; (3) full compare terjadwal sebagai pilihan terakhir.
  Sumber: <https://dzone.com/articles/data-sync-design>
- **State per-record vs snapshot global:** desain hash-per-record disimpan di map `{record_id: hash}` adalah **state per-record** — mendukung penuh pendekatan "simpan hash/state per baris (keyed by SKU) di sisi WP, bukan snapshot MD5 seluruh CSV". Snapshot global tidak bisa menjawab "baris mana yang berubah" tanpa diff penuh — dan diff penuh di Tier 3 adalah fallback, bukan pilihan utama.
  Sumber: <https://dzone.com/articles/data-sync-design>
- **Idempotent queue:** antrean perubahan dengan constraint `UNIQUE(entity_type, external_id, status)` mencegah duplikasi enqueue dan membuat antrean ikut idempotent.
  Sumber: <https://dzone.com/articles/data-sync-design>
- **Soft-delete/tombstone & kebijakan delete:** Airtable menjadikan **record deletion sebagai opsi yang default-nya OFF** (toggle "Allow deleting records") bahkan pada two-way sync — delete dari sumber tidak otomatis menghapus di tujuan tanpa konfigurasi eksplisit.
  Sumber: <https://support.airtable.com/articles/8435404541-two-way-syncing-in-airtable>
- **Failure mode "sumber tiba-tiba kosong":** tidak ada sumber yang berhasil di-fetch yang merumuskan guard threshold numerik. ⚠️ **TIDAK TERVERIFIKASI** sebagai praktik terdokumentasi; yang terbukti dari sumber: (a) delete harus eksplisit dan bisa dimatikan (Airtable), (b) delete via batch endpoint WooCommerce memakai `force` hanya bila benar-benar diinginkan (WC REST `delete` default menempatkan ke trash — parameter `force=true` untuk permanent).
  Sumber: <https://support.airtable.com/articles/8435404541-two-way-syncing-in-airtable>, <https://developer.woocommerce.com/docs/apis/rest-api/v3/products/>
  Rekomendasi desain (bukan klaim sumber): guard threshold persentase (mis. tolak run bila baris hilang > X%) + soft-delete (draft/out-of-stock) sebelum hard delete — ditandai sebagai keputusan teknis tanpa bukti publik.

### 4.3 Observability & retry

- Empat hal minimum: **heartbeat** (alert bila run sukses terakhir lebih tua dari interval), **drift metrics** (hitung record dua sisi, alert tren), **dead-letter** (setelah N gagal → status failed + notifikasi manusia dengan payload), **log per record**.
  Sumber: <https://dzone.com/articles/data-sync-design>
- Sender webhook yang andal me-retry dengan exponential backoff (Stripe: hingga 3 hari); redirect dianggap gagal; endpoint harus cepat balas 2xx sebelum logika berat.
  Sumber: <https://docs.stripe.com/webhooks>

---

## Area 5 — Eksekusi Write di WordPress/WooCommerce

### 5.1 REST batch vs CRUD `WC_Product::save()`

- Endpoint batch resmi: `POST /wp-json/wc/v3/products/batch` menerima `create`, `update`, `delete` sekaligus. **Default dibatasi sampai 100 objek** per request.
  Sumber: <https://developer.woocommerce.com/docs/apis/rest-api/v3/products/>
- `WC_Product::save()` per-produk terbukti sangat lambat pada katalog besar: kasus terdokumentasi **satu `save()` memakan 21,9 detik** (atribut product menambah ~10 detik) pada import massal di server 12-core/24GB — total import terestimasi >2 minggu (WC 3.4.4 era; setiap save melewati layer CRUD + hook + meta + invalidation).
  Sumber: <https://github.com/woocommerce/woocommerce/issues/21167>
- Perfoma REST update per-produk juga dilaporkan lambat (2–3 detik per product) pada issue resmi WooCommerce (masih open, label Performance/REST). ⚠️ Catatan: isi thread gagal ter-render penuh saat fetch; angka 2–3 detik berasal dari deskripsi issue.
  Sumber: <https://github.com/woocommerce/woocommerce/issues/26029>
- Panduan praktik: **hindari loop request per-produk** (timeout hampir pasti pada 50+ produk); satukan dalam satu batch request; cache lookup SKU→ID dengan transient karena SKU jarang berubah.
  Sumber: <https://rudrastyh.com/woocommerce/speed-up-rest-api.html>
- **Rekomendasi chunk size:** patuhi limit 100 objek (resmi). Ukuran optimal 25–50 per batch adalah **rekomendasi desain tanpa sumber spesifik** ⚠️ **TIDAK TERVERIFIKASI** — angka komunitas (50–100) ada di snippet pencarian GitHub issue #26029 tetapi komentar tersebut tidak ikut ter-fetch.

### 5.2 Lock anti-overlap untuk worker cron

- WP core sendiri memakai **transient lock `doing_cron`** untuk mencegah spawn ganda: `spawn_cron()` membaca lock; bila lock + `WP_CRON_LOCK_TIMEOUT` (default 60 detik) masih di masa depan → tidak spawn; lock > 10 menit dianggap stale. Kode inti mengakui: "Multiple processes on multiple web servers can run this code concurrently, this lock attempts to make spawning as atomic as possible."
  Sumber: <https://developer.wordpress.org/reference/functions/spawn_cron/>
- Namun lock transient ini **bukan atomik sempurna** — di bawah beban tinggi dua request bisa membaca state terbuka sebelum salah satunya menulis lock; untuk task yang menulis data eksternal, race condition ini adalah bug, bukan nuisance.
  Sumber: <https://wpwebhooks.org/blog/cron-job-for-wordpress/>
- Pola untuk job custom (BCS Sync worker): set transient lock ber-TTL sebelum proses dan hapus/biarkan expired setelahnya; idealnya ditambah mekanisme non-blocking yang lebih atomik (`wp_cache_add` pada object store eksternal / `flock` file) — pola `flock -n` terdokumentasi dipakai SpinupWP untuk cron WP.
  Sumber: <https://spinupwp.com/doc/understanding-wp-cron/> (pola flock), <https://developer.wordpress.org/reference/functions/spawn_cron/> (pola transient lock). ⚠️ Detail implementasi `wp_cache_add`-based lock: **TIDAK TERVERIFIKASI** dengan sumber yang berhasil di-fetch.

### 5.3 WP-Cron vs system cron

- WP-Cron **bukan daemon**; ia terpicu oleh trafik halaman (spawn non-blocking ke `wp-cron.php`). Konsekuensi terverifikasi: site sepi trafik → event tidak jalan; site dengan page cache penuh → cron bisa tidak terpicu sama sekali; `spawn_cron` di-rate-limit maksimal 1x per 60 detik; event panjang bisa dibunuh `max_execution_time` tanpa jejak di debug log; banyak host mem-preset `DISABLE_WP_CRON=true` **tanpa** memasang pengganti → event menumpuk stale tanpa error.
  Sumber: <https://spinupwp.com/doc/understanding-wp-cron/>, <https://wpwebhooks.org/blog/cron-job-for-wordpress/>
- Pola produksi yang direkomendasikan sumber: `define('DISABLE_WP_CRON', true);` + system cron, contoh:
  - `*/5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron` (<https://wpwebhooks.org/blog/cron-job-for-wordpress/>)
  - atau via WP-CLI: `flock -n ~/.wp_cron.lock wp cron event run --due-now --quiet` tiap 5 menit (<https://spinupwp.com/doc/understanding-wp-cron/>)
- Untuk job sinkronisasi katalog (queue persisten + retry + concurrency control), **Action Scheduler** — yang bundle dengan WooCommerce — disebut sebagai fondasi yang lebih andal daripada WP-Cron mentah (>100 plugin memakainya; job tersimpan di DB dan selamat dari crash PHP).
  Sumber: <https://wpwebhooks.org/blog/cron-job-for-wordpress/>

---

## Tabel Ringkasan: Keputusan Teknis yang Didukung Bukti

| # | Keputusan | Arah | Bukti utama | Status bukti |
|---|---|---|---|---|
| 1 | Guard anti mass-delete = validasi konten (parse CSV + header kolom), bukan hanya status code | Area 1 | Respons sheet privat = HTML error/login; 2 insiden endpoint rapuh 2020 & 2025 | ✔ Terverifikasi (bentuk respons); ⚠️ nilai Content-Type persis tidak terverifikasi |
| 2 | `gid` wajib disimpan eksplisit; tanpa `gid` = sheet pertama | Area 1 | latinode (komunitas), spreadsheet.dev, technonotes | ✔ |
| 3 | CSV export = UTF-8 tanpa BOM | Area 1 | Thread resmi Google Docs Editors | ✔ |
| 4 | Jangan taruh push di simple `onEdit`; pakai installable trigger + baca `e.range` | Area 2 | Antrean 2 event, 30 dtk, `value` hanya 1-cell | ✔ |
| 5 | Push Apps Script→WP: `doPost` + HMAC-SHA256 (secret di ScriptProperties) + timestamp ikut ditandatangani (toleransi ±5 menit) + ID event idempoten | Area 2 | Utilities ref, magesheet, Stripe anti-replay | ✔ (pola Stripe terverifikasi; penerapan ke GAS = adopsi pola) |
| 6 | Gunakan queue-and-drain (dirty-flag + drain per menit) alih-alih push per-edit | Area 2 | Kuota antrean 2 event; pola thin-handler magesheet | ✔ |
| 7 | Jalur data alternatif tanpa proxy: Sheets API v4 `values.get` + service account (JWT RS256 → POST token endpoint) via `wp_remote_post` + `openssl_sign` | Area 3 | service-account doc, IAP howto (bash murni), values.get ref | ✔ protokol; PHP native = inferensi feasible |
| 8 | Share sheet langsung ke email service account (Viewer), tanpa domain-wide delegation | Area 3 | create-credentials doc | ✔ |
| 9 | Kuota tidak jadi hambatan: 300 read/menit/project, gratis; SA = 1 user | Area 3 | Sheets API limits | ✔ |
| 10 | Sheets API tidak punya push native; Drive push dibatch ±3 menit | Area 3 | google-api-go-client#444 | ✔ |
| 11 | Diff berbasis **key (SKU) + hash per record**, state per-record di WP — bukan snapshot global | Area 4 | DZone tier-2 change detection (stored_hashes per id) | ✔ |
| 12 | Kunci kolom ID/key bersifat read-only bagi editor sheet (pola FlexSync) | Area 4 | flexsync.app | ✔ |
| 13 | Delete tidak otomatis; soft-delete dulu; hard delete eksplisit (`force`) | Area 4 | Airtable toggle delete default OFF; WC REST `force` | ✔ prinsip; ⚠️ threshold guard numerik tidak terverifikasi |
| 14 | Tulis via `/wc/v3/products/batch` (limit 100/request), hindari loop per-produk | Area 5 | WC docs; issue #21167 (save 22 dtk); rudrastyh | ✔ (chunk 25–50 = ⚠️ judgment) |
| 15 | Worker pakai lock: transient lock ala `doing_cron` + TTL; system cron disarankan | Area 5 | spawn_cron source; wpwebhooks (race condition); spinupwp (flock) | ✔ |
| 16 | `DISABLE_WP_CRON` + system cron (`curl wp-cron.php` atau `wp cron event run --due-now`); opsional Action Scheduler untuk queue persisten | Area 5 | spinupwp, wpwebhooks | ✔ |
| 17 | "Real-time" paling praktis = Apps Script push (detik–menit); jalur API murni = polling | Area 2+3 | #444 (batch 3 mnt); FlexSync pakai Apps Script | ✔ |

### Klaim yang TIDAK berhasil diverifikasi (daftar eksplisit)

1. Nilai persis header `Content-Type` (dan header cache) endpoint `/export` Google Sheets.
2. Angka rate limit resmi endpoint `/export` CSV publik (hanya laporan 429 komunitas; angka resmi hanya ada untuk Sheets API).
3. TTL cache endpoint export / perlunya cache-buster.
4. Perilaku formula pada endpoint `/export` (terverifikasi hanya untuk API: default `FORMATTED_VALUE`).
5. Implementasi debounce `CacheService` pada `onEdit` (sumber SO/Medium bot-blocked; padanan queue-and-drain terverifikasi).
6. Threshold guard mass-change yang terdokumentasi di produk sync populer (prinsip delete-eksplisit terverifikasi di Airtable).
7. Chunk size optimal WooCommerce (25–50) di luar batas resmi 100.
8. Pola lock `wp_cache_add` atomik (sumber umumnya blog 10up dkk.; tidak berhasil di-fetch kali ini).

---

## Daftar URL Sumber (semua berhasil di-fetch)

**Google resmi**
1. <https://developers.google.com/apps-script/guides/triggers>
2. <https://developers.google.com/apps-script/guides/triggers/events>
3. <https://developers.google.com/apps-script/guides/services/quotas>
4. <https://developers.google.com/apps-script/reference/utilities/utilities>
5. <https://developers.google.com/identity/protocols/oauth2/service-account>
6. <https://developers.google.com/workspace/guides/create-credentials>
7. <https://developers.google.com/workspace/sheets/api/limits>
8. <https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets.values/get>
9. <https://docs.cloud.google.com/iap/docs/authentication-howto>
10. <https://support.google.com/drive/answer/37603?hl=en>

**Insiden & komunitas Google Sheets**
11. <https://support.google.com/drive/thread/83705117/spreadsheet-published-to-the-web-csv-download-not-working-results-in-access-request-page?hl=en>
12. <https://support.google.com/docs/thread/367834351/published-spreadsheet-to-web-html-accessible-but-tsv-csv-download-not-working>
13. <https://support.google.com/docs/thread/160652357/export-sheet-as-tsv-encoding-utf-8-with-bom?hl=en>
14. <https://spreadsheet.dev/comprehensive-guide-export-google-sheets-to-pdf-excel-csv-apps-script>
15. <https://technonotes.no/posts/exportsheettopdf/>
16. <https://community.latenode.com/t/how-to-extract-data-from-google-sheets-using-python-without-authentication/32545>
17. <https://github.com/googleapis/google-api-go-client/issues/444>

**Pola webhook & auth**
18. <https://docs.stripe.com/webhooks>
19. <https://magesheet.com/blog/apps-script-webhooks-doget>

**Pola sync engine**
20. <https://dzone.com/articles/data-sync-design>
21. <https://support.airtable.com/articles/8435404541-two-way-syncing-in-airtable>
22. <https://flexsync.app/connect-google-sheets-to-shopify/>

**WordPress / WooCommerce**
23. <https://developer.woocommerce.com/docs/apis/rest-api/v3/products/>
24. <https://github.com/woocommerce/woocommerce/issues/21167>
25. <https://github.com/woocommerce/woocommerce/issues/26029>
26. <https://rudrastyh.com/woocommerce/speed-up-rest-api.html>
27. <https://developer.wordpress.org/reference/functions/spawn_cron/>
28. <https://spinupwp.com/doc/understanding-wp-cron/>
29. <https://wpwebhooks.org/blog/cron-job-for-wordpress/>

---

## Catatan Supervisi Orchestrator (post-review, 2026-09-21)

> Bagian ini ditulis oleh orchestrator (Pi) setelah audit independen atas laporan scout. Laporan inti di atas tidak diubah.

### Hasil verifikasi independen (6 klaim paling menentukan arsitektur)

Diverifikasi ulang via TinyFish langsung ke sumber — **6/6 klaim akurat**:

| Klaim | Sumber | Hasil verifikasi |
|---|---|---|
| Simple `onEdit` max 30 detik; antrean hanya 2 event; `setValue()` tidak memicu trigger | developers.google.com/apps-script/guides/triggers | ✔ Persis ("The onEdit() trigger only queues up to 2 trigger events") |
| UrlFetch 20rb/100rb per hari; runtime 6 mnt; trigger 20/user/script; simultan 30/user | developers.google.com/apps-script/guides/services/quotas | ✔ Tabel kuota cocok persis |
| Sheets API 300 read/mnt/project; 60/mnt/user; backoff eksponensial; 180 dtk timeout | developers.google.com/workspace/sheets/api/limits | ✔ Persis; bonus: "no limit to the number of requests per day" selama dalam kuota per-menit |
| WC REST batch default max 100 objek | developer.woocommerce.com (products API) | ✔ Persis ("limited to up to 100 objects") |
| `spawn_cron` pakai transient lock `doing_cron` + `WP_CRON_LOCK_TIMEOUT` (60 dtk) | developer.wordpress.org/reference/functions/spawn_cron | ✔ Persis, termasuk komentar "attempts to make spawning as atomic as possible" |
| Drive push notification dibatch ±3 menit (tidak real-time) | github.com/googleapis/google-api-go-client#444 | ✔ Persis ("only sent as frequently as every 3 min") |

### Adjusmen interpretasi untuk spec BCS (penting)

1. **Keputusan #14 (REST `/wc/v3/products/batch`) tidak berlaku mentah untuk BCS Sync.** Jalur REST ke situs sendiri (self-HTTP) tidak masuk akal untuk worker yang berjalan di dalam plugin. Jalur yang benar untuk BCS: **reuse `BCS_Catalog_Writer` + `BCS_Executor`** yang sudah punya `CHUNK_SIZE = 10`, heartbeat per chunk, guard waktu, dan resume (diverifikasi di kode, `class-bcs-executor.php`). Bukti #21167 (save 22 dtk) tetap relevan sebagai **risiko perf CRUD internal** → re-benchmark di katalog cf saat implementasi. Klaim #14 hanya berlaku jika sync di-hosting eksternal (di luar WP).
2. **Klaim bertumpu sumber tunggal non-resmi** (magesheet.com untuk pola doPost/HMAC/cold-start 5–8 dtk; DZone untuk state per-record; FlexSync untuk kolom ID read-only): perlakukan sebagai **pola yang masuk akal, bukan kontrak** — wajib diuji empiris di fase implementasi (terutama cold start & pola queue-and-drain).
3. **Pricing Sheets API**: halaman limits menyatakan kelebihan kuota "planned to incur charges later in 2026" — per September 2026 masih berstatus planned; standard use tetap gratis. Konektivitas BCS Sync (≤1 read/mnt) jauh di bawah kuota.
4. **Angka `save()` 21,9 dtk berasal dari WC 3.4.4 (2018)** — valid sebagai indikasi risiko, bukan angka saat ini; harus diukur ulang.

### Verdict supervisi

Laporan **LOLOS audit**: 29 sumber konsisten, 8 klaim tidak terverifikasi ditandai jujur, klaim kritis 6/6 terverifikasi independen. Satu diskrepansi non-material: ringkasan lisan scout menyebut "±1.100 baris" — file aktual 315 baris (padat, konten lengkap).
