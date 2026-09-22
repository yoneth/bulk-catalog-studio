# BCS Handoff — Bulk Catalog Studio + Google Sheets Sync

**Date:** 2025 (session handoff from Pi to Claude Code)
**Repo:** `/Users/rio/Pi/cf.djavaweb`
**Live site:** `https://cf.djavaweb.com`
**Webroot (VPS):** `/home/runcloud/webapps/cf`
**SSH alias:** `djavaweb`
**MCP endpoint:** `https://cf.djavaweb.com/wp-json/bcs-mcp/mcp`

---

## 1. What this project is

**Bulk Catalog Studio (BCS)** — a WordPress plugin for safe bulk WooCommerce catalog
imports via a custom MCP server. Every write goes **preview → approval → execute**
with audit log and rollback. V2 adds **one-way Google Sheets → WooCommerce sync**.

**Hard constraints (do not violate):**

- All writes require preview/approval/execute. Agent (MCP) edits must NOT bypass BCS approval.
- PHP 7.4-compatible syntax, WooCommerce-native CRUD, WordPress coding standards.
- All user-facing admin/MCP/error/README text in **English**. Text domain: `bulk-catalog-studio`.
- Do NOT use Novamira. Use the custom MCP server at `/wp-json/bcs-mcp/mcp`.
- Do NOT commit or deploy without explicit user approval. No SSH by subagents.

---

## 2. Current status (verified)

- Plugin deployed and upgraded to **DB version 1.2.0** on live site.
- Tables: `wp_bcs_jobs`, `wp_bcs_plans`, `wp_bcs_audit` (existing) +
  `wp_bcs_connections`, `wp_bcs_sync_runs` (new). Audit has `source` column.
- **Important:** `wp_bcs_sync_runs` DB column is `run_trigger` (public key stays `trigger`).
  The reserved word `trigger` previously made `dbDelta()` silently skip the table — fixed in commit `ffcf3dc`.
- **11 MCP tools live**, including `bcs-sync-list-connections`, `bcs-sync-run`,
  `bcs-sync-approve-run`.
- Local verification: **275 PASS / 0 FAIL** across 7 PHP harnesses; PHP lint + PHP 7.4 lint
  on VPS + bash syntax + `git diff --check` all green.
- Public fixture CSVs served at `https://cf.djavaweb.com/bcs-e2e/`
  (`main.csv`, `empty.csv`, `missing.csv`, `price.csv`, `delete.csv`, `rollback.csv`).

## 3. Key recent commits

| Commit    | Content                                                        |
|-----------|----------------------------------------------------------------|
| `e56848b` | Google Sheets sync research (29 sources)                       |
| `f94f441` | Sync spec `docs/spec/bcs-sync.md`                              |
| `6207cf0` | Gate-1 sync decisions                                          |
| `fa82836` | Phase 1–4: schema, encryption, CSV fetcher, diff/guard, runner, variation writer, rollback, CLI |
| `969a826` | Phase 5–7: 3 sync MCP tools, approval tokens, 4-tab Sync admin UI, quarantine/conflict actions |
| `d30e9cf` | Phase 8–9: Sheets API JWT fetcher, ownership gate, 6 E2E scripts, runbook |
| `ffcf3dc` | Reserved-column fix (`trigger` → `run_trigger`)                |
| `f2c74af` | Localization + audit pagination (deployed, verified live)      |

## 4. Google Sheets fixture — the one open blocker

Goal: 6 tabs in one Google Sheet (`main`, `empty`, `missing`, `price`, `delete`,
`rollback`), each matching `/tmp/bcs-sheet/*.tsv` (local) and
`https://cf.djavaweb.com/bcs-e2e/*.csv` (public).

**Status:** another agent (account "Dul") created
**"BCS Demo Catalog"** —
`https://docs.google.com/spreadsheets/d/1stA3UtLNyRx3FNK44eJad1jqMM3qg8aAhpYSQr7io_0/edit?gid=0`
— containing the full 30-product `main` fixture in `Sheet1` (gid=0), shared
*Anyone with the link → Viewer* (public CSV export verified: 11,392 bytes, 30 rows).

**Missing:** the 5 variant tabs (`empty`, `missing`, `price`, `delete`, `rollback`).

**What does NOT work (already tried — do not retry blindly):**

- Google Sheets MCP (`https://sheetsmcp.googleapis.com/mcp/v1`): hard-blocked —
  project `89334931753` is not enrolled in the Google Workspace Developer Preview
  Program. Reconnect/re-auth does not help.
- Orca browser keyboard injection into Google Sheets (`orca type`, `inserttext`,
  formula bar, Edit→Paste menu): Sheets rejects non-trusted input; paste menu asks
  for clipboard permission that never sticks; Apps Script editor got polluted by
  stray keystrokes. **Do not use OS-level keystroke injection at all** (explicit ban
  after an incident where fixture text leaked into a TUI).

**Viable options to finish the fixture:**

1. Ask the user (Mas Rio) to run the Apps Script in `/tmp/bcs-code.js` (also at
   `/tmp/bcs-fill.gs`) — open https://script.new, paste, Run → `bcsFill` → Allow.
   It pulls all 6 CSVs from the webroot into 6 tabs. ~1 minute. **Note:** update the
   script's spreadsheet ID to `1stA3UtLNyRx3FNK44eJad1jqMM3qg8aAhpYSQr7io_0` (the Dul
   sheet) OR keep the older sheet `14ELf0sb31jP4Meh9_JlRO4rPjq2mPQcSxOktvxqh3rM`
   (owned by Rio's account, currently has corrupt test residue in gid=0 — would need
   clearing; the script's `sh.clear()` handles that per tab).
2. Ask the user to manually paste the 5 missing TSVs from `/tmp/bcs-sheet/`.
3. Delegate to the other pane agent (account Dul, proven able to write via Sheets UI)
   via pi-intercom session `01a0c29d` (same cwd) — but that agent's write path was
   manual UI entry, slow for 5 more tabs.

## 5. Next steps after fixture is ready

1. Verify each tab exports correctly via
   `https://docs.google.com/spreadsheets/d/<ID>/export?format=csv&gid=<GID>`;
   record tab GIDs (get them by switching tabs and reading the URL, or from the
   sheet metadata page).
2. Run live E2E in order:
   `tests/e2e/sync-00-prereq.sh` → `sync-01` → `sync-02` → `sync-03` → `sync-04` →
   `sync-05-agent-gate.sh`. Inspect every failure manually; never auto-retry writes.
3. Fix CLI cron upgrade path: `bcs_maybe_upgrade()` is only hooked to `admin_init`;
   WP-CLI cron runs needed a manual `wp eval 'bcs_maybe_upgrade();'`. Add upgrade
   invocation for CLI context (e.g. also hook `cli_init` or call in `wp bcs` command
   bootstrap).
4. Install system cron: `wp bcs sync run-due` (not WP-Cron).
5. Verify live Sync admin UI tabs (4 tabs), approval token flow, quarantine,
   conflict resolution, variation sync.
6. Staging-only: uninstall acceptance test + kill/resume (chaos) verification.
   **No staging environment exists yet** — ask the user before touching live.

## 6. E2E environment notes

- E2E scripts live in `tests/e2e/` (`sync-00-prereq.sh` … `sync-05-agent-gate.sh`).
- Connection source type for live sheet runs: `csv_export` with the public
  `export?format=csv&gid=<GID>` URL. `sheets_api` (JWT) path exists but has no
  service-account JSON available locally.
- Existing data state: job #1 is `rolled_back`; 28 products remain in Trash.
  Products #60 and #77 were used for UI tests; product #60 price = `169000`.
- Non-blocking WP-CLI warning observed:
  `WordPress ability 'mcp-adapter/discover-abilities' does not exist.`

## 7. Docs to read first (in this order)

1. `docs/spec/bcs-sync.md` — the sync specification (source of truth).
2. `docs/spec/gate1-sync-decisions.md` — locked decisions (thresholds, delete
   policy `sync_action=delete` only, conflict = flag_only, MySQL `GET_LOCK`,
   rollback = latest run only, approval tokens one-time/hash-only/bound to diff_hash).
3. `docs/research/gsheets-sync.md` — research backing (29 sources).
4. `docs/test/e2e-runbook.md` — E2E runbook.
5. Plugin entry: `plugin/bulk-catalog-studio/` — key classes in `includes/`:
   `class-bcs-sync-runner.php`, `class-bcs-abilities.php`, `class-bcs-install.php`,
   `class-bcs-rollback-service.php`, `class-bcs-schemas.php`; admin in
   `admin/class-bcs-admin.php`.

## 8. Rules of engagement

- Communicate with the user ("Mas Rio") in Indonesian; address him as "Mas Rio".
- Keep user-facing plugin text in English.
- Never inject keystrokes into other Orca/browser sessions.
- Never touch Google Cloud private tabs.
- Ask before: deploying, committing destructive changes, running writes against the
  live WooCommerce store beyond the approved E2E fixtures, or using Google Flow credits.
- Report progress concisely with file paths and command output evidence.
