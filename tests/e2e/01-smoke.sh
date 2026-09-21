#!/usr/bin/env bash
# =============================================================================
# 01-smoke.sh — smoke test MCP Adapter + 8 ability bcs/*.
#
# 1) tools/list via JSON-RPC (STDIO default, HTTP opsional) → response valid.
# 2) tools/call mcp-adapter-discover-abilities → HARUS menampilkan tepat 8
#    ability dengan prefix 'bcs/'.
# 3) Bila env BCS_WP_USER + BCS_APP_PASSWORD di-set → uji juga jalur HTTP
#    (initialize → Mcp-Session-Id → discover) dan bandingkan hasilnya.
#
# READ-ONLY: tidak ada ability write yang dipanggil.
# ABORT: jumlah ability bcs/* != 8 → STOP (exit 2), JANGAN lanjut happy path.
# =============================================================================
export BCS_SCRIPT_NAME="01-smoke"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

EXPECTED_ABILITIES="bcs/audit-log bcs/approve-plan bcs/build-plan bcs/check-duplicates bcs/execute-batch bcs/job-status bcs/parse-source bcs/rollback-batch"

# --- 1. tools/list ----------------------------------------------------------
resp_tools="$(bcs_rpc tools/list '{}')" || true
if [ -n "${resp_tools:-}" ] && printf '%s' "$resp_tools" | jq -e '.result' >/dev/null 2>&1; then
	meta_tools="$(printf '%s' "$resp_tools" | jq -r '[.result.tools[]?.name] | length' 2>/dev/null || echo '?')"
	bcs_pass "tools-list" "tools/list OK ($meta_tools tool; 3 meta-tool adapter diharapkan)"
else
	bcs_stop "tools-list" "tools/list gagal — cek 'wp mcp-adapter serve --user=$BCS_CLI_USER' manual"
fi

# --- 2. discover-abilities (transport aktif) --------------------------------
resp_disc="$(bcs_rpc tools/call '{"name":"mcp-adapter-discover-abilities","arguments":{}}')" || true
raw_disc="${resp_disc:-}"
found_count="$(bcs_count_bcs_abilities "$raw_disc")"
found_list="$(bcs_list_bcs_abilities "$raw_disc")"

if [ "$found_count" -eq 8 ] 2>/dev/null; then
	bcs_pass "discover-abilities" "8 ability bcs/* tampil: $found_list"
else
	bcs_stop "discover-abilities" "ditemukan $found_count ability bcs/* (harus 8): '$found_list' — STOP, jangan lanjut execute"
fi

# Kelengkapan nama (semua 8 nama wajib ada).
missing=""
for a in $EXPECTED_ABILITIES; do
	printf '%s' "$found_list" | grep -qw "${a#bcs/}" || missing="$missing $a"
done
if [ -z "$missing" ]; then
	bcs_pass "discover-names" "semua 8 nama ability lengkap"
else
	bcs_stop "discover-names" "ability hilang:$missing"
fi

# --- 3. Jalur HTTP (hanya bila kredensial env tersedia) ---------------------
if [ -n "${BCS_WP_USER:-}" ] && [ -n "${BCS_APP_PASSWORD:-}" ]; then
	resp_http="$(BCS_TRANSPORT=http bcs_rpc tools/call '{"name":"mcp-adapter-discover-abilities","arguments":{}}')" || true
	http_count="$(bcs_count_bcs_abilities "${resp_http:-}")"
	if [ "$http_count" -eq 8 ] 2>/dev/null; then
		bcs_pass "discover-http" "jalur HTTP juga menampilkan 8 ability bcs/*"
	else
		bcs_fail "discover-http" "jalur HTTP menampilkan $http_count ability (harus 8) — cek Application Password/permalink"
	fi
else
	bcs_info "discover-http" "BCS_WP_USER/BCS_APP_PASSWORD tidak di-set — lewati uji jalur HTTP"
fi

bcs_finish
