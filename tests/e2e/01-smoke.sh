#!/usr/bin/env bash
# =============================================================================
# 01-smoke.sh — smoke test CUSTOM MCP SERVER (bcs-mcp, HTTP-only).
#
# 1) initialize → tangkap Mcp-Session-Id + body response:
#    serverInfo.name HARUS "Bulk Catalog Studio MCP".
# 2) tools/list (dengan header session) → HARUS tepat 11 tool individual:
#    8 tool batch lama + bcs-sync-list-connections, bcs-sync-run,
#    bcs-sync-approve-run.
# 3) NEGATIF: meta-tool mcp-adapter-discover-abilities / mcp-adapter-execute-ability
#    TIDAK boleh ada di custom server.
#
# READ-ONLY: tidak ada tool write yang dipanggil.
# ABORT: serverInfo.name salah, jumlah tool ≠ 11, nama tool tidak lengkap, atau
# meta-tool adapter ditemukan → STOP (exit 2), JANGAN lanjut happy path.
# =============================================================================
export BCS_SCRIPT_NAME="01-smoke"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# --- 0. Transport & kredensial ----------------------------------------------
bcs_require_http || bcs_finish
bcs_require_http_creds || bcs_finish

# --- 1. initialize + serverInfo.name ----------------------------------------
BCS_HTTP_SESSION="$(bcs_http_session 2>/dev/null)" || true
if [ -n "$BCS_HTTP_SESSION" ] && [ -s "$BCS_INIT_BODY" ]; then
	bcs_pass "initialize" "received Mcp-Session-Id (custom server $BCS_MCP_ENDPOINT)"
else
	bcs_stop "initialize" "custom-server initialize failed — check credentials/endpoint (00-prereq)"
	bcs_finish
fi

server_name="$(jq -r '.result.serverInfo.name // empty' "$BCS_INIT_BODY" 2>/dev/null | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' || true)"
if [ "$server_name" = "$BCS_MCP_SERVER_NAME" ]; then
	bcs_pass "server-info" "serverInfo.name = '$server_name'"
else
	bcs_stop "server-info" "serverInfo.name = '${server_name:-?}' (expected '$BCS_MCP_SERVER_NAME') — wrong endpoint or not the BCS custom server"
fi

# --- 2. tools/list → tepat 11 tool individual -------------------------------
# Keep this smoke test self-contained even when an older lib.sh is sourced.
BCS_EXPECTED_TOOLS="bcs-parse-source bcs-check-duplicates bcs-build-plan bcs-approve-plan bcs-execute-batch bcs-job-status bcs-rollback-batch bcs-audit-log bcs-sync-list-connections bcs-sync-run bcs-sync-approve-run"
resp_tools="$(bcs_rpc tools/list '{}')" || true
if [ -z "${resp_tools:-}" ] || ! printf '%s' "$resp_tools" | jq -e '.result' >/dev/null 2>&1; then
	bcs_stop "tools-list" "tools/list failed — check the session header and endpoint"
	bcs_finish
fi

tool_total="$(printf '%s' "$resp_tools" | jq -r '[.result.tools[]?.name] | length' 2>/dev/null || echo 0)"
tool_names="$(printf '%s' "$resp_tools" | jq -r '.result.tools[]?.name' 2>/dev/null | sort | tr '\n' ' ' || true)"
bcs_info "tools-list" "tool total = $tool_total: $tool_names"

if [ "$tool_total" -eq 11 ] 2>/dev/null; then
	bcs_pass "tools-count" "tools/list returns exactly 11 individual tools"
else
	bcs_stop "tools-count" "tool total = $tool_total (expected 11) — STOP; do not continue to execution"
fi

# Kelengkapan nama — semua 11 tool individual wajib ada.
missing=""
for t in $BCS_EXPECTED_TOOLS; do
	printf '%s' "$tool_names" | grep -qw "$t" || missing="$missing $t"
done
if [ -z "$missing" ]; then
	bcs_pass "tools-names" "all 11 individual tool names are present"
else
	bcs_stop "tools-names" "missing tools:$missing"
fi

# --- 3. NEGATIF: meta-tool adapter tidak boleh ada di custom server ---------
meta_found=""
for meta in mcp-adapter-discover-abilities mcp-adapter-get-ability-info mcp-adapter-execute-ability; do
	printf '%s' "$tool_names" | grep -qw "$meta" && meta_found="$meta_found $meta"
done
if [ -z "$meta_found" ]; then
	bcs_pass "no-meta-tools" "no adapter meta-tools (invocation = tools/call directly to individual tools)"
else
	bcs_stop "no-meta-tools" "adapter meta-tool found on custom server:$meta_found — endpoint may be the default adapter server, not bcs-mcp"
fi

# --- 4. NEGATIF: nama tool bcs/* (slash) tidak boleh ada --------------------
slash_tools="$(printf '%s' "$tool_names" | { grep -o 'bcs/[a-z0-9-]*' || true; } | sort -u | tr '\n' ' ' || true)"
if [ -z "$slash_tools" ]; then
	bcs_pass "no-slash-names" "no slash-separated tool names (custom server uses dashed names)"
else
	bcs_stop "no-slash-names" "slash-separated tool names found: $slash_tools — custom server uses bcs-<slug>"
fi

bcs_finish
