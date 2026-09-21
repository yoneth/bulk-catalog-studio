#!/usr/bin/env bash
# =============================================================================
# 00-prereq.sh — pra-cek environment sebelum uji E2E Bulk Catalog Studio.
#
# Cek: tooling → WP reachable → plugin & WooCommerce aktif → permalink bukan
# Plain → MCP Adapter + 3 tabel bcs_* → fixture CSV → endpoint HTTP 401 tanpa
# auth (opsional bila jaringan tersedia) → sesi HTTP bila BCS_TRANSPORT=http.
#
# READ-ONLY: skrip ini tidak menulis apa pun ke situs. Aman dijalankan ulang.
# Abort criteria: plugin/Woo inactive, permalink Plain, tabel bcs_* hilang,
# atau MCP server tidak terdaftar → orchestrator HARUS berhenti (exit 2).
# =============================================================================
export BCS_SCRIPT_NAME="00-prereq"
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

bcs_results_reset
bcs_state_init

# --- 1. Tooling lokal -------------------------------------------------------
bcs_require_tools || bcs_finish

# --- 2. WP-CLI reachable ----------------------------------------------------
bcs_require_wp || bcs_finish

# --- 3. Plugin aktif --------------------------------------------------------
if bcs_wp plugin list --status=active --field=name 2>/dev/null | grep -qx 'bulk-catalog-studio'; then
	bcs_pass "plugin-active" "bulk-catalog-studio aktif"
else
	bcs_stop "plugin-active" "plugin bulk-catalog-studio TIDAK aktif — STOP, aktifkan dulu"
fi

if bcs_wp plugin list --status=active --field=name 2>/dev/null | grep -qx 'woocommerce'; then
	bcs_pass "woocommerce-active" "WooCommerce aktif"
else
	bcs_stop "woocommerce-active" "WooCommerce TIDAK aktif — ability write menolak (guard WC_Product)"
fi

# --- 4. Permalink bukan Plain (prasyarat REST/MCP HTTP) ---------------------
permalink="$(bcs_wp option get permalink_structure 2>/dev/null | tail -n 1 || true)"
if [ -n "$permalink" ] && [ "$permalink" != "" ]; then
	bcs_pass "permalink" "permalink_structure=$permalink"
else
	bcs_stop "permalink" "permalink_structure kosong (Plain) — MCP HTTP tidak akan bekerja"
fi

# --- 5. MCP Adapter terdaftar ----------------------------------------------
if bcs_wp mcp-adapter list 2>/dev/null | grep -q "$BCS_MCP_SERVER"; then
	bcs_pass "mcp-server-registered" "$BCS_MCP_SERVER terdaftar"
else
	bcs_stop "mcp-server-registered" "server '$BCS_MCP_SERVER' tidak ada di 'wp mcp-adapter list'"
fi

# --- 6. Ability bcs/parse-source terdaftar di registry ---------------------
if [ "$(bcs_wp eval 'echo wp_has_ability("bcs/parse-source") ? "yes" : "no";' 2>/dev/null | tail -n 1)" = "yes" ]; then
	bcs_pass "abilities-registered" "bcs/parse-source ada di registry"
else
	bcs_stop "abilities-registered" "bcs/parse-source TIDAK terdaftar — cek hook wp_abilities_api_init"
fi

# --- 7. Tabel bcs_* ada (aktivasi sukses) -----------------------------------
prefix="$(bcs_table_prefix)"
tables_found="$(bcs_db_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('${prefix}bcs_jobs','${prefix}bcs_plans','${prefix}bcs_audit');")" || tables_found=""
if [ "${tables_found:-0}" -ge 3 ] 2>/dev/null; then
	bcs_pass "bcs-tables" "3 tabel bcs_* ada (prefix: $prefix)"
else
	bcs_stop "bcs-tables" "tabel bcs_* kurang dari 3 (ditemukan: ${tables_found:-?}) — jalankan aktivasi plugin"
fi

# --- 8. Fixture CSV tersedia -------------------------------------------------
if [ -s "$BCS_FIXTURE" ]; then
	rows="$(awk 'END{print NR-1}' "$BCS_FIXTURE" || true)"
	bcs_pass "fixture" "$BCS_FIXTURE ($rows baris data)"
else
	bcs_stop "fixture" "fixture tidak ditemukan/kosong: $BCS_FIXTURE (set BCS_FIXTURE)"
fi

# --- 9. Endpoint MCP tanpa auth harus 401 (bukan 200) -----------------------
http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$(bcs_http_endpoint)" 2>/dev/null || echo '000')"
if [ "$http_code" = "401" ]; then
	bcs_pass "http-401-unauth" "endpoint tanpa auth → 401 (sesuai harapan)"
elif [ "$http_code" = "000" ]; then
	bcs_info "http-401-unauth" "endpoint tidak terjangkau dari host ini (abaikan bila uji dari luar VPS)"
else
	bcs_fail "http-401-unauth" "endpoint tanpa auth balik HTTP $http_code (harusnya 401)"
fi

# --- 10. Transport http: wajib kredensial env + sesi initialize -------------
if [ "$BCS_TRANSPORT" = "http" ]; then
	if bcs_http_session > /tmp/bcs-e2e-session.txt 2>/dev/null; then
		bcs_pass "http-initialize" "Mcp-Session-Id didapat (initialize OK)"
	else
		bcs_fail "http-initialize" "initialize gagal — cek BCS_WP_USER/BCS_APP_PASSWORD (Application Password)"
	fi
else
	bcs_info "http-initialize" "transport=stdio (set BCS_TRANSPORT=http + env kredensial untuk uji jalur HTTP)"
fi

bcs_finish
