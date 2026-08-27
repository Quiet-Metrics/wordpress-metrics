# Changelog

All notable changes to the Quiet Metrics WordPress plugin are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [Unreleased]

Full pre-publication review pending (the core PHP SDK and the Laravel bridge already went through theirs); the embedded tracker copy must be resynchronised with its source as part of it.

## [0.1.1] - 2026-08-27

Artwork only. The plugin code is unchanged since 0.1.0.

### Changed
- Banner redrawn to the current brand, and its tagline now states what the plugin actually offers: server-side, first-party script, or both.
- Directory assets regenerated (`banner-1544x500`, `banner-772x250`, icons). The icon now uses the brand seal, the Q in reserve on a filled square, instead of the bare mark whose tail disappears at icon sizes.

### Fixed
- `readme.txt` announced its first release as `= 1.0.0 =` while the plugin header and the stable tag both said `0.1.0`.

## [0.1.0] - 2026-07-24

First tagged snapshot (private beta).

### Added
- Three collection modes: first-party script (local `qm.js` relayed through the site's own REST route), unblockable server-side tracking (decision on `template_redirect`, send on `shutdown`), or both.
- Settings page (single `quiet_metrics_settings` option): keys, service URL, mode, excluded roles and paths; `quiet_metrics_event()` and `quiet_metrics_client()` helpers; clean uninstall, multisite included.
- wordpress.org directory assets in `.wordpress-org/` (banners 1544x500 and 772x250, icons 256 and 128).

### Fixed
- Default service URL now `https://quietmetrics.dev` (embedded SDK default endpoint aligned too).
