#!/usr/bin/env bash
# =============================================================================
# bcs-sync-cron.sh — system cron wrapper for the BCS sync worker.
#
# The plugin deliberately registers no WP-Cron event (spec §1.2): the worker is
# driven by system cron so a run is never tied to site traffic.
#
# Install (as the webapp user, never root — WP-CLI refuses to run as root):
#
#   sudo -u runcloud crontab -e
#   * * * * * /home/runcloud/webapps/cf/ops/bcs-sync-cron.sh >> /home/runcloud/logs/bcs-sync-cron.log 2>&1
#
# flock keeps a slow run from stacking a second worker on top of itself. The
# runner also takes a MySQL GET_LOCK, so a stacked worker would exit harmlessly
# anyway; flock just avoids spawning the PHP process at all. -n means "skip this
# minute", never queue, so a backlog cannot build up.
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
BCS_LOCK_FILE="${BCS_LOCK_FILE:-/tmp/bcs-sync-run-due.lock}"

if [ "$(id -u)" = "0" ]; then
	echo "bcs-sync-cron: refusing to run as root; install this under the webapp user" >&2
	exit 1
fi

if [ ! -x "$BCS_PHP_BIN" ]; then
	echo "bcs-sync-cron: $BCS_PHP_BIN is not executable; set BCS_PHP_BIN to the site PHP" >&2
	exit 1
fi

# flock -n exits 1 when the lock is held. That is a normal skip, not a failure,
# and returning it to cron would mail an alert every single minute; exit 0
# instead and let the next minute try again.
set +e
flock -n "$BCS_LOCK_FILE" \
	"$BCS_PHP_BIN" "$BCS_WP_BIN" --path="$BCS_WP_ROOT" \
	--skip-plugins="$BCS_SKIP_PLUGINS" bcs sync run-due
rc=$?
set -e

if [ "$rc" -eq 1 ] && ! flock -n "$BCS_LOCK_FILE" true; then
	echo "bcs-sync-cron: previous run still holds the lock; skipping this minute"
	exit 0
fi
exit "$rc"
