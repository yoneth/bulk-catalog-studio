#!/usr/bin/env bash
# =============================================================================
# sync-00-prereq.sh — pra-cek BCS Sync Fase 8–9.
#
# Tujuan: memastikan plugin, skema sync, WP-CLI, custom MCP server, tepat 11
# tool, dan seluruh fixture URL yang dibutuhkan suite tersedia.
# Abort criteria: dependency inactive, tabel/version/command hilang, server
# bukan BCS custom server, daftar tool tidak persis, atau env fixture kosong.
#
# READ-ONLY terhadap katalog dan koneksi. Aman dijalankan ulang.
# =============================================================================
export BCS_SCRIPT_NAME="sync-00-prereq"
export BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-sync-e2e-state.json}"
export BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-sync-e2e-results-${BCS_SCRIPT_NAME}.jsonl}"
export BCS_INIT_BODY="${BCS_INIT_BODY:-/tmp/bcs-sync-e2e-init.json}"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

bcs_require_tools || bcs_finish
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish
bcs_require_wp || bcs_finish

# --- 1. Plugin aktif --------------------------------------------------------
for plugin in bulk-catalog-studio woocommerce mcp-adapter; do
	if bcs_wp plugin list --status=active --field=name 2>/dev/null | grep -qx "$plugin"; then
		bcs_pass "plugin-active-$plugin" "$plugin is active"
	else
		bcs_stop "plugin-active-$plugin" "$plugin is not active — activate it before sync E2E"
	fi
done

# --- 2. Tabel sync -----------------------------------------------------------
prefix="$(bcs_table_prefix)"
tables="$(bcs_db_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('${prefix}bcs_connections','${prefix}bcs_sync_runs');")"
if [ "${tables:-0}" -eq 2 ] 2>/dev/null; then
	bcs_pass "sync-tables" "${prefix}bcs_connections and ${prefix}bcs_sync_runs exist"
else
	bcs_stop "sync-tables" "expected two sync tables, found ${tables:-?}"
fi

# --- 3. Database version >= 1.2.0 ------------------------------------------
db_version="$(bcs_wp option get bcs_db_version 2>/dev/null | tail -n 1 || true)"
version_ok="$(bcs_wp eval 'echo version_compare( (string) get_option( "bcs_db_version", "0.0.0" ), "1.2.0", ">=" ) ? "yes" : "no";' 2>/dev/null | tail -n 1 || true)"
if [ "$version_ok" = "yes" ]; then
	bcs_pass "db-version" "bcs_db_version=$db_version (>= 1.2.0)"
else
	bcs_stop "db-version" "bcs_db_version=${db_version:-?} is below 1.2.0"
fi

# --- 4. WP-CLI namespace ----------------------------------------------------
if bcs_wp help bcs sync >/dev/null 2>&1; then
	bcs_pass "wp-cli-sync" "wp bcs sync command is registered"
else
	bcs_stop "wp-cli-sync" "wp help bcs sync failed — CLI worker is not registered"
fi

# --- 5. Initialize + serverInfo --------------------------------------------
BCS_HTTP_SESSION="$(bcs_http_session 2>/dev/null || true)"
if [ -n "$BCS_HTTP_SESSION" ] && [ -s "$BCS_INIT_BODY" ]; then
	bcs_pass "mcp-initialize" "received Mcp-Session-Id"
else
	bcs_stop "mcp-initialize" "custom MCP initialize failed"
fi
server_name="$(jq -r '.result.serverInfo.name // empty' "$BCS_INIT_BODY" 2>/dev/null || true)"
if [ "$server_name" = "$BCS_MCP_SERVER_NAME" ]; then
	bcs_pass "server-info" "serverInfo.name=$server_name"
else
	bcs_stop "server-info" "serverInfo.name=${server_name:-?}; expected $BCS_MCP_SERVER_NAME"
fi

# --- 6. tools/list exact 11 names ------------------------------------------
resp_tools="$(bcs_rpc tools/list '{}')" || resp_tools=""
if [ -z "$resp_tools" ] || ! printf '%s' "$resp_tools" | jq -e '.result.tools' >/dev/null 2>&1; then
	bcs_stop "tools-list" "tools/list did not return a tools array"
else
	tool_count="$(printf '%s' "$resp_tools" | jq -r '[.result.tools[]?.name] | length')"
	actual_tools="$(printf '%s' "$resp_tools" | jq -c '[.result.tools[]?.name] | sort')"
	expected_tools="$(printf '%s\n' $BCS_EXPECTED_TOOLS | jq -R -s -c 'split("\n") | map(select(length > 0)) | sort')"
	if [ "$tool_count" = "11" ]; then
		bcs_pass "tools-count" "tools/list returned exactly 11 tools"
	else
		bcs_stop "tools-count" "tools/list returned $tool_count tools (expected 11)"
	fi
	if [ "$actual_tools" = "$expected_tools" ]; then
		bcs_pass "tools-names" "all 11 expected dashed tool names match exactly"
	else
		bcs_stop "tools-names" "tool names differ; actual=$actual_tools expected=$expected_tools"
	fi
fi

# --- 7. E2E fixture environment -------------------------------------------
# The CSV URL is the controlled source for sync-01. The other URLs are the
# controlled read-only/guard fixtures used by sync-02.
required_envs="BCS_SYNC_CONN_CSV_URL BCS_SYNC_CONN_EMPTY_URL BCS_SYNC_CONN_MISSING_URL BCS_SYNC_CONN_PRICE_URL BCS_SYNC_CONN_DELETE_URL BCS_SYNC_CONN_ROLLBACK_URL BCS_SYNC_CONN_CHAOS_URL"
missing=""
for var in $required_envs; do
	if [ -z "${!var:-}" ]; then
		missing="$missing $var"
	fi
done
if [ -z "$missing" ]; then
	bcs_pass "sync-env" "required BCS_SYNC_CONN_* fixture URLs are available"
else
	bcs_stop "sync-env" "missing required fixture env:$missing"
fi

# GID values default to 0 so each fixture may use its first tab explicitly.
for var in BCS_SYNC_CONN_CSV_GID BCS_SYNC_CONN_EMPTY_GID BCS_SYNC_CONN_MISSING_GID BCS_SYNC_CONN_PRICE_GID BCS_SYNC_CONN_DELETE_GID BCS_SYNC_CONN_ROLLBACK_GID BCS_SYNC_CONN_CHAOS_GID BCS_SYNC_CONN_BAD_GID; do
	if [ -z "${!var:-}" ]; then
		export "$var=0"
	fi
done
bcs_pass "sync-env-gids" "fixture GID variables are set (unset values default to 0)"

for var in BCS_SYNC_CONN_SHEETS_ID BCS_SYNC_CONN_SHEETS_RANGE BCS_SYNC_CONN_SHEETS_CREDENTIALS_FILE; do
	if [ -n "${!var:-}" ]; then
		bcs_info "sheets-api-env-$var" "$var is available for optional Sheets API verification"
	else
		bcs_info "sheets-api-env-$var" "$var is not set; CSV E2E does not require it"
	fi
done

bcs_state_set prereq_passed "1"
bcs_finish
