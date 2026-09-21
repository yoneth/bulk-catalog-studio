#!/usr/bin/env bash
# =============================================================================
# 00-prereq.sh — pra-cek environment sebelum uji E2E Bulk Catalog Studio
#                terhadap CUSTOM MCP SERVER (bcs-mcp, HTTP-only).
#
# Cek: tooling → WP reachable → plugin mcp-adapter + woocommerce +
# bulk-catalog-studio aktif → permalink bukan Plain → 5 tabel bcs_* →
# fixture CSV → endpoint custom (BCS_MCP_ENDPOINT) balas 401 tanpa auth →
# transport HTTP + kredensial env → initialize menghasilkan Mcp-Session-Id.
#
# READ-ONLY: skrip ini tidak menulis apa pun ke situs. Aman dijalankan ulang.
# Abort criteria: salah satu plugin inactive, tabel bcs_* hilang, transport
# bukan http, kredensial env kosong, atau initialize gagal → STOP (exit 2).
# =============================================================================
export BCS_SCRIPT_NAME="00-prereq"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# --- 1. Tooling lokal -------------------------------------------------------
bcs_require_tools || bcs_finish

# --- 2. Transport HTTP-only (custom server) ---------------------------------
bcs_require_http || bcs_finish

# --- 3. Kredensial env (Application Password) -------------------------------
bcs_require_http_creds || bcs_finish

# --- 4. WP-CLI reachable (untuk pra-cek plugin & tabel di VPS) --------------
bcs_require_wp || bcs_finish

# --- 5. Plugin aktif: bulk-catalog-studio, woocommerce, mcp-adapter ---------
for plugin in bulk-catalog-studio woocommerce mcp-adapter; do
	if bcs_wp plugin list --status=active --field=name 2>/dev/null | grep -qx "$plugin"; then
		bcs_pass "plugin-active-$plugin" "$plugin is active"
	else
		bcs_stop "plugin-active-$plugin" "$plugin is NOT active — STOP and activate it first"
	fi
done

# --- 6. Permalink bukan Plain (prasyarat REST/MCP HTTP) ---------------------
permalink="$(bcs_wp option get permalink_structure 2>/dev/null | tail -n 1 || true)"
if [ -n "$permalink" ]; then
	bcs_pass "permalink" "permalink_structure=$permalink"
else
	bcs_stop "permalink" "permalink_structure is empty (Plain) — the HTTP MCP endpoint will not work"
fi

# --- 7. Ability bcs/parse-source terdaftar di registry WP -------------------
if [ "$(bcs_wp eval 'echo wp_has_ability("bcs/parse-source") ? "yes" : "no";' 2>/dev/null | tail -n 1 || true)" = "yes" ]; then
	bcs_pass "abilities-registered" "bcs/parse-source is registered (source for 11 custom-server tools)"
else
	bcs_stop "abilities-registered" "bcs/parse-source is NOT registered — the custom server will not expose the BCS tools"
fi

# --- 8. Tabel bcs_* ada (aktivasi sukses) -----------------------------------
prefix="$(bcs_table_prefix)"
tables_found="$(bcs_db_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('${prefix}bcs_jobs','${prefix}bcs_plans','${prefix}bcs_audit','${prefix}bcs_connections','${prefix}bcs_sync_runs');")" || tables_found=""
if [ "${tables_found:-0}" -ge 5 ] 2>/dev/null; then
	bcs_pass "bcs-tables" "5 bcs_* tables exist (prefix: $prefix)"
else
	bcs_stop "bcs-tables" "fewer than 5 bcs_* tables found (${tables_found:-?}) — activate or upgrade the plugin"
fi

# --- 9. Fixture CSV tersedia -------------------------------------------------
if [ -s "$BCS_FIXTURE" ]; then
	rows="$(awk 'END{print NR-1}' "$BCS_FIXTURE" || true)"
	bcs_pass "fixture" "$BCS_FIXTURE ($rows data rows)"
else
	bcs_stop "fixture" "fixture not found or empty: $BCS_FIXTURE (set BCS_FIXTURE)"
fi

# --- 10. Endpoint custom tanpa auth harus 401 (bukan 200) -------------------
http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$BCS_MCP_ENDPOINT" 2>/dev/null || echo '000')"
if [ "$http_code" = "401" ]; then
	bcs_pass "endpoint-401-unauth" "$BCS_MCP_ENDPOINT without auth → 401 (as expected)"
elif [ "$http_code" = "000" ]; then
	bcs_fail "endpoint-401-unauth" "endpoint $BCS_MCP_ENDPOINT is unreachable from this host"
else
	bcs_fail "endpoint-401-unauth" "unauthenticated endpoint returned HTTP $http_code (expected 401)"
fi

# --- 11. Initialize custom server → Mcp-Session-Id --------------------------
if bcs_http_session > /tmp/bcs-e2e-session.txt 2>/dev/null; then
	bcs_pass "http-initialize" "received Mcp-Session-Id from $BCS_MCP_ENDPOINT (initialize OK)"
else
	bcs_stop "http-initialize" "custom-server initialize failed — check BCS_WP_USER/BCS_APP_PASSWORD (Application Password) and endpoint"
fi

bcs_finish
