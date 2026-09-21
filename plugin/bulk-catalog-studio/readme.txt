=== Bulk Catalog Studio ===
Contributors: cfdjavaweb
Tags: woocommerce, mcp, ai, bulk import, catalog, abilities-api
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, mcp-adapter
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns CSV / Google Sheets exports / prompt-produced rows into native WooCommerce products via the WordPress Abilities API + MCP, with a mandatory human approval pipeline.

== Description ==

AI Bulk Catalog Studio (BCS) registers 11 abilities (`bcs/*`) with the WordPress Abilities API and exposes them as individual MCP tools through its isolated custom server, built with the official MCP Adapter:

1. `bcs/parse-source` — parse inline CSV / attachment / public Google Sheets CSV export URL → new job with status `parsed`.
2. `bcs/check-duplicates` — detect intra-batch duplicates and rows matching the existing catalog (SKU + normalized name).
3. `bcs/build-plan` — materialized execution plan (create/update/skip + final payload + `before` snapshot).
4. `bcs/approve-plan` — approval gate: one-time token shown ONLY on the WP admin screen.
5. `bcs/execute-batch` — execute the plan via WC_Product CRUD (chunks of 10 operations, heartbeat, dry_run, idempotent resume).
6. `bcs/job-status` — list recent jobs / details of one job (including staleness).
7. `bcs/rollback-batch` — trash new products (soft) + restore the before snapshot for updates.
8. `bcs/audit-log` — call + row level audit trail per job.
9. `bcs/sync-list-connections` — list configured sync connections and pending manual work.
10. `bcs/sync-run` — run one guarded Google Sheets/catalog sync connection.
11. `bcs/sync-approve-run` — approve a threshold-held run with its one-time token.

Full pipeline: **parse → duplicate-check → build-plan → HUMAN APPROVAL → execute → (optional) rollback**. No MCP write action happens without approval.

Products created by BCS are 100% native WooCommerce (`product`, `product_variation` CPTs, `product_cat`, `product_tag`, `pa_*` taxonomies) — editable from the regular WooCommerce admin even with the plugin deactivated.

== Installation ==

1. Upload the `bulk-catalog-studio` folder to `/wp-content/plugins/`.
2. Make sure WooCommerce is active, the **mcp-adapter** plugin is active, and WordPress ≥ 6.9.
3. Activate the plugin through the Plugins menu. The `bcs_jobs`, `bcs_plans`, `bcs_audit`, `bcs_connections`, and `bcs_sync_runs` tables are created or upgraded automatically.
4. The custom MCP server is live at **`/wp-json/bcs-mcp/mcp`** — the default `/wp-json/mcp/mcp-adapter-default-server` endpoint is not used (intercepted by Novamira on the target server).
5. Create a dedicated MCP user (e.g. `mcp-catalog`) with the capabilities: `read`, `edit_products`, `manage_product_terms`, `manage_woocommerce`. Not an administrator.
6. Create an Application Password for that user (Users → Profile → Application Passwords).

== Connecting Claude ==

Configure `mcpServers` (Claude Desktop / Claude Code) using the official `@automattic/mcp-wordpress-remote` proxy:

    {
      "mcpServers": {
        "bulk-catalog-studio": {
          "command": "npx",
          "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
          "env": {
            "WP_API_URL": "https://cf.djavaweb.com/wp-json/bcs-mcp/mcp",
            "OAUTH_ENABLED": "false",
            "WP_API_USERNAME": "mcp-catalog",
            "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
          }
        }
      }
    }

Important notes:
- `OAUTH_ENABLED=false` is required so the proxy uses the Application Password (not OAuth).
- The primary endpoint is the BCS custom server: `https://<domain>/wp-json/bcs-mcp/mcp`. The default `/wp-json/mcp/...` endpoint is NOT used (intercepted by Novamira on the target server) — the bare domain must also be avoided (it resolves to the deprecated legacy `wordpress-mcp`).
- `WP_API_USERNAME` = WP login username (not an email); `WP_API_PASSWORD` = Application Password (not the account password, not a WooCommerce REST API key).
- On this custom server the 11 BCS tools appear individually in `tools/list` (`bcs-parse-source`, `bcs-sync-list-connections`, `bcs-sync-run`, `bcs-sync-approve-run`, etc.).
- The custom server transport permission requires `manage_woocommerce` (plus a per-ability re-check).
- Sync administration is under **Bulk Catalog → Sync** with Connections, Runs, Quarantine, and Conflicts tabs.
- `bcs/sync-list-connections` is read-only status; `bcs/sync-run` runs one locked connection; `bcs/sync-approve-run` requires the `diff_hash` and one-time `BCS-XXXXXXXX` token shown on the Runs tab.

== Sync administration ==

The Sync screen provides four tabs:

* **Connections** — add, edit, pause/resume, run active connections, test, or delete paused connections. Tests fetch and parse only; they never write products.
* **Runs** — inspect diff summaries, errors, hashes, durations, and approval gates. Tokens are hash-only at rest and shown once.
* **Quarantine** — manually Apply anyway or Discard field-level and ownership holds.
* **Conflicts** — choose Sheet wins (reapply the persisted source payload) or Keep WP version (record the current WP payload hash) for manual WooCommerce edits; every resolution is audited.

The operational fetcher in this build is `csv_export`. `sheets_api` remains reserved and returns a clear not-implemented error until the production credentials flow is shipped.

== Example flow for one job ==

1. Claude calls `bcs-parse-source` (a 30-product fashion CSV: 20 simple + 10 variable size×color ≈ 60 variations).
2. Claude calls `bcs-check-duplicates` → 2 duplicates found.
3. A human decides: row 5 is updated, row 19 is skipped. Claude calls `bcs-build-plan` with `on_duplicate` + `row_overrides`.
4. A human opens **Bulk Catalog → Job → Diff preview** in wp-admin, reviews the diff, clicks **Generate token approval** → token `BCS-XXXXX` is shown ONCE.
5. Claude calls `bcs-approve-plan` with the token from the human (or the human clicks the Approve button).
6. Claude calls `bcs-execute-batch` → products + variations are created (WC_Product CRUD).
7. Claude verifies via `bcs-job-status` + `bcs-audit-log`.
8. If something went wrong: `bcs-rollback-batch` with `confirm: true`.

== Frequently Asked Questions ==

= Does the plugin write products without approval? =

No. `bcs/execute-batch` rejects plans that are not `approved` yet. Approval requires a one-time token only a human can see on the admin screen (diff page), bound to the `plan_hash`, expiring after 24 hours, single-use.

= What happens on deactivation? =

No data is lost (AC-7): tables and settings are preserved. Only uninstall (delete plugin) drops the tables + `bcs_*` options. Products are never touched by uninstall.

= Which CSV format is supported? =

Comma / semicolon / tab delimiters; optional header row with `column_mapping`; multi-values for variable products are pipe-separated `|` (attribute options, variation SKUs, variation prices, variation stock in parallel). URL mode only accepts public CSV exports from docs.google.com (SSRF guard). V1 limits: 500 rows/job (default setting 100), 2 MB inline CSV, 10 MB attachment.

== Changelog ==

= 1.0.0 =
* Initial V1 release per spec `docs/spec/bulk-catalog-studio.md` + gate1 decisions.
* 11 `bcs/*` abilities (Abilities API + MCP Adapter, `meta.mcp.public`).
* Sync connections, guarded runs, quarantine/conflict resolution, and one-time run approval tokens.
* One-time batch approval token pipeline, snapshot rollback, per-row audit.
* Images: `attachment_id_only` (sideload postponed to V1.1 — gate1 decision #4).
* QA-1 rework: updates never touch unsent status/stock; job-scoped rollback;
  atomic single-use token consumption; SSRF guard (docs.google.com-only host,
  redirects off, private IP blocks); plan rebuild (superseded); resume without
  double-counting; CSV encoding conversion to UTF-8.
* Gate 4: audit pagination clamps out-of-range pages to the last valid page;
  all user-facing strings are English (i18n-ready with the `bulk-catalog-studio` text domain).
