# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
the `x.Yddd` versioning scheme (`x` = release class, `Y` = year last digit,
`ddd` = Julian day).

## [0.6123] — 2026-05-03

### Security

- **Replaced the self-rolled denylist SVG sanitizer with the allowlist-based
  [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitize)
  library** (bundled in `vendor/`). Addresses **GHSA-wmc2-4458-vm72** (HIGH):
  the prior denylist had multiple known bypass classes that could land an
  XSS payload via SVG upload. Allowlist semantics close that surface.
- Added `finfo_file()` server-side MIME validation before sanitization runs.
  Disguised payloads (HTML/JS named `.svg`) are rejected early.
- Sandboxed the Media Library SVG preview by replacing inline `<img src>`
  rendering with `<iframe sandbox="" referrerpolicy="no-referrer">`. Cookie
  and JS exfil from a pre-sanitization or edge-case payload is contained.

### Added

- Per-role upload allowlist with a new `upload_svg_files` capability granted
  to roles selected on the settings screen. Default: `administrator` only.
- Sanitization-failure log (`timu_svg_failure_log` option, capped at 50
  most-recent entries) recording filename, reason, user ID, and IP for
  incident response.
- `CHANGELOG.md`, `CODEOWNERS`, `.github/dependabot.yml`, `tests/`, and
  `languages/` scaffolding.

### Changed

- Settings page moved from `Tools > SVG Support` to `Settings > SVG Support`,
  matching the README claim. Plugin row "Settings" link updated.
- Bumped `Requires PHP` from `7.4` to `8.1` (matches `composer.json`).
- Replaced `file_get_contents()` / `file_put_contents()` calls with
  `WP_Filesystem` (direct transport pinned during the upload prefilter).
- Dropped `@gzdecode()` error suppression; explicit error-path branches.
- Strict comparison on the `enabled` option (`1 === (int) $value`).

### Fixed

- `register_activation_hook()` is now registered at file scope, not from
  inside `__construct()`. The prior registration timing meant defaults
  could silently fail to seed on first activation.
- `Stable tag` in `readme.txt` is aligned with the plugin header version.

## [1.6365] — 2026-12-30

- Documentation and profile alignment update (no functional changes).
