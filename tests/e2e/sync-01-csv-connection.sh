#!/usr/bin/env bash
# =============================================================================
# sync-01-csv-connection.sh — koneksi CSV + gerbang approval + apply.
#
# Tujuan: buat/reuse koneksi csv_export, test-connection, buktikan import awal
# melewati threshold menjadi awaiting_approval, approve melalui MCP token, lalu
# lanjutkan worker sampai done. Verifikasi meta ownership/hash dan audit source.
# Abort criteria: test fetch gagal, run awal menulis sebelum approval, token
# ditolak, state bukan done, atau meta/audit sync tidak terbentuk.
#
# IDEMPOTEN/RESUMABLE: connection_id, run_id, diff_hash, dan stage disimpan di
# /tmp/bcs-sync-e2e-state.json. Rerun tidak membuat koneksi/run baru pada stage
# yang telah selesai. Approval token plaintext tidak disimpan ke state.
# =============================================================================
export BCS_SCRIPT_NAME="sync-01-csv-connection"
export BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-sync-e2e-state.json}"
export BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-sync-e2e-results-${BCS_SCRIPT_NAME}.jsonl}"
export BCS_INIT_BODY="${BCS_INIT_BODY:-/tmp/bcs-sync-e2e-init.json}"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish
bcs_require_wp || bcs_finish

jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

mapping_default='{"Nama":"name","SKU":"sku","Harga":"regular_price","Stok":"stock_quantity"}'
mapping="${BCS_SYNC_CONN_CSV_MAPPING:-$mapping_default}"
if ! printf '%s' "$mapping" | jq -e 'type == "object"' >/dev/null 2>&1; then
	bcs_stop "mapping" "BCS_SYNC_CONN_CSV_MAPPING must be a JSON object"
	bcs_finish
fi

connection_name="${BCS_SYNC_CONNECTION_NAME:-BCS E2E Sync CSV}"
connection_id="$(bcs_state_get '.connection_id // ""')"

# --- 1. Create/reuse deterministic connection -------------------------------
if [ -n "$connection_id" ]; then
	connection_ok="$(bcs_wp eval 'echo is_array( BCS_Sync_Connection::get( (int) $args[0] ) ) ? "yes" : "no";' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
else
	connection_ok="no"
fi
if [ "$connection_ok" != "yes" ]; then
	connection_id="$(bcs_wp eval '
$name = (string) $args[0];
$url = (string) $args[1];
$gid = (string) $args[2];
$mapping = json_decode( (string) $args[3], true );
$found = 0;
foreach ( (array) BCS_Sync_Connection::list_connections() as $connection ) {
	if ( isset( $connection["name"], $connection["fetcher"] ) && $name === $connection["name"] && "csv_export" === $connection["fetcher"] ) {
		$found = (int) $connection["id"];
		break;
	}
}
$thresholds = array(
	"max_create_update_ops" => 5,
	"max_create_update_catalog_pct" => 10,
	"max_delete_ops" => 10,
	"missing_row_flag_pct" => 20,
	"price_delta_pct" => 80,
	"stock_drop_pct" => 90,
);
if ( $found ) {
	$result = BCS_Sync_Connection::update( $found, array(
		"sheet_config" => array( "url" => $url, "gid" => $gid ),
		"column_mapping" => is_array( $mapping ) ? $mapping : array(),
		"thresholds" => $thresholds,
		"status" => "active",
	) );
	echo is_wp_error( $result ) ? "ERR:" . $result->get_error_message() : (string) $found;
	exit;
}
$result = BCS_Sync_Connection::insert( array(
	"name" => $name,
	"fetcher" => "csv_export",
	"sheet_config" => array( "url" => $url, "gid" => $gid ),
	"credentials" => null,
	"column_mapping" => is_array( $mapping ) ? $mapping : array(),
	"thresholds" => $thresholds,
	"status" => "active",
	"interval_minutes" => 1,
) );
echo is_wp_error( $result ) ? "ERR:" . $result->get_error_message() : (string) $result;
' -- "$connection_name" "$BCS_SYNC_CONN_CSV_URL" "${BCS_SYNC_CONN_CSV_GID:-0}" "$mapping" 2>/dev/null | tail -n 1 || true)"
	case "$connection_id" in
		''|ERR:*)
			bcs_stop "connection-create" "could not create/reuse csv_export connection: ${connection_id:-empty}"
			bcs_finish
			;;
		*) bcs_pass "connection-create" "csv_export connection_id=$connection_id" ;;
	esac
else
	bcs_info "connection-create" "resume: using connection_id=$connection_id"
fi
bcs_state_set connection_id "$connection_id"
bcs_state_set connection_name "$connection_name"
bcs_state_set original_csv_url "$BCS_SYNC_CONN_CSV_URL"
bcs_state_set original_csv_gid "${BCS_SYNC_CONN_CSV_GID:-0}"

stage="$(bcs_state_get '.stage // ""')"
prefix="$(bcs_table_prefix)"

# --- 2. Test connection (fetch + parse only) -------------------------------
if [ "$stage" = "" ]; then
	if test_output="$(bcs_wp bcs sync test-connection --connection-id="$connection_id" 2>&1)"; then
		bcs_pass "test-connection" "selected csv_export fetcher passed: $(printf '%s' "$test_output" | tail -n 1)"
	else
		bcs_stop "test-connection" "fetch+parse failed: $(printf '%s' "$test_output" | tail -n 2 | head -c 300)"
		bcs_finish
	fi
fi

# --- 3. Initial run must stop at awaiting_approval --------------------------
run_json() {
	bcs_wp eval '$run = BCS_Sync_Runner::latest_run( (int) $args[0] ); echo wp_json_encode( $run ? $run : array() );' -- "$connection_id" 2>/dev/null | tail -n 1
}

if [ "$stage" = "" ]; then
	before_tagged="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id=p.ID WHERE p.post_type='product' AND m.meta_key='_bcs_connection_id' AND m.meta_value='${connection_id}';")"
	if run_output="$(bcs_wp bcs sync run --connection-id="$connection_id" 2>&1)"; then
		bcs_info "initial-run-cli" "$(printf '%s' "$run_output" | tail -n 1)"
	else
		bcs_stop "initial-run" "wp bcs sync run failed: $(printf '%s' "$run_output" | tail -n 2 | head -c 300)"
		bcs_finish
	fi
	latest="$(run_json)"
	run_id="$(jget "$latest" '.id')"
	run_state="$(jget "$latest" '.state')"
	diff_hash="$(jget "$latest" '.diff_hash')"
	if [ "$run_state" = "awaiting_approval" ]; then
		after_tagged="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta m ON m.post_id=p.ID WHERE p.post_type='product' AND m.meta_key='_bcs_connection_id' AND m.meta_value='${connection_id}';")"
		if [ "${before_tagged:-0}" = "${after_tagged:-0}" ]; then
			bcs_pass "initial-run-awaiting-approval" "run_id=$run_id state=awaiting_approval; no product writes before approval"
		else
			bcs_fail "initial-run-awaiting-approval" "tagged product count changed before approval ($before_tagged -> $after_tagged)"
		fi
		bcs_state_set sync_run_id "$run_id"
		bcs_state_set sync_diff_hash "$diff_hash"
		bcs_state_set stage "awaiting_approval"
		stage="awaiting_approval"
	else
		bcs_stop "initial-run-awaiting-approval" "initial run state=${run_state:-?}; expected awaiting_approval (threshold fixture/import setup failed)"
		bcs_finish
	fi
else
	run_id="$(bcs_state_get '.sync_run_id // ""')"
	diff_hash="$(bcs_state_get '.sync_diff_hash // ""')"
	bcs_info "initial-run" "resume stage=$stage run_id=${run_id:-?}"
fi

# --- 4. Token gate + MCP approval ------------------------------------------
if [ "$stage" = "awaiting_approval" ]; then
	[ -n "$run_id" ] && [ -n "$diff_hash" ] || { bcs_stop "approval-state" "run_id/diff_hash missing from state"; bcs_finish; }
	token="${BCS_SYNC_APPROVAL_TOKEN:-}"
	token_source="BCS_SYNC_APPROVAL_TOKEN"
	if [ -z "$token" ] && [ "${BCS_SYNC_TOKEN_HELPER:-wpcli}" = "wpcli" ]; then
		token="$(bcs_wp eval '$token = BCS_Sync_Runner::generate_run_token( (int) $args[0] ); echo is_wp_error( $token ) ? "ERR:" . $token->get_error_message() : $token;' -- "$run_id" 2>/dev/null | tail -n 1 || true)"
		token_source="wp eval helper (or admin Sync Runs equivalent)"
	fi
	if [ -z "$token" ] || [[ "$token" == ERR:* ]] || ! printf '%s' "$token" | grep -Eq '^[A-Z0-9-]{6,12}$'; then
		bcs_stop "approval-token" "approval token unavailable (source=$token_source). Generate it on Sync Runs, then rerun with BCS_SYNC_APPROVAL_TOKEN=BCS-XXXXX"
		bcs_finish
	fi
	bcs_info "approval-token" "token obtained via $token_source; plaintext is not persisted"
	approve_input="$(jq -n --argjson run_id "$run_id" --arg diff_hash "$diff_hash" --arg token "$token" '{run_id:$run_id,diff_hash:$diff_hash,confirmation_token:$token}')"
	approve_result="$(bcs_call_tool "bcs-sync-approve-run" "$approve_input" || true)"
	if bcs_last_response_ok && [ "$(jget "$approve_result" '.status')" = "approved" ]; then
		bcs_pass "mcp-approve-run" "run_id=$run_id transitioned to approved via bcs-sync-approve-run"
		bcs_state_set stage "approved"
		stage="approved"
	else
		bcs_stop "mcp-approve-run" "approval failed or token was rejected: $(printf '%s' "${approve_result:-$BCS_LAST_RESPONSE}" | head -c 300)"
		bcs_finish
	fi
fi

# --- 5. Resume approved run through applying -> done ------------------------
if [ "$stage" = "approved" ]; then
	if apply_output="$(bcs_wp bcs sync run --connection-id="$connection_id" 2>&1)"; then
		bcs_info "apply-cli" "$(printf '%s' "$apply_output" | tail -n 1)"
	else
		bcs_stop "apply-cli" "approved sync run could not be resumed: $(printf '%s' "$apply_output" | tail -n 2 | head -c 300)"
		bcs_finish
	fi
	latest="$(run_json)"
	run_state="$(jget "$latest" '.state')"
	if [ "$run_state" = "done" ]; then
		bcs_pass "apply-done" "run_id=$run_id progressed approved -> applying -> done"
		bcs_state_set stage "done"
		stage="done"
	else
		bcs_stop "apply-done" "latest run state=${run_state:-?}; expected done"
		bcs_finish
	fi
fi

# --- 6. Final DB ownership/hash/audit checks --------------------------------
latest="$(run_json)"
run_state="$(jget "$latest" '.state')"
run_id="$(jget "$latest" '.id')"
if [ "$run_state" = "done" ]; then
	bcs_pass "run-state-done" "connection $connection_id latest run #$run_id is done"
else
	bcs_fail "run-state-done" "latest run state=${run_state:-?}"
fi
owned_products="$(bcs_db_scalar "SELECT COUNT(DISTINCT p.ID) FROM ${prefix}posts p INNER JOIN ${prefix}postmeta owner ON owner.post_id=p.ID AND owner.meta_key='_bcs_connection_id' AND owner.meta_value='${connection_id}' INNER JOIN ${prefix}postmeta hash ON hash.post_id=p.ID AND hash.meta_key='_bcs_sync_hash' AND hash.meta_value <> '' WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft');")"
if [ "${owned_products:-0}" -gt 0 ] 2>/dev/null; then
	bcs_pass "sync-meta" "$owned_products live product(s) have _bcs_connection_id and non-empty _bcs_sync_hash"
else
	bcs_fail "sync-meta" "no live product has both sync ownership and hash metadata"
fi
audit_count="$(bcs_db_scalar "SELECT COUNT(*) FROM ${prefix}bcs_audit WHERE job_id='${run_id}' AND source='sync';")"
if [ "${audit_count:-0}" -gt 0 ] 2>/dev/null; then
	bcs_pass "sync-audit" "$audit_count audit row/call entries use source=sync for run #$run_id"
else
	bcs_fail "sync-audit" "no source=sync audit entry found for run #$run_id"
fi

bcs_state_set stage "done"
bcs_state_set last_sync_run_id "$run_id"
bcs_finish
