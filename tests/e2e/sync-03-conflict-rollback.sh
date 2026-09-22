#!/usr/bin/env bash
# =============================================================================
# sync-03-conflict-rollback.sh — S9 + S12.
#
# Tujuan: edit harga produk secara manual dengan post_modified lebih baru,
# jalankan sync untuk flag conflict tanpa menimpa nilai WP, lalu jalankan satu
# update sync yang valid, rollback run terakhir, dan buktikan rollback run lama
# ditolak secara eksplisit.
# Abort criteria: nilai manual tertimpa, conflict flag tidak ada, rollback
# memulihkan nilai yang salah, atau rollback non-terakhir diterima.
#
# IDEMPOTEN/RESUMABLE: menyimpan product/run IDs dan tahap di state; sumber
# selalu dipulihkan ke fixture CSV asli pada akhir setiap jalur.
# =============================================================================
export BCS_SCRIPT_NAME="sync-03-conflict-rollback"
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
conflict_sku="${BCS_SYNC_CONFLICT_SKU:-KML-GRS-001}"
# Nilai edit manual harus BERBEDA dari sumber namun tetap DI DALAM ambang
# price_delta_pct (80%), supaya operasinya diklasifikasikan sebagai conflict dan
# bukan ditangkap lebih dulu oleh guard harga ekstrem. Fixture main memberi SKU
# ini harga 159000; nilai lama 120 adalah penurunan 99,9% sehingga selalu
# dikarantina dan langkah konflik tidak pernah teruji.
manual_price="${BCS_SYNC_CONFLICT_PRICE:-170000}"
[ -n "$connection_id" ] || { bcs_stop "state" "connection_id missing — run sync-01 first"; bcs_finish; }
[ -n "$original_url" ] || { bcs_stop "state" "original source missing — run sync-01 first"; bcs_finish; }
[ -n "${BCS_SYNC_CONN_ROLLBACK_URL:-}" ] || { bcs_stop "env" "BCS_SYNC_CONN_ROLLBACK_URL is required for S12"; bcs_finish; }

prefix="$(bcs_table_prefix)"
jget() { printf '%s' "$1" | jq -r "$2 // empty" 2>/dev/null || true; }

sync_update_source() {
	local url="$1" gid="$2" output
	output="$(bcs_wp eval '
$id = (int) $args[0]; $url = (string) $args[1]; $gid = (string) $args[2];
$c = BCS_Sync_Connection::get( $id );
if ( ! is_array( $c ) ) { echo "ERR:not-found"; exit; }
$config = is_array( $c["sheet_config"] ) ? $c["sheet_config"] : array();
$config["url"] = $url; $config["gid"] = $gid;
$result = BCS_Sync_Connection::update( $id, array( "sheet_config" => $config, "status" => "active" ) );
echo is_wp_error( $result ) ? "ERR:" . $result->get_error_message() : "OK";
' -- "$connection_id" "$url" "$gid" 2>/dev/null | tail -n 1 || true)"
	[ "$output" = "OK" ]
}

sync_restore_source() { sync_update_source "$original_url" "$original_gid"; }

# The rollback fixture is deliberately a small slice of the catalog, so it drops
# far more than missing_row_flag_pct of the SKUs seen so far and would be stopped
# by the missing-row guard (class-bcs-sync-diff.php:289-299) before it could
# produce the update this step needs. Lift that one threshold for the duration of
# the run, the way sync-04 lifts the operation-count thresholds for its own
# fixture, and put the original value back immediately afterwards. The saved
# value lives in state so a resumed run still restores it.
missing_guard_relax() {
	local saved
	saved="$(bcs_wp eval '
$c = BCS_Sync_Connection::get( (int) $args[0] );
if ( ! is_array( $c ) ) { echo "ERR:not-found"; exit; }
echo wp_json_encode( $c["thresholds"] );
' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	printf '%s' "$saved" | jq -e 'has("missing_row_flag_pct")' >/dev/null 2>&1 || return 1
	bcs_state_set rollback_saved_thresholds "$saved"
	local output
	output="$(bcs_wp eval '
$id = (int) $args[0];
$c = BCS_Sync_Connection::get( $id );
$t = $c["thresholds"];
$t["missing_row_flag_pct"] = 100;
$r = BCS_Sync_Connection::update( $id, array( "thresholds" => $t, "status" => "active" ) );
echo is_wp_error( $r ) ? "ERR:" . $r->get_error_message() : "OK";
' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	[ "$output" = "OK" ]
}

missing_guard_restore() {
	local saved output
	saved="$(bcs_state_get '.rollback_saved_thresholds // ""')"
	[ -n "$saved" ] || return 0
	output="$(bcs_wp eval '
$id = (int) $args[0];
$t = json_decode( (string) $args[1], true );
if ( ! is_array( $t ) ) { echo "ERR:state"; exit; }
$r = BCS_Sync_Connection::update( $id, array( "thresholds" => $t, "status" => "active" ) );
echo is_wp_error( $r ) ? "ERR:" . $r->get_error_message() : "OK";
' -- "$connection_id" "$saved" 2>/dev/null | tail -n 1 || true)"
	[ "$output" = "OK" ] || return 1
	bcs_state_set rollback_saved_thresholds ""
}

sync_latest_json() { bcs_wp eval '$r=BCS_Sync_Runner::latest_run((int)$args[0]); echo wp_json_encode($r ? $r : array());' -- "$connection_id" 2>/dev/null | tail -n 1; }
run_once() { bcs_wp bcs sync run --connection-id="$connection_id" >/tmp/bcs-sync-e2e-last-cli.txt 2>&1 || true; }

# --- 1. Find the product and create the controlled manual edit -------------
conflict_stage="$(bcs_state_get '.conflict_stage // ""')"
product_id="$(bcs_state_get '.conflict_product_id // ""')"
if [ "$conflict_stage" = "" ]; then
	product_id="$(bcs_wp eval 'echo (string)(int)wc_get_product_id_by_sku((string)$args[0]);' -- "$conflict_sku" 2>/dev/null | tail -n 1 || true)"
	if [ "${product_id:-0}" -lt 1 ] 2>/dev/null; then
		bcs_stop "conflict-product" "SKU $conflict_sku not found; set BCS_SYNC_CONFLICT_SKU to a SKU in the controlled CSV"
		bcs_finish
	fi
	manual_result="$(bcs_wp eval '
$id=(int)$args[0]; $price=(string)$args[1]; $p=wc_get_product($id);
if(!$p instanceof WC_Product){echo "ERR:not-product";exit;}
$p->set_regular_price($price); $saved=$p->save();
$future=gmdate("Y-m-d H:i:s",time()+120);
global $wpdb;
// WordPress selalu menulis post_modified DAN post_modified_gmt pada setiap
// penyuntingan. Deteksi konflik membandingkan post_modified_gmt dengan snapshot
// yang ditinggalkan sync, jadi simulasi edit manual yang hanya menyentuh
// post_modified tidak akan pernah terdeteksi — dan itu cacat kesetiaan simulasi,
// bukan kelonggaran deteksi.
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_modified=%s, post_modified_gmt=%s WHERE ID=%d",$future,$future,$id));
echo $saved ? "OK" : "ERR:save";
' -- "$product_id" "$manual_price" 2>/dev/null | tail -n 1 || true)"
	if [ "$manual_result" = "OK" ]; then
		bcs_pass "manual-edit" "SKU $conflict_sku product #$product_id manually changed to $manual_price with future post_modified"
		bcs_state_set conflict_product_id "$product_id"
		bcs_state_set conflict_sku "$conflict_sku"
		bcs_state_set manual_price "$manual_price"
	else
		bcs_stop "manual-edit" "could not edit product #$product_id: $manual_result"
		bcs_finish
	fi
else
	bcs_info "manual-edit" "resume: product #$product_id already prepared"
fi

# --- 2. Original source must produce a flag-only conflict -------------------
if [ "$conflict_stage" = "" ]; then
	sync_update_source "$original_url" "$original_gid" || { bcs_stop "conflict-source" "could not restore original source"; bcs_finish; }
	run_once
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"
	diff_summary="$(jget "$latest" '.diff_summary')"
	conflicts="$(printf '%s' "$diff_summary" | jq -r '(if type=="string" then fromjson else . end) | [.operations[]? | select(.op=="conflict")] | length' 2>/dev/null || echo 0)"
	flag="$(bcs_wp eval 'echo (string)get_post_meta((int)$args[0],"_bcs_conflict_flag",true);' -- "$product_id" 2>/dev/null | tail -n 1 || true)"
	wp_price="$(bcs_wp eval '$p=wc_get_product((int)$args[0]); echo $p ? (string)$p->get_regular_price("edit") : "";' -- "$product_id" 2>/dev/null | tail -n 1 || true)"
	if [ "$state" = "done" ] && [ "${conflicts:-0}" -ge 1 ] 2>/dev/null && [ -n "$flag" ] && [ "$wp_price" = "$manual_price" ]; then
		bcs_pass "conflict-flag-only" "run flagged conflict; WP price stayed $wp_price and _bcs_conflict_flag is set"
		conflict_run_id="$(jget "$latest" '.id')"
		bcs_state_set conflict_run_id "$conflict_run_id"
		bcs_state_set conflict_stage "conflict_done"
		conflict_stage="conflict_done"
	else
		bcs_fail "conflict-flag-only" "state=${state:-?} conflicts=${conflicts:-?} flag=${flag:-empty} wp_price=${wp_price:-?} expected=$manual_price"
	fi
else
	bcs_info "conflict-flag-only" "resume: conflict run #$(bcs_state_get '.conflict_run_id // ""') already passed"
fi

# Acknowledge the manual value before the rollback fixture so the next source
# diff is a normal update rather than another conflict operation.
if [ "$conflict_stage" = "conflict_done" ]; then
	resolve="$(bcs_wp eval '$r=BCS_Sync_Runner::resolve_conflict((int)$args[0],"keep_wp","wp_admin"); echo is_wp_error($r) ? "ERR:".$r->get_error_message() : "OK";' -- "$product_id" 2>/dev/null | tail -n 1 || true)"
	if [ "$resolve" = "OK" ]; then
		bcs_pass "conflict-keep-wp" "manual value acknowledged before rollback fixture"
	else
		bcs_stop "conflict-keep-wp" "could not acknowledge manual conflict value: $resolve"
		bcs_finish
	fi
	bcs_state_set conflict_stage "acknowledged"
	conflict_stage="acknowledged"
fi

# --- 3. Create the latest update run used for rollback ----------------------
rollback_run_id="$(bcs_state_get '.rollback_run_id // ""')"
if [ "$conflict_stage" = "acknowledged" ]; then
	before_rollback_price="$(bcs_wp eval '$p=wc_get_product((int)$args[0]); echo $p ? (string)$p->get_regular_price("edit") : "";' -- "$product_id" 2>/dev/null | tail -n 1 || true)"
	bcs_state_set before_rollback_price "$before_rollback_price"
	sync_update_source "$BCS_SYNC_CONN_ROLLBACK_URL" "${BCS_SYNC_CONN_ROLLBACK_GID:-0}" || { bcs_stop "rollback-source" "could not set rollback fixture"; bcs_finish; }
	if missing_guard_relax; then
		bcs_info "rollback-guard-relax" "missing_row_flag_pct raised to 100 for the rollback fixture run only"
	else
		bcs_stop "rollback-guard-relax" "could not lift the missing-row guard for the rollback fixture"
		bcs_finish
	fi
	run_once
	missing_guard_restore || { bcs_stop "rollback-guard-restore" "could not restore the original thresholds"; bcs_finish; }
	latest="$(sync_latest_json)"
	state="$(jget "$latest" '.state')"
	diff_summary="$(jget "$latest" '.diff_summary')"
	updates="$(printf '%s' "$diff_summary" | jq -r '(if type=="string" then fromjson else . end) | ((.summary.update // 0)+(.summary.update_variation // 0))' 2>/dev/null || echo 0)"
	rollback_run_id="$(jget "$latest" '.id')"
	if [ "$state" = "done" ] && [ "${updates:-0}" -ge 1 ] 2>/dev/null; then
		bcs_pass "rollback-source-run" "latest update run #$rollback_run_id done with $updates update operation(s)"
		bcs_state_set rollback_run_id "$rollback_run_id"
		bcs_state_set conflict_stage "rollback_ready"
		conflict_stage="rollback_ready"
	else
		bcs_stop "rollback-source-run" "state=${state:-?} update_ops=${updates:-?}; controlled rollback fixture must yield a normal update"
		bcs_finish
	fi
fi

# --- 4. Roll back latest run and verify before snapshot ---------------------
if [ "$conflict_stage" = "rollback_ready" ]; then
	rollback_result="$(bcs_wp eval '$r=BCS_Sync_Runner::rollback_run((int)$args[0]); echo is_wp_error($r) ? "ERR:".$r->get_error_message() : wp_json_encode($r);' -- "$rollback_run_id" 2>/dev/null | tail -n 1 || true)"
	if [[ "$rollback_result" != ERR:* ]] && [ "$(printf '%s' "$rollback_result" | jq -r '.status // empty' 2>/dev/null)" = "rolled_back" ]; then
		restored_price="$(bcs_wp eval '$p=wc_get_product((int)$args[0]); echo $p ? (string)$p->get_regular_price("edit") : "";' -- "$product_id" 2>/dev/null | tail -n 1 || true)"
		before_rollback_price="$(bcs_state_get '.before_rollback_price // ""')"
		if [ "$restored_price" = "$before_rollback_price" ]; then
			bcs_pass "rollback-latest" "run #$rollback_run_id rolled_back and restored price $restored_price"
			bcs_state_set conflict_stage "rolled_back"
			conflict_stage="rolled_back"
		else
			bcs_fail "rollback-latest" "restored price=$restored_price expected before=$before_rollback_price"
		fi
	else
		bcs_fail "rollback-latest" "rollback returned: $(printf '%s' "$rollback_result" | head -c 300)"
	fi
fi

# --- 5. A non-latest completed run must be rejected --------------------------
if [ "$conflict_stage" = "rolled_back" ]; then
	old_run_id="$(bcs_state_get '.conflict_run_id // ""')"
	old_result="$(bcs_wp eval '$r=BCS_Sync_Runner::rollback_run((int)$args[0]); echo is_wp_error($r) ? "ERR:".$r->get_error_message() : wp_json_encode($r);' -- "$old_run_id" 2>/dev/null | tail -n 1 || true)"
	if [[ "$old_result" == ERR:* ]] && printf '%s' "$old_result" | grep -Eqi 'latest|run'; then
		bcs_pass "rollback-nonlatest-rejected" "old run #$old_run_id rejected explicitly: $(printf '%s' "$old_result" | head -c 220)"
		bcs_state_set conflict_stage "done"
	else
		bcs_fail "rollback-nonlatest-rejected" "old rollback unexpectedly accepted or returned unclear error: $old_result"
	fi
fi

missing_guard_restore || { bcs_stop "restore-thresholds" "failed to restore the original thresholds"; bcs_finish; }
sync_restore_source || { bcs_stop "restore-original-source" "failed to restore original source after conflict/rollback tests"; bcs_finish; }
bcs_state_set conflict_rollback_complete "1"
bcs_finish
