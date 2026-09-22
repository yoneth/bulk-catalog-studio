#!/usr/bin/env bash
# =============================================================================
# sync-04-chaos.sh — S11 crash/resume/idempotency.
#
# Tujuan: jalankan worker di background, SIGKILL setelah 3 detik, tandai
# heartbeat stale hanya sebagai akselerasi test, lalu rerun. Verifikasi run done,
# SKU owned tidak duplikat, dan named lock tidak macet.
# Abort criteria: worker tidak bisa diretry, lock tetap busy setelah heartbeat
# stale, run gagal, atau SQL GROUP BY menemukan SKU ganda.
#
# IDEMPOTEN/RESUMABLE: chaos_stage dan konfigurasi koneksi sebelum test disimpan
# di state; rerun setelah kill melanjutkan dari fase stale/resume. Sumber dan
# threshold koneksi dipulihkan pada akhir test.
# =============================================================================
export BCS_SCRIPT_NAME="sync-04-chaos"
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
[ -n "$connection_id" ] || { bcs_stop "state" "connection_id missing — run sync-01 first"; bcs_finish; }
[ -n "${BCS_SYNC_CONN_CHAOS_URL:-}" ] || { bcs_stop "env" "BCS_SYNC_CONN_CHAOS_URL is required for chaos fixture"; bcs_finish; }

prefix="$(bcs_table_prefix)"
chaos_stage="$(bcs_state_get '.chaos_stage // ""')"

sync_update_source() {
	local url="$1" gid="$2" output
	output="$(bcs_wp eval '
$id=(int)$args[0]; $url=(string)$args[1]; $gid=(string)$args[2];
$c=BCS_Sync_Connection::get($id); if(!is_array($c)){echo "ERR:not-found";exit;}
$config=is_array($c["sheet_config"])?$c["sheet_config"]:array(); $config["url"]=$url; $config["gid"]=$gid;
$r=BCS_Sync_Connection::update($id,array("sheet_config"=>$config,"status"=>"active"));
echo is_wp_error($r)?"ERR:".$r->get_error_message():"OK";
' -- "$connection_id" "$url" "$gid" 2>/dev/null | tail -n 1 || true)"
	[ "$output" = "OK" ]
}

restore_connection() {
	local saved="$1"
	bcs_wp eval '
$id=(int)$args[0]; $saved=json_decode((string)$args[1],true);
if(!is_array($saved)){echo "ERR:state";exit;}
$r=BCS_Sync_Connection::update($id,array("sheet_config"=>$saved["sheet_config"],"policy"=>$saved["policy"],"thresholds"=>$saved["thresholds"],"status"=>"active"));
echo is_wp_error($r)?"ERR:".$r->get_error_message():"OK";
' -- "$connection_id" "$saved" 2>/dev/null | tail -n 1
}

# --- 1. Save config and launch the worker once ------------------------------
if [ -z "$chaos_stage" ]; then
	before_config="$(bcs_wp eval '$c=BCS_Sync_Connection::get((int)$args[0]); echo wp_json_encode(array("sheet_config"=>$c["sheet_config"],"policy"=>$c["policy"],"thresholds"=>$c["thresholds"]));' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	if ! printf '%s' "$before_config" | jq -e 'has("sheet_config") and has("policy") and has("thresholds")' >/dev/null 2>&1; then
		bcs_stop "chaos-config" "could not snapshot connection config"
		bcs_finish
	fi
	bcs_state_set chaos_original_config "$before_config"
	# The controlled chaos fixture must be below the parser hard limit. Raise
	# only this connection's run threshold so it reaches applying automatically.
	threshold_result="$(bcs_wp eval '
$id=(int)$args[0]; $c=BCS_Sync_Connection::get($id); $t=$c["thresholds"];
$t["max_create_update_ops"]=1000; $t["max_create_update_catalog_pct"]=100; $t["max_delete_ops"]=1000;
$r=BCS_Sync_Connection::update($id,array("thresholds"=>$t,"status"=>"active")); echo is_wp_error($r)?"ERR:".$r->get_error_message():"OK";
' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	if [ "$threshold_result" != "OK" ] || ! sync_update_source "$BCS_SYNC_CONN_CHAOS_URL" "${BCS_SYNC_CONN_CHAOS_GID:-0}"; then
		bcs_stop "chaos-config" "could not configure chaos fixture/thresholds"
		bcs_finish
	fi
	"$BCS_WP_BIN" --path="$BCS_WP_ROOT" bcs sync run --connection-id="$connection_id" > /tmp/bcs-sync-e2e-chaos-worker.log 2>&1 &
	worker_pid=$!
	bcs_state_set chaos_worker_pid "$worker_pid"
	bcs_state_set chaos_stage "started"
	bcs_pass "chaos-start" "worker PID $worker_pid started against controlled chaos fixture"
	sleep 3
	if kill -0 "$worker_pid" 2>/dev/null; then
		kill -9 "$worker_pid" 2>/dev/null || true
		bcs_pass "chaos-sigkill" "worker PID $worker_pid was SIGKILLed after 3 seconds"
	else
		bcs_info "chaos-sigkill" "worker finished before 3 seconds; resume path is still verified"
	fi
	wait "$worker_pid" 2>/dev/null || true
	bcs_state_set chaos_stage "killed"
	chaos_stage="killed"
else
	bcs_info "chaos-start" "resume: chaos_stage=$chaos_stage"
fi

# --- 2. Accelerate stale heartbeat and rerun --------------------------------
if [ "$chaos_stage" = "started" ] || [ "$chaos_stage" = "killed" ]; then
	stale_result="$(bcs_wp eval '
$id=(int)$args[0]; global $wpdb; $table=$wpdb->prefix."bcs_sync_runs"; $stale=gmdate("Y-m-d H:i:s",time()-700);
$r=$wpdb->query($wpdb->prepare("UPDATE {$table} SET heartbeat_at=%s WHERE connection_id=%d AND state=%s",$stale,$id,"applying")); echo false===$r?"ERR:db":"OK";
' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	if [ "$stale_result" != "OK" ]; then
		bcs_stop "chaos-stale-heartbeat" "could not mark applying heartbeat stale for deterministic resume test"
		bcs_finish
	fi
	# GET_LOCK must be released automatically by the killed connection; the
	# second worker is the assertion that no deadlock remains.
	rerun_output="$(bcs_wp bcs sync run --connection-id="$connection_id" 2>&1 || true)"
	bcs_info "chaos-rerun-cli" "$(printf '%s' "$rerun_output" | tail -n 1)"
	latest="$(bcs_wp eval '$r=BCS_Sync_Runner::latest_run((int)$args[0]); echo wp_json_encode($r ? $r : array());' -- "$connection_id" 2>/dev/null | tail -n 1 || true)"
	state="$(printf '%s' "$latest" | jq -r '.state // empty' 2>/dev/null || true)"
	if [ "$state" = "done" ]; then
		bcs_pass "chaos-resume" "rerun completed done without lock deadlock"
		bcs_state_set chaos_stage "done"
		chaos_stage="done"
	else
		bcs_fail "chaos-resume" "latest state=${state:-?}; worker output=$(printf '%s' "$rerun_output" | head -c 260)"
	fi
fi

# --- 3. No duplicate owned SKU and restore connection config -----------------
if [ "$chaos_stage" = "done" ]; then
	# Hanya post HIDUP yang boleh dihitung. Produk yang sudah di-trash tetap
	# menyandang _sku dan _bcs_connection_id, jadi tanpa filter status setiap
	# SKU dari impor sebelumnya akan berpasangan dengan SKU yang baru dibuat dan
	# tampak sebagai duplikat — padahal katalog hidupnya bersih.
	duplicate_skus="$(bcs_db_scalar "SELECT COUNT(*) FROM (SELECT sku.meta_value FROM ${prefix}postmeta sku INNER JOIN ${prefix}posts p ON p.ID=sku.post_id AND p.post_status NOT IN ('trash','auto-draft') INNER JOIN ${prefix}postmeta owner ON owner.post_id=sku.post_id AND owner.meta_key='_bcs_connection_id' AND owner.meta_value='${connection_id}' WHERE sku.meta_key='_sku' AND sku.meta_value <> '' GROUP BY sku.meta_value HAVING COUNT(*) > 1) AS duplicate_skus;")"
	if [ "${duplicate_skus:-0}" = "0" ]; then
		bcs_pass "chaos-no-duplicate-sku" "SQL GROUP BY/HAVING found no duplicate owned SKU"
	else
		bcs_fail "chaos-no-duplicate-sku" "found $duplicate_skus duplicate owned SKU group(s)"
	fi
	saved_config="$(bcs_state_get '.chaos_original_config // ""')"
	restored="$(restore_connection "$saved_config" || true)"
	if [ "$restored" = "OK" ]; then
		bcs_pass "chaos-cleanup" "original source/policy/threshold configuration restored"
	else
		bcs_fail "chaos-cleanup" "could not restore original connection configuration: $restored"
	fi
fi

bcs_state_set chaos_complete "1"
bcs_finish
