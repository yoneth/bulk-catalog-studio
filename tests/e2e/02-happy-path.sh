#!/usr/bin/env bash
# =============================================================================
# 02-happy-path.sh — alur happy path AC-1 (fixture 30 produk fashion).
#
# Alur: CUSTOM MCP SERVER bcs-mcp (HTTP-only), tools/call langsung
# ke tool individual. Urutan (spec §4):
#   seed produk existing (SKU row 3) → parse-source → check-duplicates →
#   build-plan → [NEGATIF] execute-batch sebelum approval (harus DITOLAK) →
#   approval (token) → approve-plan → execute-batch dry_run=true →
#   execute-batch sungguhan → job-status → audit-log.
#
# Parameter:
#   BCS_FIXTURE         path CSV (default fixture repo). Wajib ada.
#   BCS_APPROVAL_TOKEN  token dari Mas Rio / layar admin (prioritas pertama).
#   BCS_TOKEN_HELPER    "wpcli" (default) = generate token via
#                       `wp eval BCS_Approval_Token::generate(...)`;
#                       "none" = paksa STOP dan tunggu token manusia.
#   BCS_STATE_FILE      state antar-run (default /tmp/bcs-e2e-state.json);
#                       skrip RESUME dari stage terakhir yang sukses.
#
# Output: JSON Lines PASS/FAIL/STOP per langkah + ringkasan akhir.
# Exit: 0 sukses; 1 ada FAIL; 2 STOP (approval menunggu manusia / abort).
#
# IDEMPOTEN: rerun aman — job/plan diambil dari state file, tidak membuat
# job ganda. execute-batch sungguhan hanya dipanggil sekali (stage 'executed').
# =============================================================================
export BCS_SCRIPT_NAME="02-happy-path"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# Custom server bcs-mcp: HANYA HTTP + kredensial env wajib.
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish

jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

# Hasil ability terakhir harus objek (bukan WP_Error ter-serialisasi).
result_ok() {
	local res="$1"
	bcs_last_response_ok || return 1
	printf '%s' "$res" | jq -e 'type=="object"' >/dev/null 2>&1 || return 1
	if printf '%s' "$res" | jq -e '(has("code") and has("message")) and (has("job_id")|not) and (has("plan_id")|not) and (has("status")|not) and (has("entries")|not)' >/dev/null 2>&1; then
		return 1
	fi
	return 0
}

stage="$(bcs_state_get '.stage // ""')"
if [ "$stage" = "done" ]; then
	bcs_pass "already-done" "job $(bcs_state_get '.job_id') sudah done sebelumnya — tidak ada aksi baru"
	bcs_finish
fi

# ---------------------------------------------------------------------------
# Langkah 0 — seed produk existing untuk deteksi duplikat vs katalog (AC-10)
# ---------------------------------------------------------------------------
seed_sku="KML-GRS-003" # SKU baris 3 fixture
seed_out="$(bcs_wp eval '
$sku = "KML-GRS-003";
$existing = wc_get_product_id_by_sku( $sku );
if ( $existing ) { echo "exists:" . $existing; exit; }
$p = new WC_Product();
$p->set_name( "BCS E2E Existing Product (seed, bukan hasil job)" );
$p->set_sku( $sku );
$p->set_regular_price( "159000" );
$p->set_stock_quantity( 12 );
$p->set_manage_stock( true );
$p->set_status( "draft" );
$pid = $p->save();
echo $pid ? "created:" . $pid : "failed";
' 2>/dev/null | tail -n 1)" || seed_out=""
case "$seed_out" in
	exists:*|created:*) bcs_pass "seed-existing" "produk existing SKU $seed_sku siap ($seed_out)" ;;
	*) bcs_fail "seed-existing" "gagal seed produk existing: '$seed_out'" ;;
esac

# ---------------------------------------------------------------------------
# Langkah 1 — parse-source (hanya bila belum ada job di state)
# ---------------------------------------------------------------------------
job_id="$(bcs_state_get '.job_id // ""')"
if [ -z "$job_id" ]; then
	[ -s "$BCS_FIXTURE" ] || { bcs_stop "parse-source" "fixture tidak ada: $BCS_FIXTURE"; bcs_finish; }
	input="$(jq -n --rawfile csv "$BCS_FIXTURE" '{
		source_type: "inline_csv",
		csv_text: $csv,
		source_name: "sample-fashion-30.csv",
		delimiter: ",",
		header_row: true,
		column_mapping: {
			"Nama":"name", "Deskripsi":"description", "Ringkasan":"short_description",
			"SKU":"sku", "Harga":"regular_price", "Harga Diskon":"sale_price",
			"Stok":"stock_quantity", "Berat(kg)":"weight", "P":"length", "L":"width", "T":"height",
			"Kategori":"categories", "Tag":"tags",
			"Ukuran":"attribute:pa_ukuran", "Warna":"attribute:pa_warna",
			"SKU Variasi":"variation_sku", "Harga Variasi":"variation_regular_price",
			"Stok Variasi":"variation_stock_quantity"
		},
		notes: "E2E happy path 30 baris (docs/test/e2e-runbook.md)"
	}')"
	res="$(bcs_call_tool "bcs-parse-source" "$input")" || res=""
	if result_ok "$res" && [ "$(jget "$res" '.status')" = "parsed" ]; then
		job_id="$(jget "$res" '.job_id')"
		total_rows="$(jget "$res" '.total_rows')"
		bcs_state_set job_id "$job_id"
		bcs_state_set source_hash "$(jget "$res" '.source_hash')"
		bcs_state_set stage "parsed"
		if [ "$total_rows" = "30" ]; then
			bcs_pass "parse-source" "job_id=$job_id status=parsed total_rows=$total_rows"
		else
			bcs_fail "parse-source" "total_rows=$total_rows (harus 30) — STOP dan review warnings"
		fi
	else
		bcs_stop "parse-source" "gagal/ditolak: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 300)"
	fi
else
	bcs_info "parse-source" "resume: pakai job_id=$job_id dari state"
fi
[ -n "$job_id" ] || { bcs_finish; }

# ---------------------------------------------------------------------------
# Langkah 2 — check-duplicates (AC-10)
# ---------------------------------------------------------------------------
plan_id="$(bcs_state_get '.plan_id // ""')"
if [ -z "$plan_id" ]; then
	res="$(bcs_call_tool "bcs-check-duplicates" '{"job_id":'"$job_id"',"strategy":"sku+title","on_duplicate":"flag_only"}')" || res=""
	if result_ok "$res"; then
		intra="$(jget "$res" '.intra_batch_duplicates | length // 0')"
		existing="$(jget "$res" '.existing_matches | length // 0')"
		dup_count="$(jget "$res" '.duplicate_count')"
		bcs_state_set duplicate_count "$dup_count"
		# Harapan: 1 intra-batch (row 15 vs row 5, SKU KML-GRS-005) + 1 existing (row 3 vs seed).
		if [ "${intra:-0}" -ge 1 ] && [ "${existing:-0}" -ge 1 ] 2>/dev/null; then
			bcs_pass "check-duplicates" "duplicate_count=$dup_count intra=$intra existing=$existing"
		else
			bcs_stop "check-duplicates" "deteksi duplikat tidak sesuai (intra=$intra existing=$existing) — STOP, review AC-10"
		fi
	else
		bcs_stop "check-duplicates" "gagal: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 300)"
	fi

	# ---------------------------------------------------------------------------
	# Langkah 3 — build-plan (status=draft, attribute global pa_*)
	# ---------------------------------------------------------------------------
	res="$(bcs_call_tool "bcs-build-plan" '{"job_id":'"$job_id"',"on_duplicate":"skip","defaults":{"status":"draft","manage_stock":true,"attribute_scope":"global_pa"},"image_policy":{"mode":"attachment_id_only","set_alt_text":true}}')" || res=""
	if result_ok "$res" && [ -n "$(jget "$res" '.plan_hash')" ]; then
		plan_id="$(jget "$res" '.plan_id')"
		plan_hash="$(jget "$res" '.plan_hash')"
		summary="$(printf '%s' "$res" | jq -c '.summary')"
		bcs_state_set plan_id "$plan_id"
		bcs_state_set plan_hash "$plan_hash"
		bcs_state_set summary "$summary"
		bcs_state_set diff_preview_url "$(jget "$res" '.diff_preview_url')"
		c_simple="$(jget "$res" '.summary.create_simple // 0')"; c_var="$(jget "$res" '.summary.create_variable // 0')"
		c_variations="$(jget "$res" '.summary.create_variations // 0')"; c_skip="$(jget "$res" '.summary.skip_duplicates // 0')"
		# Fixture sendiri punya 1 duplikat SKU dan seed menambah 1 produk existing;
		# kedua baris ber-SKU duplikat di-skip, sehingga totalnya 27 create + 3 skip.
		if [ "$(( c_simple + c_var ))" -eq 27 ] && [ "$c_variations" -ge 60 ] && [ "$c_skip" -eq 3 ]; then
			bcs_pass "build-plan" "plan_id=$plan_id hash=${plan_hash:0:12}… summary=$summary"
			bcs_state_set stage "planned"
		else
			bcs_fail "build-plan" "summary tidak sesuai harapan (create=27, variasi>=60, skip=3): $summary"
		fi
	else
		bcs_stop "build-plan" "gagal: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 300)"
	fi
else
	bcs_info "build-plan" "resume: pakai plan_id=$plan_id dari state"
fi
[ -n "$plan_id" ] || { bcs_finish; }
plan_hash="$(bcs_state_get '.plan_hash // ""')"
summary="$(bcs_state_get '.summary // "{}"')"

# ---------------------------------------------------------------------------
# Langkah 4 — [NEGATIF, AC-4] execute-batch NYATA sebelum approval harus DITOLAK.
# dry_run bukan aksi tulis dan memang boleh dipakai sebagai simulasi plan.
# ---------------------------------------------------------------------------
res="$(bcs_call_tool "bcs-execute-batch" '{"plan_id":'"$plan_id"'}')" || res=""
if bcs_last_response_rejected 'approv|planned|permission|denied|invalid|error|gagal'; then
	bcs_pass "execute-before-approve-rejected" "execute-batch pada plan belum approved DITOLAK (AC-4) ✔"
else
	bcs_fail "execute-before-approve-rejected" "execute-batch TIDAK ditolak padahal plan masih planned — pelanggaran gerbang approval (AC-4)"
fi

# job masih planned
res="$(bcs_call_tool "bcs-job-status" '{"job_id":'"$job_id"'}')" || res=""
st="$(jget "$res" '.jobs[0].status // .status')"
if [ "$st" = "planned" ]; then
	bcs_pass "job-status-planned" "job $job_id masih planned (tidak ada efek samping)"
else
	bcs_fail "job-status-planned" "status job = '$st' (harus planned)"
fi

# ---------------------------------------------------------------------------
# Langkah 5 — gerbang approval (STOP bila belum ada token manusia)
# ---------------------------------------------------------------------------
if [ "$stage" != "approved" ] && [ "$stage" != "executed" ]; then
	token="${BCS_APPROVAL_TOKEN:-}"
	token_src="env BCS_APPROVAL_TOKEN"
	if [ -z "$token" ] && [ "${BCS_TOKEN_HELPER:-wpcli}" = "wpcli" ]; then
		token="$(bcs_wp eval '
$t = BCS_Approval_Token::generate( (int) $args[0], (string) $args[1] );
echo is_wp_error( $t ) ? "ERR:" . $t->get_error_message() : $t;
' -- "$plan_id" "$plan_hash" 2>/dev/null | tail -n 1)" || token=""
		token_src="wp-cli eval (helper automation)"
	fi
	if [ -z "$token" ] || [ "${token#ERR:}" != "$token" ] || ! printf '%s' "$token" | grep -Eq '^[A-Z0-9-]{6,12}$'; then
		bcs_stop "approval-token" "Token approval belum tersedia (src=$token_src, got='${token:-kosong}'). \
STOP: minta token dari Mas Rio via layar admin ${BCS_SITE_URL}/wp-admin/admin.php?page=bcs-jobs&view=plan&plan=$plan_id \
(Generate token approval), lalu jalankan ulang: BCS_APPROVAL_TOKEN=BCS-XXXXX $0 (state di-resume dari sini). \
Matikan helper dengan BCS_TOKEN_HELPER=none bila token manual diwajibkan."
		bcs_finish
	fi
	bcs_info "approval-token" "token diperoleh via $token_src"

	res="$(bcs_call_tool "bcs-approve-plan" '{"plan_id":'"$plan_id"',"plan_hash":"'"$plan_hash"'","confirmation_token":"'"$token"'"}')" || res=""
	if result_ok "$res" && [ "$(jget "$res" '.status')" = "approved" ]; then
		bcs_pass "approve-plan" "status=approved approved_by=$(jget "$res" '.approved_by')"
		bcs_state_set stage "approved"
	else
		note="$(jget "$res" '.note')"
		bcs_stop "approve-plan" "approval gagal: '${note:-token invalid/expired}' — generate token baru lalu rerun"
		bcs_finish
	fi
else
	bcs_info "approve-plan" "resume: stage=$stage (approval sudah dilewati)"
fi

# ---------------------------------------------------------------------------
# Langkah 6 — execute-batch dry_run=true (tidak boleh menulis produk)
# ---------------------------------------------------------------------------
if [ "$stage" != "executed" ]; then
	res="$(bcs_call_tool "bcs-execute-batch" '{"plan_id":'"$plan_id"',"dry_run":true}')" || res=""
	if result_ok "$res" && [ "$(jget "$res" '.status')" = "done" ] && [ "$(jget "$res" '.counts.failed')" = "0" ]; then
		bcs_pass "execute-dry-run" "dry_run done, failed=0, counts=$(printf '%s' "$res" | jq -c '.counts')"
	else
		bcs_stop "execute-dry-run" "dry_run gagal — JANGAN eksekusi sungguhan: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 300)"
		bcs_finish
	fi
	# DB guard: dry run TIDAK boleh menulis produk bertag plan ini.
	prefix="$(bcs_table_prefix)"
	written="$(bcs_db_scalar "SELECT COUNT(*) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id = p.ID WHERE p.post_type = 'product' AND m.meta_key = '_bcs_plan_id' AND m.meta_value = '${plan_id}';")"
	if [ "${written:-0}" = "0" ]; then
		bcs_pass "execute-dry-run-no-write" "0 produk tertulis setelah dry_run ✔"
	else
		bcs_fail "execute-dry-run-no-write" "dry_run menulis $written produk — STOP, periksa implementasi"
	fi
fi

# ---------------------------------------------------------------------------
# Langkah 7 — execute-batch sungguhan (sekali saja)
# ---------------------------------------------------------------------------
if [ "$stage" != "executed" ]; then
	res="$(bcs_call_tool "bcs-execute-batch" '{"plan_id":'"$plan_id"'}')" || res=""
	if result_ok "$res" && [ "$(jget "$res" '.status')" = "done" ]; then
		counts="$(printf '%s' "$res" | jq -c '.counts')"
		bcs_state_set counts "$counts"
		bcs_state_set stage "executed"
		bcs_pass "execute-batch" "status=done counts=$counts duration_ms=$(jget "$res" '.duration_ms')"
	else
		counts="$(jget "$res" '.counts // "{}"')"
		bcs_state_set counts "$counts"
		bcs_state_set stage "executed"  # job bisa partially_failed — tetap dicatat utk rollback
		bcs_fail "execute-batch" "status=$(jget "$res" '.status') counts=$counts failures=$(printf '%s' "$res" | jq -c '.failures // []' | head -c 200) — jalankan 04-rollback.sh"
	fi
else
	bcs_info "execute-batch" "resume: stage=executed (eksekusi nyata sudah pernah dijalankan)"
fi
[ "$(bcs_state_get '.stage // ""')" = "executed" ] || { bcs_finish; }

# ---------------------------------------------------------------------------
# Langkah 8 — job-status final = done (AC-1)
# ---------------------------------------------------------------------------
res="$(bcs_call_tool "bcs-job-status" '{"job_id":'"$job_id"'}')" || res=""
st="$(jget "$res" '.jobs[0].status // .status')"
if [ "$st" = "done" ]; then
	bcs_pass "job-status-done" "job $job_id = done (AC-1)"
	bcs_state_set stage "done"
else
	bcs_fail "job-status-done" "status job = '$st' (harus done)"
fi

# ---------------------------------------------------------------------------
# Langkah 9 — audit-log level row (AC-5)
# ---------------------------------------------------------------------------
res="$(bcs_call_tool "bcs-audit-log" '{"job_id":'"$job_id"',"level":"row","limit":200}')" || res=""
if result_ok "$res"; then
	total="$(jget "$res" '.total')"
	create_rows="$(printf '%s' "$res" | jq -r '[.entries[]? | select(.action=="create_product" and .product_id != null and .actor_via=="mcp")] | length' 2>/dev/null || echo 0)"
	if [ "${total:-0}" -ge 28 ] 2>/dev/null && [ "${create_rows:-0}" -ge 1 ] 2>/dev/null; then
		bcs_pass "audit-log" "total=$total entri row; create_product+product_id+via=mcp ditemukan ($create_rows) (AC-5)"
	else
		bcs_fail "audit-log" "audit kurang: total=$total, create_product ber-produk_id=$create_rows"
	fi
else
	bcs_fail "audit-log" "audit-log gagal: $(printf '%s' "${res:-$BCS_LAST_RESPONSE}" | head -c 200)"
fi

bcs_finish
