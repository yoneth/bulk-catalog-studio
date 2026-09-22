# Bulk Catalog Studio (BCS)

A small WordPress plugin that turns tabular data sources (CSV / public Google
Sheets CSV export / prompt-produced rows from Claude) into **native WooCommerce
products**, through a 6-gate pipeline with mandatory human approval:

```
parse → duplicate-check → build-plan → HUMAN APPROVAL (one-time token) → execute → (optional) rollback
```

The plugin registers 11 `bcs/*` abilities via the **WordPress Abilities API**
(`wp_register_ability`, hook `wp_abilities_api_init`) and exposes them through
its **own custom MCP server** (`bcs-mcp-server`, endpoint
`/wp-json/bcs-mcp/mcp`) created via `mcp_adapter_init` + `create_server()`
(the official MCP Adapter pattern). The default endpoint
`/wp-json/mcp/mcp-adapter-default-server` **is NOT used** — on the target
server that route is intercepted by Novamira (`serverInfo: Novamira (legacy alias)`).

On this custom server the 11 abilities appear as **individual tools** in
`tools/list` (`bcs-parse-source`, `bcs-check-duplicates`, `bcs-build-plan`,
`bcs-approve-plan`, `bcs-execute-batch`, `bcs-job-status`, `bcs-rollback-batch`,
`bcs-audit-log`, `bcs-sync-list-connections`, `bcs-sync-run`,
`bcs-sync-approve-run`) — not only via meta-tools discovery.

Products are written 100% through `WC_Product` / `WC_Product_Variable` /
`WC_Product_Variation` CRUD (`WC_Product_Variable::sync()` after variations) —
**no** `wp_insert_post`/manual meta — so the result remains fully editable from
the regular WooCommerce admin, even with this plugin deactivated.

---

## 1. Install

1. Copy the `bulk-catalog-studio/` folder to `/wp-content/plugins/`.
2. Prerequisites: WordPress ≥ 6.9, PHP ≥ 7.4, WooCommerce active, MCP Adapter
   active (the `/wp-json/mcp/mcp-adapter-default-server` endpoint responds 401
   without auth).
3. Activate the plugin → the `bcs_jobs`, `bcs_plans`, `bcs_audit`,
   `bcs_connections`, and `bcs_sync_runs` tables are created or upgraded
   automatically (dbDelta) along with the `bcs_settings` option.
4. Admin pages: **Bulk Catalog** (menu `admin.php?page=bcs-jobs`) and
   **Bulk Catalog → Sync** (menu `admin.php?page=bcs-sync`).

## 2. Connect Claude (MCP)

Create a dedicated MCP user (not an administrator), e.g. `mcp-catalog`, with the
capabilities: `read`, `edit_products`, `manage_product_terms`,
`manage_woocommerce`. Then create an **Application Password** (Users → Profile
→ Application Passwords).

Configure `mcpServers` (Claude Desktop / Claude Code) using the official
`@automattic/mcp-wordpress-remote` proxy — **point it at the BCS custom
endpoint**:

```json
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
```

(Replace the host with your site: the pattern is
`https://<domain>/wp-json/bcs-mcp/mcp`.)

Notes:

| Env / behavior | Rule |
|---|---|
| `WP_API_URL` | BCS custom endpoint: `https://<domain>/wp-json/bcs-mcp/mcp`. Do **NOT** use `/wp-json/mcp/mcp-adapter-default-server` (intercepted by Novamira on the target server) or the bare domain (resolves to the deprecated legacy `wordpress-mcp`). |
| `OAUTH_ENABLED` | `false` — required so the proxy uses the Application Password, not OAuth. |
| `WP_API_USERNAME` | WP login username (not an email address). |
| `WP_API_PASSWORD` | Application Password (not the account password, not a WooCommerce REST key). |
| Transport permission | The custom server requires `manage_woocommerce` at the transport level (13th parameter of `create_server`), plus a per-ability capability re-check. |

Because this endpoint is a **custom server**, all 11 BCS tools appear directly in
`tools/list` (`bcs-parse-source`, … `bcs-sync-approve-run`) — no need for the
`mcp-adapter-discover-abilities` meta-tool used by the default server. The
default `mcp-adapter-default-server` would still expose the BCS abilities (via
`meta.mcp.public`) if that route were used, but the supported and tested path
is the custom endpoint above.

## 3. Example flow for one job (30-row fashion CSV)

Sample fixtures: `tests/fixtures/sample-fashion-30.csv` (20 simple + 10 variable
size×color ≈ 60 variations, 2 duplicate SKUs) and
`tests/fixtures/sample-prompt-rows.json` (example inline rows from Claude).

1. **`bcs-parse-source`** — send the CSV (inline `csv_text`, `attachment_id`, or
   a public Google Sheets `url` CSV export) + `column_mapping`.
   Output: `job_id`, `source_hash`, `total_rows`, warnings.
2. **`bcs-check-duplicates`** — `{ job_id, strategy: "sku+title", on_duplicate: "flag_only" }`.
3. A human decides the fate of duplicates; Claude calls
   **`bcs-build-plan`** — `{ job_id, on_duplicate: "update", row_overrides: [{row_index: 19, op: "skip"}] }`.
   Output: `plan_id`, `plan_hash`, `summary`, `diff_preview_url`.
4. A human opens **wp-admin → Bulk Catalog → Job → Diff preview**, reviews the
   create/update/skip table + before/after, then clicks **Generate token approval**
   → token `BCS-XXXXX` is shown **ONCE** (stored as a hash; expires after
   24 hours; single-use; bound to `plan_hash`).
5. Claude calls **`bcs-approve-plan`** with the token from the human — OR the
   human clicks the **Approve** button directly on the screen (pick one).
6. Claude calls **`bcs-execute-batch`** — optionally `dry_run: true` for a
   simulation. The server writes in chunks of 10 operations + heartbeat; it
   supports `resume: true` if the request dies (idempotent per `row_key`).
7. Verify: **`bcs-job-status`** (`stale` flag) + **`bcs-audit-log`** (`row`
   level for the per-product/variation trail).
8. If you need to undo: **`bcs-rollback-batch`** with `confirm: true` and
   `scope: all|created_only|updated_only`. New products → trashed (soft) along
   with their variations; updated products → restored to the `before` snapshot.

## 4. Sync administration

The Sync screen (`admin.php?page=bcs-sync`) has four tabs:

- **Connections** — add, edit, pause/resume, run active connections, test, or
  delete paused connections. Connection tests fetch and parse only; they never
  write catalog data.
- **Runs** — inspect state, diff hash, summary, duration, errors, and threshold
  approval. Generate an approval token here; the plaintext token is shown once.
- **Quarantine** — review field-level or ownership holds and choose **Apply
  anyway** or **Discard**. Both actions require `manage_woocommerce` and a nonce.
- **Conflicts** — resolve a manual WooCommerce edit with **Sheet wins** (reapply
  the persisted source payload) or **Keep WP version** (record the current WP
  payload hash). Each resolution is audited.

The Sync MCP tools are `bcs/sync-list-connections` (read status),
`bcs/sync-run` (run one locked connection), and `bcs/sync-approve-run`
(approve an `awaiting_approval` run with its one-time `BCS-XXXXXXXX` token and
matching `diff_hash`). A run never bypasses its fetch, diff, quarantine,
conflict, or threshold guards. The operational fetcher in this build is
`csv_export`; `sheets_api` is reserved and returns a clear not-implemented error
until its production credentials flow is shipped.

## 5. CSV format

- Delimiters: comma, semicolon, or `tab`.
- The first row is usually a header; mapping via `column_mapping`
  (`"Name": "name"`, etc.). Without mapping, headers identical to canonical
  field names are auto-detected.
- Canonical fields: `name, description, short_description, sku, regular_price,
  sale_price, stock_quantity, weight, length, width, height, categories, tags,
  attribute:<slug>, variation_sku, variation_attribute:<slug>,
  variation_regular_price, variation_stock_quantity, image_url, image_alt,
  seo_title, seo_description`. The `dimensions.length` form etc. is also
  accepted.
- **Multi-values for variable products** are pipe-separated `|` (one row = one
  product): `attribute:pa_ukuran = S|M|L`, `variation_sku = SKU-S-MRH|SKU-S-BRU|…`,
  `variation_attribute:pa_ukuran = S|S|M|M|L|L` (parallel to the variation
  SKUs). Without a `variation_attribute:*` column, variation combinations are
  built from the cartesian product of the attribute options.
- Hierarchical categories: `Wanita > Dress`; multiple categories are
  comma-separated.
- Images: a numeric value in the `image_url` / `image_id` column is treated as an
  existing `attachment_id`. A URL is sideloaded only when `allow_sideload` is on
  **and** its host is in `sideload_domain_whitelist`; otherwise it is skipped with
  a warning in the audit trail. Sideloading is https-only and rejects literal IP
  addresses and internal hosts. The source URL is remembered on the product as
  `_bcs_sync_image_url`, so an unchanged URL is never downloaded twice
  (gate1 decision #4, opened for V1.1).
- Limits: 500 rows/job hard limit, 100 rows default setting; 2 MB inline;
  10 MB attachment.

## 6. V1 limits (gate1 decisions — `docs/spec/gate1-decisions.md` + QA-1 rework)

1. Google Sheets: only **public** CSV export URLs (no OAuth), and **only from
   the `docs.google.com` host** (SSRF guard from the QA-1 rework — other hosts
   are rejected; redirects are disabled; private/loopback/link-local IPs are
   blocked. Extend via the `bcs_allowed_source_hosts` filter at your own risk).
2. Approval: the one-time token is only shown on the WP admin screen (no email
   — V1.1). Tokens are now 8 characters + atomic single-use consumption.
3. Write capability: `manage_woocommerce` (custom `bcs_execute` cap → V2).
4. Images: `attachment_id_only` — no URL sideload (V1.1). Alt text is still
   written to the referenced attachment.
5. Rollback of new products: **trash** (soft) is the only option. Rollback uses
   the product_id from the job's own audit (job-scoped).
6. `update_product` operations do NOT touch fields the row does not send:
   `status` is never changed (defaults.status is only for new products), and
   stock is only updated when the stock column is explicitly filled (QA-1
   rework, BLOCKER-1).
7. Rebuilding a plan on a `planned` job is allowed — the old plan is
   automatically `superseded` and old tokens/approvals become invalid.
8. Other non-goals: agentic checkout, headless storefront, multi-vendor,
   AI copywriting inside the plugin, etc. (spec §7).
9. MCP: only the custom `/wp-json/bcs-mcp/mcp` endpoint is supported (Gate 3).
   The plugin header declares `Requires Plugins: woocommerce, mcp-adapter`; if
   the mcp-adapter plugin is not active, the custom server is not created (admin
   notice, no fatal) and no BCS MCP route is active.

## 7. Security

- All ability inputs are strict: `additionalProperties: false`.
- Per-ability `permission_callback` + re-check inside the `execute_callback`
  (defense in depth), rate limit 60 calls/user/minute (transient).
- State machine transitions are enforced; invalid transitions → `WP_Error` +
  audit entry.
- All custom queries use `$wpdb->prepare`; all admin output is escaped.
- All product writes go through a single service (`BCS_Catalog_Writer`) with a
  meta whitelist (`_bcs_*`, Yoast/Rank Math SEO meta, `_wp_attachment_image_alt`).
- Append-only audit in `bcs_audit` (call + row levels) with `actor_via`
  (`mcp` / `wp_admin` / `wp_cli` / `system`).
- Deactivation removes nothing; uninstall only drops the `bcs_*` tables +
  `bcs_*` options/transients.

## 8. Code structure

```
bulk-catalog-studio.php          bootstrap + activation hooks + ability registration
uninstall.php                    drop tables + options on uninstall
readme.txt                       standard WP readme
README.md                        this document
includes/
  class-bcs-install.php          dbDelta 3 tables (spec §3)
  class-bcs-settings.php         defaults + settings helpers
  class-bcs-schemas.php          input/output JSON Schema for the 11 abilities
  class-bcs-abilities.php        ability registrar + execute callbacks
  class-bcs-csv-parser.php       CSV parser + normalizer
  class-bcs-duplicate-checker.php intra-batch + vs-catalog duplicates
  class-bcs-plan-builder.php     materialized plan + before snapshot
  class-bcs-approval-token.php   one-time token (hash, expiry, single-use)
  class-bcs-catalog-writer.php   THE single product-write service (WC CRUD)
  class-bcs-executor.php         chunked execution + dry_run + resume
  class-bcs-rollback-service.php per-plan trash/restore
  class-bcs-job-manager.php      state machine + job CRUD
  class-bcs-audit-logger.php     call/row audit
  class-bcs-mcp-server.php       custom MCP server /wp-json/bcs-mcp/mcp (Gate 3)
  class-bcs-sync-connection.php  sync connection CRUD + encrypted credentials
  class-bcs-sync-runner.php      guarded sync runs, approval, quarantine, conflicts
admin/
  class-bcs-admin.php            admin pages: jobs, diff preview, token, audit
  class-bcs-sync-admin.php       Sync connections, runs, quarantine, conflicts
tests/fixtures/
  sample-fashion-30.csv          30-row fixture (20 simple + 10 variable)
  sample-prompt-rows.json        example inline rows from Claude
```
