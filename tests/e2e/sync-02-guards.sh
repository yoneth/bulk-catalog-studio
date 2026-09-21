#!/usr/bin/env bash
# =============================================================================
# sync-02-guards.sh — guard negatif S2/S3/S4/S6/S7b.
#
# Tujuan: memakai wp eval untuk menukar referensi fixture pada koneksi aktif,
# lalu membuktikan HTTP/config error, zero rows, missing-row guard, quarantine
# satu harga ekstrem, dan delete threshold 11 baris.
# Abort criteria: guard tidak menghentikan run sebelum katalog berubah, error
# code/state salah, quarantine tidak field-level, atau delete threshold lolos.
#
# READ-HEAVY: setiap fixture dipulihkan ke URL/GID asli segera setelah uji.
# IDEMPOTEN/RESUMABLE: hasil tiap subkasus disimpan sebagai guard_* di state.
# =============================================================================
export BCS_SCRIPT_NAME="sync-02-guards"
export BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-sync-e2e-state.json}"
export BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-sync-e2e-results-${BCS_SCRIPT_NAME}.jsonl}"
export BCS_INIT_BODY="${BCS_INIT_BODY:-/tmp/bcs-sync-e2e-init.json}"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init
bcs_require_wp || bcs_finish

connection_id="$(bcs_state_get '.connection_id // ""')"
original_url="$(bcs_state_get '.original_csv_url // ""')"
original_gid="$(bcs_state_get '.original_csv_gid // "0"')"
[ -n "$connection_id" ] || { bcs_stop "state" "connection_id missing — run sync-01-csv-connection.sh first"; bcs_finish; }
[ -n "$original_url" ] || { bcs_stop "state" "original CSV URL missing — run sync-01-csv-connection.sh first"; bcs_finish; }

prefix="$(bcs_table_prefix)"

sync_update_source() {
	local url="$1" gid="$2" output
	output="$(bcs_wp eval '
$id = (int) $args[0];
$url = (string) $args[1];
$gid = (string) $args[2];
$connection = BCS_Sync_Connection::get( $id );
if ( ! is_array( $connection ) ) { echo "ERR:not-found"; exit; }
$config = is_array( $connection["sheet_config"] ) ? $connection["sheet_config"] : array();
$config["url"] = $url;
$config["gid"] = $gid;
$result = BCS_Sync_Connection::update( $id, array( "sheet_config" => $config, "status" => "active" ) );
echo is_wp_error( $result ) ? "ERR:" . $result->get_error_message() : "OK";
' -- "$connection_id" "$url" "$gid" 2>/dev/null | tail -n 1 || true)"
	[ "$output" = "OK" ] || return 1
}

sync_restore_source() {
	sync_update_source "$original_url" "$original_gid"
}

sync_latest_json() {
	bcs_wp eval '$run = BCS_Sync_Runner::latest_run( (int) $args[0] ); echo wp_json_encode( $run ? $run : array() );' -- "$connection_id" 2>/dev/null | tail -n 1
}

owned_live_count() {
	bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id=p.ID WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key='_bcs_connection_id' AND m.meta_value='${connection_id}';"
}

run_once() {
	# Failed guard runs make WP-CLI return non-zero; the persisted run is the
	# assertion source, so keep the shell alive and inspect it below.
	bcs_wp bcs sync run --connection-id="$connection_id" >/tmp/bcs-sync-e2e-last-cli.txt 2>&1 || true
}

jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

# --- S2: invalid sheet reference / HTTP-config fetch guard ------------------
if [ "$(bcs_state_get '.guard_invalid // ""')" != "pass" ]; then
	before="$(owned_live_count)"
	bad_url="${BCS_SYNC_CONN_BAD_URL:-https://invalid.example.test/not-a-sheet}"
	bad_gid="${BCS_SYNC_CONN_BAD_GID:-0}"
	if ! sync_update_source "$bad_url" "$bad_gid"; then
		bcs_stop "guard-invalid-setup" "could not update connection to invalid sheet reference"
		bcs_finish
	fi
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"; code="$(jget "$latest" '.error_code')"; after="$(owned_live_count)"
	if [ "$state" = "failed_fetch" ] && [ "${before:-0}" = "${after:-0}" ]; then
		bcs_pass "guard-invalid-sheet" "state=failed_fetch error_code=${code:-?}; 0 owned product writes"
		bcs_state_set guard_invalid "pass"
	else
		bcs_fail "guard-invalid-sheet" "state=${state:-?} error_code=${code:-?} owned=${before:-?}->${after:-?}"
	fi
	 sync_restore_source || { bcs_stop "guard-invalid-restore" "failed to restore original source"; bcs_finish; }
else
	bcs_info "guard-invalid-sheet" "resume: already passed"
fi

# --- S3: empty sheet --------------------------------------------------------
if [ "$(bcs_state_get '.guard_empty // ""')" != "pass" ]; then
	before="$(owned_live_count)"
	if ! sync_update_source "$BCS_SYNC_CONN_EMPTY_URL" "${BCS_SYNC_CONN_EMPTY_GID:-0}"; then
		bcs_stop "guard-empty-setup" "could not update connection to empty fixture"
		bcs_finish
	fi
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"; code="$(jget "$latest" '.error_code')"; after="$(owned_live_count)"
	if [ "$state" = "failed_fetch" ] && [ "$code" = "fetch_zero_rows" ] && [ "${before:-0}" = "${after:-0}" ]; then
		bcs_pass "guard-empty-sheet" "fetch_zero_rows; 0 owned product writes/trash"
		bcs_state_set guard_empty "pass"
	else
		bcs_fail "guard-empty-sheet" "state=${state:-?} error_code=${code:-?} owned=${before:-?}->${after:-?}"
	fi
	sync_restore_source || { bcs_stop "guard-empty-restore" "failed to restore original source"; bcs_finish; }
else
	bcs_info "guard-empty-sheet" "resume: already passed"
fi

# --- S4: >20% previously seen SKUs missing ---------------------------------
if [ "$(bcs_state_get '.guard_missing // ""')" != "pass" ]; then
	before="$(owned_live_count)"
	if ! sync_update_source "$BCS_SYNC_CONN_MISSING_URL" "${BCS_SYNC_CONN_MISSING_GID:-0}"; then
		bcs_stop "guard-missing-setup" "could not update connection to missing-row fixture"
		bcs_finish
	fi
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"; code="$(jget "$latest" '.error_code')"; after="$(owned_live_count)"
	if [ "$state" = "failed_guard" ] && [ "$code" = "missing_rows_guard" ] && [ "${before:-0}" = "${after:-0}" ]; then
		bcs_pass "guard-missing-rows" "missing_rows_guard; >20% source loss caused 0 writes"
		bcs_state_set guard_missing "pass"
	else
		bcs_fail "guard-missing-rows" "state=${state:-?} error_code=${code:-?} owned=${before:-?}->${after:-?}"
	fi
	sync_restore_source || { bcs_stop "guard-missing-restore" "failed to restore original source"; bcs_finish; }
else
	bcs_info "guard-missing-rows" "resume: already passed"
fi

# --- S6: one price x10 quarantined, other rows apply -----------------------
if [ "$(bcs_state_get '.guard_price // ""')" != "pass" ]; then
	if ! sync_update_source "$BCS_SYNC_CONN_PRICE_URL" "${BCS_SYNC_CONN_PRICE_GID:-0}"; then
		bcs_stop "guard-price-setup" "could not update connection to price-quarantine fixture"
		bcs_finish
	fi
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"
	diff_summary="$(jget "$latest" '.diff_summary')"
	quarantined="$(printf '%s' "$diff_summary" | jq -r 'fromjson | .summary.quarantined // 0' 2>/dev/null || echo 0)"
	applied="$(printf '%s' "$diff_summary" | jq -r 'fromjson | ((.summary.create // 0) + (.summary.update // 0) + (.summary.update_variation // 0))' 2>/dev/null || echo 0)"
	if [ "$state" = "done" ] && [ "${quarantined:-0}" -ge 1 ] 2>/dev/null && [ "${applied:-0}" -ge 1 ] 2>/dev/null; then
		bcs_pass "guard-price-quarantine" "one extreme price quarantined; $applied other operation(s) applied"
		bcs_state_set guard_price "pass"
	else
		bcs_fail "guard-price-quarantine" "state=${state:-?} quarantined=${quarantined:-?} applied=${applied:-?}"
	fi
	sync_restore_source || { bcs_stop "guard-price-restore" "failed to restore original source"; bcs_finish; }
else
	bcs_info "guard-price-quarantine" "resume: already passed"
fi

# --- S7b: 11 explicit deletes require approval ------------------------------
if [ "$(bcs_state_get '.guard_delete // ""')" != "pass" ]; then
	before="$(owned_live_count)"
	if ! sync_update_source "$BCS_SYNC_CONN_DELETE_URL" "${BCS_SYNC_CONN_DELETE_GID:-0}"; then
		bcs_stop "guard-delete-setup" "could not update connection to delete fixture"
		bcs_finish
	fi
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"
	diff_summary="$(jget "$latest" '.diff_summary')"
	deletes="$(printf '%s' "$diff_summary" | jq -r 'fromjson | .summary.delete // 0' 2>/dev/null || echo 0)"
	after="$(owned_live_count)"
	if [ "$state" = "awaiting_approval" ] && [ "${deletes:-0}" -ge 11 ] 2>/dev/null && [ "${before:-0}" = "${after:-0}" ]; then
		bcs_pass "guard-delete-threshold" "11 explicit delete operations held at awaiting_approval; no product trash"
		bcs_state_set guard_delete "pass"
	else
		bcs_fail "guard-delete-threshold" "state=${state:-?} delete_ops=${deletes:-?} owned=${before:-?}->${after:-?}"
	fi
	sync_restore_source || { bcs_stop "guard-delete-restore" "failed to restore original source"; bcs_finish; }
else
	bcs_info "guard-delete-threshold" "resume: already passed"
fi

sync_restore_source || { bcs_stop "restore-original-source" "final source restore failed"; bcs_finish; }
bcs_state_set guards_complete "1"
bcs_finish
