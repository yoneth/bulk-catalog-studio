#!/usr/bin/env bash
# =============================================================================
# 04-rollback.sh — rollback job (AC-6) + verifikasi trash.
#
# 1) bcs-rollback-batch { job_id, confirm: true, scope: "all" }
#    → produk baru → TRASH (soft), variasi → trash, audit per-baris rolled_back.
# 2) Verifikasi SQL: jumlah produk/variasi bertag _bcs_job_id dengan
#    post_status='trash' == jumlah yang dibuat.
# 3) Verifikasi audit berisi entri rolled_back.
#
# IDEMPOTEN: bila job sudah rolled_back, skrip hanya memverifikasi ulang trash.
# Prasyarat: job selesai (done / partially_failed) — jalankan setelah 02 sukses.
# =============================================================================
export BCS_SCRIPT_NAME="04-rollback"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# Custom server bcs-mcp: HANYA HTTP + kredensial env wajib.
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish

jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

job_id="${BCS_JOB_ID:-$(bcs_state_get '.job_id // ""')}"
[ -n "$job_id" ] || { bcs_stop "state" "job_id tidak ada — jalankan 02-happy-path.sh dulu"; bcs_finish; }

prefix="$(bcs_table_prefix)"

# --- 0. Pastikan job layak rollback ------------------------------------------
res="$(bcs_call_tool "bcs-job-status" '{"job_id":'"$job_id"'}')" || res=""
st="$(jget "$res" '.jobs[0].status // .status')"
already_rolled=0
case "$st" in
	done|partially_failed) bcs_info "pre-check" "job $job_id status=$st — layak di-rollback" ;;
	rolled_back)           bcs_info "pre-check" "job $job_id SUDAH rolled_back — hanya verifikasi ulang"; already_rolled=1 ;;
	*)                     bcs_stop "pre-check" "status job = '$st' — rollback hanya untuk done/partially_failed"; bcs_finish ;;
esac

# --- 1. Panggil rollback-batch (sekali) --------------------------------------
if [ "$already_rolled" = "0" ]; then
	res="$(bcs_call_tool "bcs-rollback-batch" '{"job_id":'"$job_id"',"confirm":true,"scope":"all"}')" || res=""
	if bcs_last_response_ok && [ "$(jget "$res" '.status')" = "rolled_back" ]; then
		bcs_pass "rollback-batch" "status=rolled_back counts=$(printf '%s' "$res" | jq -c '.counts')"
		bcs_state_set rollback_counts "$(printf '%s' "$res" | jq -c '.counts')"
	else
		bcs_fail "rollback-batch" "gagal: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 300)"
		bcs_finish
	fi
fi

# --- 2. Verifikasi trash via SQL ---------------------------------------------
trashed_products="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id = p.ID WHERE p.post_type='product' AND p.post_status='trash' AND m.meta_key='_bcs_job_id' AND m.meta_value='${job_id}';")"
trashed_variations="$(bcs_db_scalar "SELECT COUNT(*) FROM ${prefix}posts v INNER JOIN ${prefix}postmeta m ON m.post_id = v.ID WHERE v.post_type='product_variation' AND v.post_status='trash' AND m.meta_key='_bcs_job_id' AND m.meta_value='${job_id}';")"
live_products="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id = p.ID WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key='_bcs_job_id' AND m.meta_value='${job_id}';")"

if [ "${trashed_products:-0}" -ge 27 ] 2>/dev/null; then
	bcs_pass "trash-products" "produk hasil job di trash = $trashed_products (>= 27; AC-6)"
else
	bcs_fail "trash-products" "produk di trash = ${trashed_products:-?} (harus >= 27)"
fi
if [ "${trashed_variations:-0}" -ge 60 ] 2>/dev/null; then
	bcs_pass "trash-variations" "variasi di trash = $trashed_variations (>= 60; AC-6)"
else
	bcs_fail "trash-variations" "variasi di trash = ${trashed_variations:-?} (harus >= 60)"
fi
if [ "${live_products:-0}" = "0" ] 2>/dev/null; then
	bcs_pass "trash-complete" "0 produk hasil job tersisa di luar trash"
else
	bcs_fail "trash-complete" "masih ada $live_products produk hasil job di luar trash"
fi

# --- 3. wp post list: trash terlihat dari admin (recoverable, bukan delete) --
trash_list="$( { bcs_wp post list --post_type=product --post_status=trash --field=ID 2>/dev/null || true; } | wc -l | tr -d '[:space:]')"
if [ "${trash_list:-0}" -ge 27 ] 2>/dev/null; then
	bcs_pass "wp-post-trash" "post list trash = $trash_list (produk masih recoverable dari admin)"
else
	bcs_fail "wp-post-trash" "post list trash = ${trash_list:-?} (harus >= 27)"
fi

# --- 4. Audit berisi rolled_back per baris ------------------------------------
res="$(bcs_call_tool "bcs-audit-log" '{"job_id":'"$job_id"',"level":"row","limit":200}')" || res=""
rb_rows="$(printf '%s' "$res" | jq -r '[.entries[]? | select(.status=="rolled_back")] | length' 2>/dev/null || echo 0)"
if [ "${rb_rows:-0}" -ge 27 ] 2>/dev/null; then
	bcs_pass "audit-rolled-back" "$rb_rows entri audit berstatus rolled_back (AC-5/AC-6)"
else
	bcs_fail "audit-rolled-back" "entri rolled_back = ${rb_rows:-?} (harus >= 27)"
fi

# --- 5. Produk non-job tidak terpengaruh (produk seed masih ada) --------------
seed_pid="$(bcs_wp eval 'echo (string) (int) wc_get_product_id_by_sku( "KML-GRS-003" );' 2>/dev/null | tail -n 1 || true)"
if [ "${seed_pid:-0}" -gt 0 ] 2>/dev/null; then
	bcs_pass "non-job-untouched" "produk existing (seed KML-GRS-003, id=$seed_pid) tidak ikut ter-trash"
else
	bcs_fail "non-job-untouched" "produk existing seed hilang — rollback menyentuh produk di luar job!"
fi

bcs_state_set stage "rolled_back"
bcs_finish
