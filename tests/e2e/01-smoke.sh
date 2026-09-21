#!/usr/bin/env bash
# =============================================================================
# 01-smoke.sh — smoke test CUSTOM MCP SERVER (bcs-mcp, HTTP-only).
#
# 1) initialize → tangkap Mcp-Session-Id + body response:
#    serverInfo.name HARUS "Bulk Catalog Studio MCP".
# 2) tools/list (dengan header session) → HARUS tepat 8 tool individual:
#    bcs-parse-source, bcs-check-duplicates, bcs-build-plan, bcs-approve-plan,
#    bcs-execute-batch, bcs-job-status, bcs-rollback-batch, bcs-audit-log.
# 3) NEGATIF: meta-tool mcp-adapter-discover-abilities / mcp-adapter-execute-ability
#    TIDAK boleh ada di custom server.
#
# READ-ONLY: tidak ada tool write yang dipanggil.
# ABORT: serverInfo.name salah, jumlah tool ≠ 8, nama tool tidak lengkap, atau
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
	bcs_pass "initialize" "Mcp-Session-Id didapat (custom server $BCS_MCP_ENDPOINT)"
else
	bcs_stop "initialize" "initialize ke custom server gagal — cek kredensial/endpoint (00-prereq)"
	bcs_finish
fi

server_name="$(jq -r '.result.serverInfo.name // empty' "$BCS_INIT_BODY" 2>/dev/null | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' || true)"
if [ "$server_name" = "$BCS_MCP_SERVER_NAME" ]; then
	bcs_pass "server-info" "serverInfo.name = '$server_name'"
else
	bcs_stop "server-info" "serverInfo.name = '${server_name:-?}' (harus '$BCS_MCP_SERVER_NAME') — endpoint salah atau bukan custom server BCS"
fi

# --- 2. tools/list → tepat 8 tool individual --------------------------------
resp_tools="$(bcs_rpc tools/list '{}')" || true
if [ -z "${resp_tools:-}" ] || ! printf '%s' "$resp_tools" | jq -e '.result' >/dev/null 2>&1; then
	bcs_stop "tools-list" "tools/list gagal — periksa session header & endpoint"
	bcs_finish
fi

tool_total="$(printf '%s' "$resp_tools" | jq -r '[.result.tools[]?.name] | length' 2>/dev/null || echo 0)"
tool_names="$(printf '%s' "$resp_tools" | jq -r '.result.tools[]?.name' 2>/dev/null | sort | tr '\n' ' ' || true)"
bcs_info "tools-list" "total tool = $tool_total: $tool_names"

if [ "$tool_total" -eq 8 ] 2>/dev/null; then
	bcs_pass "tools-count" "tools/list mengembalikan tepat 8 tool individual"
else
	bcs_stop "tools-count" "total tool = $tool_total (harus 8) — STOP, jangan lanjut execute"
fi

# Kelengkapan nama — semua 8 tool individual wajib ada.
missing=""
for t in $BCS_EXPECTED_TOOLS; do
	printf '%s' "$tool_names" | grep -qw "$t" || missing="$missing $t"
done
if [ -z "$missing" ]; then
	bcs_pass "tools-names" "semua 8 nama tool individual lengkap"
else
	bcs_stop "tools-names" "tool hilang:$missing"
fi

# --- 3. NEGATIF: meta-tool adapter tidak boleh ada di custom server ---------
meta_found=""
for meta in mcp-adapter-discover-abilities mcp-adapter-get-ability-info mcp-adapter-execute-ability; do
	printf '%s' "$tool_names" | grep -qw "$meta" && meta_found="$meta_found $meta"
done
if [ -z "$meta_found" ]; then
	bcs_pass "no-meta-tools" "tidak ada meta-tool adapter (invocation = tools/call langsung ke tool individual)"
else
	bcs_stop "no-meta-tools" "meta-tool ditemukan di custom server:$meta_found — endpoint kemungkinan server default adapter, bukan bcs-mcp"
fi

# --- 4. NEGATIF: nama tool bcs/* (slash) tidak boleh ada --------------------
slash_tools="$(printf '%s' "$tool_names" | { grep -o 'bcs/[a-z0-9-]*' || true; } | sort -u | tr '\n' ' ' || true)"
if [ -z "$slash_tools" ]; then
	bcs_pass "no-slash-names" "tidak ada nama tool ber-slash (custom server memakai nama dash)"
else
	bcs_stop "no-slash-names" "nama tool ber-slash ditemukan: $slash_tools — custom server memakai bcs-<slug>"
fi

bcs_finish
