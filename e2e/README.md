# CrawlerToll e2e rig

A **real** WordPress (real PHP + SQLite, no MySQL) that runs the working-tree
plugin with Pro unlocked and asserts behavior over HTTP. This is the gold-standard
check the mock `test-harness.php` can't do — it sees WP's actual request
lifecycle, header emission, and database.

## Run it

```bash
e2e/run.sh           # build a fresh WP, configure, assert, tear down
e2e/run.sh --keep    # leave the server running (prints the URL; login admin/password)
```

Exit code `0` = all assertions passed. WordPress core and the SQLite drop-in are
cached under `~/.cache/crawlertoll-e2e`, so only the first run downloads them.

## How it works

| File           | Role                                                                    |
|----------------|-------------------------------------------------------------------------|
| `run.sh`       | Orchestrates: cache → fresh WP tree → copy plugin → install → assert.    |
| `install.php`  | Headless `wp_install()` + plugin activation (wp-cli fatals on PHP 8.5).  |
| `configure.php`| Writes the `crawlertoll_settings` fixture (enforcement on, path pricing).|

Pro is unlocked via `define('CRAWLERTOLL_PRO_DEV', true)` in the generated
`wp-config.php` — no Freemius license needed. A `router.php` makes `php -S` route
non-existent paths (e.g. `/premium/report/`) into WordPress so `parse_request`
still fires.

## Notes

- This folder is **excluded from the wp.org build** (dev-only).
- Re-run after any plugin code change — the plugin is **copied**, not symlinked.
- Extend `configure.php` (fixtures) and the assertions in `run.sh` as new Pro
  features land.

## Upgrade + uninstall (2026-09-16)

- `run.sh` now also (a) simulates an auto-update on the running rig (marker gone,
  Pro table dropped, stale rewrite rules) and asserts the upgrader repairs it on
  the first request with settings untouched (`test-upgrade.php`); (b) posts a
  client-error report and checks it lands in the error log; (c) runs WP's own
  `uninstall_plugin()` last and asserts nothing is left (`test-uninstall.php`,
  skipped with `--keep`).
- `run-upgrade.sh` is the REAL upgrade: installs the approved wp.org 0.1.1 tree vendored at `e2e/fixtures/crawlertoll-0.1.1`
  (override with `CT_UPGRADE_FROM`), configures it, swaps in the working tree
  without activation, and asserts the site works and 0.1.1 settings survive.
