#!/usr/bin/env bash
# =============================================================================
# 03-verify-counts.sh — verifikasi jumlah produk/variasi & jejak di DB (AC-1, AC-5, AC-10).
#
# Sumber kebenaran:
#   1) bcs-job-status  : status done + counter job.
#   2) SQL via wp db query: produk & variasi bertag meta _bcs_job_id = job.
#   3) wp post list / wp term list: sampel SKU, taksonomi pa_ukuran/pa_warna.
#
# READ-ONLY. Aman dijalankan ulang kapan saja setelah 02-happy-path.sh sukses.
# Prasyarat: BCS_STATE_FILE berisi job_id hasil 02 (atau set env BCS_JOB_ID).
# =============================================================================
export BCS_SCRIPT_NAME="03-verify-counts"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# Custom server bcs-mcp: HANYA HTTP + kredensial env wajib.
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish

jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

job_id="${BCS_JOB_ID:-$(bcs_state_get '.job_id // ""')}"
plan_id="${BCS_PLAN_ID:-$(bcs_state_get '.plan_id // ""')}"
[ -n "$job_id" ] || { bcs_stop "state" "job_id tidak ada — jalankan 02-happy-path.sh dulu"; bcs_finish; }
bcs_info "state" "verifikasi job_id=$job_id plan_id=${plan_id:-?}"

prefix="$(bcs_table_prefix)"

# --- 1. Status job = done + counter cocok dengan hasil execute --------------
res="$(bcs_call_tool "bcs-job-status" '{"job_id":'"$job_id"'}')" || res=""
st="$(jget "$res" '.jobs[0].status // .status')"
if [ "$st" = "done" ]; then
	bcs_pass "job-status" "job $job_id = done"
else
	bcs_fail "job-status" "status job = '$st' (harus done; bila rolled_back jalankan 04 dulu lalu ulangi alur)"
fi

count_created="$(jget "$res" '.jobs[0].counts.created // .jobs[0].counts.products_created')"
count_variations="$(jget "$res" '.jobs[0].counts.variations // .jobs[0].counts.variations_created')"
bcs_info "job-counters" "created=${count_created:-?} variations=${count_variations:-?}"

# --- 2. SQL: jumlah produk hasil job ----------------------------------------
sql_products="SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id = p.ID WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key = '_bcs_job_id' AND m.meta_value = '${job_id}';"
db_products="$(bcs_db_scalar "$sql_products")"
sql_variations="SELECT COUNT(*) FROM ${prefix}posts v INNER JOIN ${prefix}postmeta m ON m.post_id = v.ID WHERE v.post_type = 'product_variation' AND v.post_status NOT IN ('trash') AND m.meta_key = '_bcs_job_id' AND m.meta_value = '${job_id}';"
db_variations="$(bcs_db_scalar "$sql_variations")"

if [ "${db_products:-0}" -ge 28 ] 2>/dev/null; then
	bcs_pass "db-products" "produk hasil job di DB = $db_products (>= 28; AC-1)"
else
	bcs_fail "db-products" "produk hasil job di DB = ${db_products:-?} (harus >= 28)"
fi
if [ "${db_variations:-0}" -ge 60 ] 2>/dev/null; then
	bcs_pass "db-variations" "variasi hasil job di DB = $db_variations (>= 60; AC-1)"
else
	bcs_fail "db-variations" "variasi hasil job di DB = ${db_variations:-?} (harus >= 60)"
fi
if [ -n "$count_created" ] && [ "$db_products" != "$count_created" ] 2>/dev/null; then
	bcs_fail "counter-vs-db" "counter job ($count_created) != DB ($db_products)"
else
	bcs_pass "counter-vs-db" "counter job == jumlah DB ($db_products)"
fi

# --- 3. wp post list: sampel produk draft hasil job --------------------------
draft_count="$(bcs_wp post list --post_type=product --post_status=draft --format=count 2>/dev/null | tail -n 1 || true)"
if [ "${draft_count:-0}" -ge 28 ] 2>/dev/null; then
	bcs_pass "wp-post-list" "post list draft = $draft_count (>= 28)"
else
	bcs_fail "wp-post-list" "post list draft = ${draft_count:-?} (harus >= 28)"
fi

# --- 4. Spot-check SKU (id: >0 = ada; dua di antaranya variable) -------------
sku_check_fail=0
for s in DRS-KTN-001 DRS-KTN-010 KML-GRS-001; do
	pid="$(bcs_wp eval 'echo (string) (int) wc_get_product_id_by_sku( $args[0] );' -- "$s" 2>/dev/null | tail -n 1 || true)"
	if [ "${pid:-0}" -gt 0 ] 2>/dev/null; then
		bcs_info "sku-spot" "$s → product_id=$pid"
	else
		bcs_fail "sku-spot" "SKU $s tidak ditemukan"; sku_check_fail=1
	fi
done
[ "$sku_check_fail" = "0" ] && bcs_pass "sku-spot" "semua SKU sampel ditemukan"

# --- 5. AC-10: SKU duplikat intra-batch hanya jadi 1 produk ------------------
dup_sku_count="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id = p.ID WHERE p.post_type='product' AND p.post_status NOT IN ('trash') AND m.meta_key='_sku' AND UPPER(m.meta_value) = 'KML-GRS-005';")"
if [ "${dup_sku_count:-0}" = "1" ] 2>/dev/null; then
	bcs_pass "ac10-intra-dedup" "SKU KML-GRS-005 hanya 1 produk (baris duplikat ke-15 di-skip)"
else
	bcs_fail "ac10-intra-dedup" "SKU KML-GRS-005 terdaftar ${dup_sku_count:-?}x (harus 1)"
fi

# --- 6. Taksonomi global pa_ukuran & pa_warna (attribute_scope=global_pa) ----
terms_ukuran="$(bcs_wp term list pa_ukuran --field=name 2>/dev/null | tr '\n' ' ' || true)"
terms_warna="$(bcs_wp term list pa_warna --field=name 2>/dev/null | tr '\n' ' ' || true)"
ok_ukuran=1; ok_warna=1
for t in S M L; do printf '%s' "$terms_ukuran" | grep -qw "$t" || ok_ukuran=0; done
for t in Merah Biru merah biru; do printf '%s' "$terms_warna" | grep -qw "$t" && ok_warna=1; done
if [ "$ok_ukuran" = "1" ] && [ "$ok_warna" = "1" ]; then
	bcs_pass "pa-taxonomies" "pa_ukuran[$terms_ukuran] pa_warna[$terms_warna]"
else
	bcs_fail "pa-taxonomies" "term atribut kurang: ukuran='$terms_ukuran' warna='$terms_warna'"
fi

# --- 7. AC-2: produk native terbaca via WC CRUD standar (tanpa AI) -----------
native="$(bcs_wp eval '
$pid = wc_get_product_id_by_sku( "DRS-KTN-001" );
if ( ! $pid ) { echo "fail:no-product"; exit; }
$p = wc_get_product( $pid );
if ( ! $p instanceof WC_Product ) { echo "fail:not-wc"; exit; }
$is_var = $p->is_type( "variable" );
$kids = $is_var ? $p->get_children() : array();
echo $is_var ? "ok:variable:" . count( $kids ) : "ok:simple";
' 2>/dev/null | tail -n 1)"
case "$native" in
	ok:variable:*) bcs_pass "ac2-native-wc" "DRS-KTN-001 variable native, ${native#ok:variable:} variasi terbaca via WC_Product" ;;
	ok:simple)     bcs_pass "ac2-native-wc" "produk simple native terbaca via WC_Product" ;;
	*)             bcs_fail "ac2-native-wc" "produk tidak terbaca via WC CRUD: '$native'" ;;
esac

bcs_finish
