#!/usr/bin/env bash
# =============================================================================
# lib.sh — helper bersama untuk skrip E2E Bulk Catalog Studio (BCS)
#          terhadap CUSTOM MCP SERVER yang sudah live:
#
#   Endpoint : https://cf.djavaweb.com/wp-json/bcs-mcp/mcp   (env BCS_MCP_ENDPOINT)
#   serverInfo.name : "Bulk Catalog Studio MCP"
#   tools/list      : 11 tool individual (bcs-parse-source, … bcs-sync-approve-run)
#   Invocation      : tools/call LANGSUNG ke nama tool individual —
#                     TIDAK ada meta-tool mcp-adapter-discover-abilities /
#                     mcp-adapter-execute-ability di custom server.
#   Transport       : HANYA HTTP (Application Password). Alur custom tidak
#                     memakai STDIO; helper STDIO generik tetap disediakan
#                     untuk server default adapter (opsional, tidak dipakai).
#
# Kredensial TIDAK pernah di-hardcode: env var BCS_WP_USER + BCS_APP_PASSWORD.
#
# Semua skrip E2E: set -euo pipefail, aman dijalankan ulang (idempoten).
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Konfigurasi (semua dioverride via env; nilai default = environment cf)
# ---------------------------------------------------------------------------
BCS_SITE_URL="${BCS_SITE_URL:-https://cf.djavaweb.com}"
BCS_MCP_ENDPOINT="${BCS_MCP_ENDPOINT:-${BCS_SITE_URL%/}/wp-json/bcs-mcp/mcp}"
BCS_MCP_SERVER_NAME="${BCS_MCP_SERVER_NAME:-Bulk Catalog Studio MCP}"
BCS_WP_ROOT="${BCS_WP_ROOT:-/home/runcloud/webapps/cf}"
BCS_WP_BIN="${BCS_WP_BIN:-/usr/local/bin/wp}"
BCS_CLI_USER="${BCS_CLI_USER:-admin}"
BCS_TRANSPORT="${BCS_TRANSPORT:-http}" # custom server: HANYA http (stdio = helper generik lama)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BCS_STATE_FILE="${BCS_STATE_FILE:-/tmp/bcs-e2e-state.json}"
BCS_FIXTURE="${BCS_FIXTURE:-$REPO_ROOT/plugin/bulk-catalog-studio/tests/fixtures/sample-fashion-30.csv}"
BCS_RESULTS_FILE="${BCS_RESULTS_FILE:-/tmp/bcs-e2e-results-${BCS_SCRIPT_NAME:-run}.jsonl}"
BCS_INIT_BODY="${BCS_INIT_BODY:-/tmp/bcs-e2e-init.json}" # body response initialize

# 11 tool individual custom server (nama dash — bukan bcs/* dan bukan meta-tool).
BCS_EXPECTED_TOOLS="bcs-parse-source bcs-check-duplicates bcs-build-plan bcs-approve-plan bcs-execute-batch bcs-job-status bcs-rollback-batch bcs-audit-log bcs-sync-list-connections bcs-sync-run bcs-sync-approve-run"

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
# WP-CLI (hanya untuk pra-cek lokal di VPS; alur MCP custom tidak lewat sini)
# ---------------------------------------------------------------------------
# `wp eval` menerima HANYA kode PHP. Argumen posisional yang mengisi $args
# adalah fitur `wp eval-file` (diverifikasi pada WP-CLI 2.12.0), sehingga
# pemanggilan `wp eval '<code>' -- a b` gagal dengan "Too many positional
# arguments" dan seluruh skrip sync berhenti di langkah pertamanya. Rute
# pemanggilan yang membawa argumen ke eval-file lewat berkas sementara, supaya
# pola $args[...] di semua skrip tetap berlaku tanpa perlu diubah satu per satu.
bcs_wp() {
	if [ "${1:-}" != "eval" ]; then
		"$BCS_WP_BIN" --path="$BCS_WP_ROOT" "$@"
		return $?
	fi
	shift
	local code="${1:-}"
	shift || true
	[ "${1:-}" != "--" ] || shift
	if [ "$#" -eq 0 ]; then
		"$BCS_WP_BIN" --path="$BCS_WP_ROOT" eval "$code"
		return $?
	fi
	local file status
	file="$(mktemp "${TMPDIR:-/tmp}/bcs-eval-XXXXXX.php")"
	printf '<?php\n%s\n' "$code" > "$file"
	"$BCS_WP_BIN" --path="$BCS_WP_ROOT" eval-file "$file" "$@"
	status=$?
	rm -f "$file"
	return $status
}

bcs_table_prefix() { bcs_wp db prefix 2>/dev/null | tail -n 1 || true; }

bcs_db_scalar() { # $1 = SQL; prints single scalar
	# WP-CLI dapat menambahkan baris kosong setelah hasil query; ambil baris
	# non-kosong terakhir agar scalar valid tidak hilang oleh `tail -n 1`.
	bcs_wp db query --skip-column-names "$1" 2>/dev/null | awk 'NF { last = $0 } END { print last }' || true
}

# ---------------------------------------------------------------------------
# Transport: HTTP (satu-satunya jalur custom server, Application Password)
# ---------------------------------------------------------------------------
bcs_require_http() {
	if [ "$BCS_TRANSPORT" != "http" ]; then
		bcs_stop "transport" "custom bcs-mcp server supports HTTP only — set BCS_TRANSPORT=http (default); stdio is only a legacy generic helper"
		return 1
	fi
	bcs_pass "transport" "transport=http → $BCS_MCP_ENDPOINT"
}

bcs_require_http_creds() {
	if [ -z "${BCS_WP_USER:-}" ] || [ -z "${BCS_APP_PASSWORD:-}" ]; then
		bcs_stop "http-creds" "BCS_WP_USER and BCS_APP_PASSWORD (Application Password) must be set — credentials are never hardcoded"
		return 1
	fi
	bcs_pass "http-creds" "credentials are set for user '${BCS_WP_USER}'"
}

# Langkah 1: initialize → simpan body ke BCS_INIT_BODY, cetak Mcp-Session-Id.
bcs_http_session() {
	: "${BCS_WP_USER:?BCS_WP_USER must be set for HTTP transport}"
	: "${BCS_APP_PASSWORD:?BCS_APP_PASSWORD (Application Password) must be set for HTTP transport}"
	local tmpdir session
	tmpdir="$(mktemp -d)"
	if ! curl -sS --max-time 30 -D "$tmpdir/h" -o "$tmpdir/b" -X POST "$BCS_MCP_ENDPOINT" \
		--user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
		-H "Content-Type: application/json" \
		-d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"bcs-e2e","version":"1.0.0"}}}' 2>/dev/null; then
		rm -rf "$tmpdir"
		return 1
	fi
	session="$(tr -d '\r' < "$tmpdir/h" | awk 'tolower($1)=="mcp-session-id:" {print $2}' | head -n 1 || true)"
	if [ -s "$tmpdir/b" ]; then
		mv "$tmpdir/b" "$BCS_INIT_BODY"
	fi
	rm -rf "$tmpdir"
	[ -n "$session" ] || return 1
	printf '%s' "$session"
}

BCS_HTTP_SESSION=""

bcs_http_rpc() { # $1 = payload JSON-RPC lengkap
	[ -n "$BCS_HTTP_SESSION" ] || BCS_HTTP_SESSION="$(bcs_http_session)"
	curl -sS --max-time 300 -X POST "$BCS_MCP_ENDPOINT" \
		--user "$BCS_WP_USER:$BCS_APP_PASSWORD" \
		-H "Content-Type: application/json" \
		-H "Mcp-Session-Id: $BCS_HTTP_SESSION" \
		-d "$1"
}

# Helper generik STDIO (server default adapter) — TIDAK dipakai alur custom.
bcs_stdio_rpc() { # $1 = payload JSON-RPC lengkap
	printf '%s\n' "$1" | bcs_wp mcp-adapter serve --user="$BCS_CLI_USER" --server="mcp-adapter-default-server" 2>/dev/null
}

# bcs_rpc <method> <params-json> → cetak SATU objek JSON-RPC response (result/error).
bcs_rpc() {
	local method="$1"
	local params="$2"
	[ -n "$params" ] || params='{}' 
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

# Ekstrak payload hasil tool dari response tools/call.
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
	# Bungkus {result: …} dibuka bila satu-satunya kunci.
	if printf '%s' "$out" | jq -e 'type=="object" and (keys == ["result"])' >/dev/null 2>&1; then
		out="$(printf '%s' "$out" | jq -c '.result')"
	fi
	printf '%s' "$out"
}

BCS_LAST_RESPONSE=""
BCS_LAST_RESPONSE_FILE="${BCS_LAST_RESPONSE_FILE:-/tmp/bcs-e2e-last-response.json}"

# bcs_call_tool <tool-name> <arguments-json> → panggilan LANGSUNG tools/call
# ke tool individual custom server (TANPA meta-tool). Response lengkap disimpan
# juga ke file karena pemanggil biasanya memakai command substitution; assignment
# variabel di subshell Bash tidak dapat bertahan pada proses induk.
bcs_call_tool() {
	local tool="$1"
	local args="$2"
	[ -n "$args" ] || args='{}'
	local params resp
	params="$(printf '{"name":"%s","arguments":%s}' "$tool" "$args")"
	resp="$(bcs_rpc tools/call "$params")" || return 1
	BCS_LAST_RESPONSE="$resp"
	printf '%s' "$resp" > "$BCS_LAST_RESPONSE_FILE"
	bcs_rpc_result_json "$resp"
}

bcs_last_response_raw() {
	if [ -n "$BCS_LAST_RESPONSE" ]; then
		printf '%s' "$BCS_LAST_RESPONSE"
	elif [ -s "$BCS_LAST_RESPONSE_FILE" ]; then
		cat "$BCS_LAST_RESPONSE_FILE"
	fi
}

# 0 = response menandakan SUKSES; 1 = ada indikasi error JSON-RPC / isError.
bcs_last_response_ok() {
	local last
	last="$(bcs_last_response_raw)"
	[ -n "$last" ] || return 1
	if printf '%s' "$last" | jq -e '.error != null' >/dev/null 2>&1; then return 1; fi
	if printf '%s' "$last" | jq -e '.result.isError == true' >/dev/null 2>&1; then return 1; fi
	return 0
}

# 0 = response mengandung indikasi DITOLAK (error / isError / pesan berisi pola).
bcs_last_response_rejected() { # $1 = pola teks tambahan (regex, opsional)
	if ! bcs_last_response_ok; then return 0; fi
	local last res pattern="$1"
	last="$(bcs_last_response_raw)"
	res="$(bcs_rpc_result_json "$last")"
	if printf '%s' "$res" | jq -e 'type=="object" and has("code") and has("message")' >/dev/null 2>&1; then return 0; fi
	if [ -n "$pattern" ] && printf '%s' "$res" | grep -Eq "$pattern"; then return 0; fi
	if [ -n "$pattern" ] && printf '%s' "$last" | grep -Eq "$pattern"; then return 0; fi
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
# Pra-syarat lingkungan
# ---------------------------------------------------------------------------
bcs_require_tools() {
	local missing=""
	command -v jq >/dev/null 2>&1 || missing="$missing jq"
	command -v curl >/dev/null 2>&1 || missing="$missing curl"
	if [ -n "$missing" ]; then
		bcs_fail "tools-present" "binaries not found:$missing"
		return 1
	fi
	bcs_pass "tools-present" "jq+curl are available"
}

bcs_require_wp() {
	if [ ! -x "$BCS_WP_BIN" ]; then
		bcs_fail "wp-binary" "$BCS_WP_BIN is not executable"
		return 1
	fi
	if ! bcs_wp core version >/dev/null 2>&1; then
		bcs_fail "wp-reachable" "wp core version failed at $BCS_WP_ROOT"
		return 1
	fi
	bcs_pass "wp-reachable" "$(bcs_wp core version 2>/dev/null | tail -n 1)"
}

# Hitung tool bcs-* yang tampil di response tools/list (nama dash).
bcs_count_bcs_tools() { # $1 = raw text (response tools/list apa pun bentuknya)
	printf '%s' "$1" | { grep -o 'bcs-[a-z0-9-]*' || true; } | sort -u | wc -l | tr -d '[:space:]'
}

bcs_list_bcs_tools() {
	printf '%s' "$1" | { grep -o 'bcs-[a-z0-9-]*' || true; } | sort -u | tr '\n' ' ' || true
}
