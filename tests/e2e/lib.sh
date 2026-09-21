#!/usr/bin/env bash
# =============================================================================
# lib.sh — helper bersama untuk skrip E2E Bulk Catalog Studio (BCS).
#
# Pola pemanggilan MCP mengikuti PERSIS docs/research/abilities-mcp-api.md §6:
#   1) STDIO  : echo '<json-rpc>' | wp mcp-adapter serve --user=U --server=S
#   2) HTTP   : curl dua langkah initialize → tangkap Mcp-Session-Id → request
#               berikutnya membawa header Mcp-Session-Id (Application Password).
#
# Kredensial TIDAK pernah di-hardcode:
#   - STDIO  : memakai user WP-CLI via BCS_CLI_USER (default: admin).
#   - HTTP   : memakai env var BCS_WP_USER + BCS_APP_PASSWORD (Application
#              Password), wajib diisi operator/orchestrator sebelum eksekusi.
#
# Semua skrip E2E: set -euo pipefail, aman dijalankan ulang (idempoten).
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Konfigurasi (semua dioverride via env; nilai default = environment cf)
# ---------------------------------------------------------------------------
BCS_SITE_URL="${BCS_SITE_URL:-https://cf.djavaweb.com}"
BCS_WP_ROOT="${BCS_WP_ROOT:-/home/runcloud/webapps/cf}"
BCS_WP_BIN="${BCS_WP_BIN:-/usr/local/bin/wp}"
BCS_MCP_SERVER="${BCS_MCP_SERVER:-mcp-adapter-default-server}"
BCS_CLI_USER="${BCS_CLI_USER:-admin}"
BCS_TRANSPORT="${BCS_TRANSPORT:-stdio}" # stdio | http

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-e2e-state.json}"
BCS_FIXTURE="${BCS_FIXTURE:-$REPO_ROOT/plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv}"
BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-e2e-results-${BCS_SCRIPT_NAME:-run}.jsonl}"

# ---------------------------------------------------------------------------
# Logging & hasil PASS/FAIL/STOP (JSON Lines di stdout + file)
# ---------------------------------------------------------------------------
bcs_log() { printf '%s\n' "$*" >&2; }

bcs_record() { # $1 status (PASS|FAIL|STOP|INFO)  $2 step  $3 detail
	local status="$1" step="$2" detail="${3:-}"
	detail="${detail//\\/\\\\}"; detail="${detail//\"/\\\"}"; detail="${detail//$'\n'/ }"
	local line
	line="$(printf '{"ts":"%s","script":"%s","step":"%s","status":"%s","detail":"%s"}' \
		"$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${BCS_SCRIPT_NAME:-unknown}" "$step" "$status" "$detail")"
	printf '%s\n' "$line" | tee -a "$BCS_RESULTS_FILE"
}

bcs_pass() { bcs_record PASS "$1" "${2:-}"; }
bcs_fail() { bcs_record FAIL "$1" "${2:-}"; }
bcs_stop() { bcs_record STOP "$1" "${2:-}"; }
bcs_info() { bcs_record INFO "$1" "${2:-}"; }

bcs_results_reset() { : > "$BCS_RESULTS_FILE"; }

# Selesai: ringkasan JSON; exit 0=PASS, 1=ada FAIL, 2=ada STOP (abort/wait).
bcs_finish() {
	local fail=0 stop=0 total=0
	if [ -s "$BCS_RESULTS_FILE" ]; then
		fail="$(jq -s --arg s "${BCS_SCRIPT_NAME:-unknown}" '[.[] | select(.script==$s and .status=="FAIL")] | length' "$BCS_RESULTS_FILE" 2>/dev/null || echo 0)"
		stop="$(jq -s --arg s "${BCS_SCRIPT_NAME:-unknown}" '[.[] | select(.script==$s and .status=="STOP")] | length' "$BCS_RESULTS_FILE" 2>/dev/null || echo 0)"
		total="$(jq -s --arg s "${BCS_SCRIPT_NAME:-unknown}" '[.[] | select(.script==$s)] | length' "$BCS_RESULTS_FILE" 2>/dev/null || echo 0)"
	fi
	local overall="PASS"
	[ "$fail" -gt 0 ] && overall="FAIL"
	[ "$fail" -eq 0 ] && [ "$stop" -gt 0 ] && overall="STOP"
	printf '{"script":"%s","summary":"%s","steps":%s,"failed":%s,"stopped":%s}\n' \
		"${BCS_SCRIPT_NAME:-unknown}" "$overall" "$total" "$fail" "$stop"
	[ "$fail" -gt 0 ] && exit 1
	[ "$stop" -gt 0 ] && exit 2
	exit 0
}

# ---------------------------------------------------------------------------
# WP-CLI
# ---------------------------------------------------------------------------
bcs_wp() { "$BCS_WP_BIN" --path="$BCS_WP_ROOT" "$@"; }

bcs_table_prefix() { bcs_wp db prefix 2>/dev/null | tail -n 1 || true; }

bcs_db_scalar() { # $1 = SQL; prints single scalar
	bcs_wp db query --skip-column-names "$1" 2>/dev/null | tail -n 1 || true
}

# ---------------------------------------------------------------------------
# Transport MCP: STDIO (default) & HTTP (Application Password)
# ---------------------------------------------------------------------------
bcs_http_endpoint() { printf '%s/wp-json/mcp/%s' "$BCS_SITE_URL" "$BCS_MCP_SERVER"; }

# Langkah 1 HTTP: initialize → cetak Mcp-Session-Id dari response header.
bcs_http_session() {
	: "${BCS_WP_USER:?Env BCS_WP_USER wajib di-set untuk transport http}"
	: "${BCS_APP_PASSWORD:?Env BCS_APP_PASSWORD (Application Password) wajib di-set untuk transport http}"
	local headers session
	headers="$(curl -sS --max-time 30 -D - -o /dev/null -X POST "$(bcs_http_endpoint)" \
		--user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
		-H "Content-Type: application/json" \
		-d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"bcs-e2e","version":"1.0.0"}}}')" || return 1
	session="$(printf '%s\n' "$headers" | tr -d '\r' | awk 'tolower($1)=="mcp-session-id:" {print $2}' | head -n 1)"
	[ -n "$session" ] || return 1
	printf '%s' "$session"
}

BCS_HTTP_SESSION=""

bcs_stdio_rpc() { # $1 = payload JSON-RPC lengkap
	printf '%s\n' "$1" | bcs_wp mcp-adapter serve --user="$BCS_CLI_USER" --server="$BCS_MCP_SERVER" 2>/dev/null
}

bcs_http_rpc() { # $1 = payload JSON-RPC lengkap
	[ -n "$BCS_HTTP_SESSION" ] || BCS_HTTP_SESSION="$(bcs_http_session)"
	curl -sS --max-time 300 -X POST "$(bcs_http_endpoint)" \
		--user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
		-H "Content-Type: application/json" \
		-H "Mcp-Session-Id: $BCS_HTTP_SESSION" \
		-d "$1"
}

# bcs_rpc <method> <params-json> → cetak SATU objek JSON-RPC response (result/error).
bcs_rpc() {
	local method="$1" params="${2:-{}}"
	local id payload raw
	id="${BCS_RPC_ID:-700}"
	payload="$(printf '{"jsonrpc":"2.0","id":%s,"method":"%s","params":%s}' "$id" "$method" "$params")"
	if [ "$BCS_TRANSPORT" = "http" ]; then
		raw="$(bcs_http_rpc "$payload")"
	else
		raw="$(bcs_stdio_rpc "$payload")"
	fi
	[ -n "$raw" ] || return 1
	printf '%s\n' "$raw" | jq -c 'select(type=="object" and (has("result") or has("error")))' 2>/dev/null | tail -n 1
}

# Ekstrak payload hasil ability dari response tools/call.
# Urutan: .result.structuredContent → .result.content[0].text (di-parse JSON bila bisa).
bcs_rpc_result_json() { # $1 = response object
	local resp="$1" out="" text
	out="$(printf '%s' "$resp" | jq -r '.result.structuredContent // empty' 2>/dev/null || true)"
	if [ -z "$out" ] || [ "$out" = "null" ]; then
		text="$(printf '%s' "$resp" | jq -r '.result.content[0].text // empty' 2>/dev/null || true)"
		if [ -n "$text" ] && [ "$text" != "null" ]; then
			out="$(printf '%s' "$text" | jq -c . 2>/dev/null || printf '%s' "$text")"
		fi
	fi
	# Bungkus {result: …} (gotcha flattened output) dibuka bila satu-satunya kunci.
	if printf '%s' "$out" | jq -e 'type=="object" and (keys == ["result"])' >/dev/null 2>&1; then
		out="$(printf '%s' "$out" | jq -c '.result')"
	fi
	printf '%s' "$out"
}

BCS_LAST_RESPONSE=""

# bcs_call_ability <ability-name> <input-json> → cetak hasil ability (JSON bila bisa).
# Response JSON-RPC lengkap disimpan di BCS_LAST_RESPONSE untuk asersi lanjutan.
bcs_call_ability() {
	local ability="$1" input="${2:-{}}" params resp
	params="$(printf '{"name":"mcp-adapter-execute-ability","arguments":{"ability":"%s","input":%s}}' "$ability" "$input")"
	resp="$(bcs_rpc tools/call "$params")" || return 1
	BCS_LAST_RESPONSE="$resp"
	bcs_rpc_result_json "$resp"
}

# 0 = response menandakan SUKSES; 1 = ada indikasi error JSON-RPC / isError.
bcs_last_response_ok() {
	[ -n "$BCS_LAST_RESPONSE" ] || return 1
	if printf '%s' "$BCS_LAST_RESPONSE" | jq -e '.error != null' >/dev/null 2>&1; then return 1; fi
	if printf '%s' "$BCS_LAST_RESPONSE" | jq -e '.result.isError == true' >/dev/null 2>&1; then return 1; fi
	return 0
}

# 0 = response mengandung indikasi DITOLAK (error / isError / pesan berisi pola).
bcs_last_response_rejected() { # $1 = pola teks tambahan (regex, opsional)
	if ! bcs_last_response_ok; then return 0; fi
	local res pattern="$1"
	res="$(bcs_rpc_result_json "$BCS_LAST_RESPONSE")"
	if printf '%s' "$res" | jq -e 'type=="object" and has("code") and has("message")' >/dev/null 2>&1; then return 0; fi
	if [ -n "$pattern" ] && printf '%s' "$res" | grep -Eq "$pattern"; then return 0; fi
	if printf '%s' "$BCS_LAST_RESPONSE" | grep -Eq "$pattern"; then return 0; fi
	return 1
}

# ---------------------------------------------------------------------------
# State antar skrip (JSON kecil di BCS_STATE_FILE; rerun-aman)
# ---------------------------------------------------------------------------
bcs_state_init() {
	[ -f "$BCS_STATE_FILE" ] || printf '{}\n' > "$BCS_STATE_FILE"
	jq -e . "$BCS_STATE_FILE" >/dev/null 2>&1 || printf '{}\n' > "$BCS_STATE_FILE"
}

bcs_state_get() { # $1 = jq path, mis. .job_id
	[ -s "$BCS_STATE_FILE" ] || return 0
	jq -r "$1 // empty" "$BCS_STATE_FILE" 2>/dev/null || true
}

bcs_state_set() { # $1 key  $2 value-string
	local key="$1" value="$2" tmp
	tmp="$(mktemp)"
	jq --arg k "$key" --arg v "$value" '. + {($k): $v}' "$BCS_STATE_FILE" > "$tmp" && mv "$tmp" "$BCS_STATE_FILE"
}

# ---------------------------------------------------------------------------
# Pra-syarat lingkungan (dipakai semua skrip; detail lengkap di 00-prereq.sh)
# ---------------------------------------------------------------------------
bcs_require_tools() {
	local missing=""
	command -v jq >/dev/null 2>&1 || missing="$missing jq"
	command -v curl >/dev/null 2>&1 || missing="$missing curl"
	if [ -n "$missing" ]; then
		bcs_fail "tools-present" "binary tidak ditemukan:$missing"
		return 1
	fi
	bcs_pass "tools-present" "jq+curl tersedia"
}

bcs_require_wp() {
	if [ ! -x "$BCS_WP_BIN" ]; then
		bcs_fail "wp-binary" "$BCS_WP_BIN tidak executable"
		return 1
	fi
	if ! bcs_wp core version >/dev/null 2>&1; then
		bcs_fail "wp-reachable" "wp core version gagal di $BCS_WP_ROOT"
		return 1
	fi
	bcs_pass "wp-reachable" "$(bcs_wp core version 2>/dev/null | tail -n 1)"
}

# Hitung ability bcs/* yang tampil dari raw response discovery.
bcs_count_bcs_abilities() { # $1 = raw text (response discovery apa pun bentuknya)
	printf '%s' "$1" | grep -o 'bcs/[a-z0-9-]*' | sort -u | wc -l | tr -d '[:space:]'
}

bcs_list_bcs_abilities() {
	printf '%s' "$1" | { grep -o 'bcs/[a-z0-9-]*' || true; } | sort -u | tr '\n' ' ' || true
}
