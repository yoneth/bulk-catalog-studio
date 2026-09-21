#!/usr/bin/env bash
# =============================================================================
# sync-05-agent-gate.sh — S10 ownership-vs-agent gate.
#
# Tujuan: melalui tools/call bcs-parse-source → bcs-check-duplicates →
# bcs-build-plan, kirim regular_price untuk SKU yang dimiliki koneksi aktif.
# Build-plan wajib ditolak (isError/error) dan pesan wajib menyebut connection
# id serta field regular_price. Tidak ada execute-batch di skrip ini.
# Abort criteria: SKU tidak terdeteksi duplicate/owned, build-plan diterima,
# atau pesan penolakan tidak cukup spesifik.
#
# IDEMPOTEN/RESUMABLE: job_id disimpan di state; setiap panggilan dapat diulang
# aman. Job/plan uji hanya read-only terhadap katalog.
# =============================================================================
export BCS_SCRIPT_NAME="sync-05-agent-gate"
export BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-sync-e2e-state.json}"
export BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-sync-e2e-results-${BCS_SCRIPT_NAME}.jsonl}"
export BCS_INIT_BODY="${BCS_INIT_BODY:-/tmp/bcs-sync-e2e-init.json}"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish
bcs_require_wp || bcs_finish

connection_id="$(bcs_state_get '.connection_id // ""')"
probe_sku="$(bcs_state_get '.conflict_sku // ""')"
[ -n "$connection_id" ] || { bcs_stop "state" "connection_id missing — run sync-01 first"; bcs_finish; }
if [ -z "$probe_sku" ]; then
	probe_sku="${BCS_SYNC_CONFLICT_SKU:-}"
fi
if [ -z "$probe_sku" ]; then
	probe_sku="$(bcs_wp eval '$c=BCS_Sync_Connection::get((int)$args[0]); $seen=is_array($c)&&is_array($c["policy"])&&isset($c["policy"]["seen_skus"]) ? (array)$c["policy"]["seen_skus"] : array(); echo isset($seen[0]) ? (string)$seen[0] : "";' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
fi
[ -n "$probe_sku" ] || { bcs_stop "state" "no seen SKU available for agent gate probe"; bcs_finish; }

job_id="$(bcs_state_get '.agent_gate_job_id // ""')"
if [ -z "$job_id" ]; then
	csv_text=$'name,sku,regular_price\nAgent ownership probe,'"$probe_sku"$',999.99\n'
	parse_args="$(jq -n --arg csv "$csv_text" '{source_type:"inline_csv",csv_text:$csv,source_name:"sync-05-agent-gate",header_row:true}')"
	parse_result="$(bcs_call_tool bcs-parse-source "$parse_args" || true)"
	if ! bcs_last_response_ok || ! printf '%s' "$parse_result" | jq -e '.job_id' >/dev/null 2>&1; then
		bcs_stop "parse-probe" "bcs-parse-source failed: $(bcs_last_response_raw | head -c 300)"
		bcs_finish
	fi
	job_id="$(printf '%s' "$parse_result" | jq -r '.job_id')"
	bcs_state_set agent_gate_job_id "$job_id"
	bcs_pass "parse-probe" "created/reused read-only probe job #$job_id for owned SKU $probe_sku"
else
	bcs_info "parse-probe" "resume: using probe job #$job_id"
fi

# The duplicate check is still a read-only catalog inspection, but it materializes
# its result on the job so build-plan has the exact existing_product_id.
dup_args="$(jq -n --argjson job "$job_id" '{job_id:$job,strategy:"sku",on_duplicate:"update"}')"
dup_result="$(bcs_call_tool bcs-check-duplicates "$dup_args" || true)"
if ! bcs_last_response_ok; then
	bcs_stop "duplicate-probe" "bcs-check-duplicates failed: $(bcs_last_response_raw | head -c 300)"
	bcs_finish
fi
dup_count="$(printf '%s' "$dup_result" | jq -r '.duplicate_count // 0' 2>/dev/null || echo 0)"
if [ "${dup_count:-0}" -ge 1 ] 2>/dev/null; then
	bcs_pass "owned-sku-detected" "duplicate check found $dup_count existing SKU match(es)"
else
	bcs_stop "owned-sku-detected" "probe SKU $probe_sku was not found in the catalog; sync-01/sync-03 must complete first"
	bcs_finish
fi

plan_args="$(jq -n --argjson job "$job_id" '{job_id:$job,on_duplicate:"update"}')"
plan_result="$(bcs_call_tool bcs-build-plan "$plan_args" || true)"
raw="$(bcs_last_response_raw)"
message="$(printf '%s' "$raw" | jq -r '.error.message // .result.structuredContent.message // .result.content[0].text // empty' 2>/dev/null || true)"
if bcs_last_response_rejected "connection|ownership|regular_price"; then
	if printf '%s' "$raw $message" | grep -Eqi "connection[^0-9]*${connection_id}|${connection_id}[^[:alnum:]]*(connection|ownership)|regular_price"; then
		bcs_pass "agent-ownership-gate" "bcs-build-plan rejected owned regular_price edit and named connection #$connection_id: $(printf '%s' "${message:-$raw}" | head -c 260)"
	else
		bcs_fail "agent-ownership-gate" "build-plan was rejected, but message did not name connection #$connection_id and regular_price: ${message:-$raw}"
	fi
else
	bcs_fail "agent-ownership-gate" "bcs-build-plan unexpectedly accepted a regular_price edit for owned SKU $probe_sku: $(printf '%s' "$plan_result" | head -c 300)"
fi

bcs_state_set agent_gate_complete "1"
bcs_finish
