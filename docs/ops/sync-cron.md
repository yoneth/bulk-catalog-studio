# BCS Sync — system cron installation

The plugin registers **no WP-Cron event**. Scheduling is a system cron job, so a
sync run never depends on site traffic (spec `docs/spec/bcs-sync.md` §1.2, Gate-1
decision 12).

## Wrapper

`ops/bcs-sync-cron.sh` wraps `wp bcs sync run-due`:

- refuses to run as root (WP-CLI rejects root, and RunCloud webapps are owned by
  `runcloud`);
- takes an `flock` so a slow run cannot stack a second worker on top of itself;
- treats lock contention as a skip, not a failure, so cron does not mail an
  alert every minute while a long run is in progress;
- runs WP-CLI on the site's own PHP 8.1 with the `novamira` plugin skipped. The
  host's default CLI PHP is 7.4 while the site runs 8.1, and novamira uses PHP 8
  named arguments, so a bare `wp` dies with a parse error before WordPress loads;
  under 8.1 novamira instead exhausts the CLI memory limit inside its own MCP
  handler. BCS does not use novamira — it ships its own `/wp-json/bcs-mcp/mcp`
  server — so skipping it is consistent with the project's constraints.

## Install

Deploy the file to the webroot, then register it for the webapp user:

```bash
# on the VPS, as runcloud
install -m 0755 ops/bcs-sync-cron.sh /home/runcloud/webapps/cf/ops/bcs-sync-cron.sh
crontab -e
```

Add one line:

```cron
* * * * * /home/runcloud/webapps/cf/ops/bcs-sync-cron.sh >> /home/runcloud/bcs-logs/bcs-sync-cron.log 2>&1
```

The log goes to `/home/runcloud/bcs-logs/`, not `/home/runcloud/logs/`: the latter
is owned by root and the webapp user cannot write to it, so cron output would be
lost and every tick would fail on the redirect. Create it once with
`mkdir -p /home/runcloud/bcs-logs`.

Per-minute matches the Gate-1 decision. Each connection still runs only when its
own `interval_minutes` says it is due — `run-due` skips everything else, so the
minute tick is cheap.

## Verify

```bash
# the worker runs and reports how many connections were due
sudo -u runcloud /home/runcloud/webapps/cf/ops/bcs-sync-cron.sh
# expected on a converged catalogue: "Success: Processed N due connection(s)."
# with each connection reporting done

# cron is registered for the right user
sudo -u runcloud crontab -l | grep bcs-sync-cron

# runs are actually being recorded
sudo -u runcloud wp --path=/home/runcloud/webapps/cf db query \
  "SELECT id, connection_id, run_trigger, state, started_at FROM wp_bcs_sync_runs ORDER BY id DESC LIMIT 5;"
```

Rows written by cron carry `run_trigger='cron'`. The DB column is `run_trigger`,
not `trigger` — the reserved word made `dbDelta()` skip the table silently
(commit `ffcf3dc`); the public API key stays `trigger`.

## Schema upgrades under cron

`bcs_maybe_upgrade()` is hooked to both `admin_init` and `cli_init`. The `cli_init`
hook is what keeps cron correct: a cron run never touches `admin_init`, so
without it a schema change would stay unapplied until someone opened wp-admin.
