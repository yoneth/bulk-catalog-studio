#!/usr/bin/env bash
# =============================================================================
# bcs-sync-purge-cron.sh — daily retention for the BCS log tables.
#
# Same shape as bcs-sync-cron.sh, for the same reason: the plugin registers no
# WP-Cron event (spec §1.2), and an irreversible delete must never depend on
# whether the site happened to receive traffic.
#
# Install (as the webapp user, never root — WP-CLI refuses to run as root):
#
#   sudo -u runcloud crontab -e
#   17 3 * * * /home/runcloud/webapps/cf/ops/bcs-sync-purge-cron.sh >> /home/runcloud/bcs-logs/bcs-purge.log 2>&1
#
# Run it once with BCS_PURGE_DRY_RUN=1 before trusting it: the command then
# reports what it would remove and deletes nothing.
#
# flock keeps a long purge from overlapping the next day's run. -n means skip
# rather than queue, so a backlog cannot build up.
# =============================================================================
set -euo pipefail

# The default CLI PHP on this host is 7.4 while the site itself runs 8.1, and
# the novamira plugin uses PHP 8 named arguments, so a bare `wp` dies with a
# parse error before WordPress loads; under 8.1 novamira instead exhausts the
# CLI memory limit inside its own MCP ToolsHandler. BCS does not use novamira
# (it ships its own /wp-json/bcs-mcp/mcp server), so the worker runs on the site
# PHP with that one plugin skipped. Both were verified on the live host.
BCS_PHP_BIN="${BCS_PHP_BIN:-/usr/local/lsws/lsphp81/bin/php}"
BCS_WP_BIN="${BCS_WP_BIN:-/usr/local/bin/wp}"
BCS_WP_ROOT="${BCS_WP_ROOT:-/home/runcloud/webapps/cf}"
BCS_SKIP_PLUGINS="${BCS_SKIP_PLUGINS:-novamira}"
BCS_LOCK_FILE="${BCS_LOCK_FILE:-/tmp/bcs-sync-purge.lock}"

if [ "$(id -u)" = "0" ]; then
	echo "bcs-sync-purge-cron: refusing to run as root; install this under the webapp user" >&2
	exit 1
fi

if [ ! -x "$BCS_PHP_BIN" ]; then
	echo "bcs-sync-purge-cron: $BCS_PHP_BIN is not executable; set BCS_PHP_BIN to the site PHP" >&2
	exit 1
fi

# flock -n exits 1 when the lock is held. That is a normal skip, not a failure,
# and returning it to cron would mail an alert every single minute; exit 0
# instead and let the next minute try again.
set +e
BCS_PURGE_ARGS=""
if [ -n "${BCS_PURGE_DRY_RUN:-}" ]; then
	BCS_PURGE_ARGS="--dry-run"
fi

flock -n "$BCS_LOCK_FILE" \
	"$BCS_PHP_BIN" "$BCS_WP_BIN" --path="$BCS_WP_ROOT" \
	--skip-plugins="$BCS_SKIP_PLUGINS" bcs sync purge-logs $BCS_PURGE_ARGS
rc=$?
set -e

if [ "$rc" -eq 1 ] && ! flock -n "$BCS_LOCK_FILE" true; then
	echo "bcs-sync-purge-cron: a previous purge still holds the lock; skipping"
	exit 0
fi
exit "$rc"
