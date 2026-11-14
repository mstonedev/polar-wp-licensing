# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). Until 1.0, minor versions (0.x) may include breaking changes.

## [0.1.0] - 2026-11-14

First release.

### Added

- `Licensing::register()`: one call sets up the license, the license screen, daily background checks and updates for a plugin.
- License activation with Polar's public license key endpoints. No secrets ship in your plugin.
- Activation limits: one seat per site, labelled with the site's domain. Re-entering the same key doesn't use a second seat.
- Daily background check, so refunded, revoked, disabled or expired licenses stop working.
- Grace period (default 14 days): a valid license keeps working while Polar can't be reached.
- License screen under Settings, or embeddable in your own settings page with `render_license_form()`.
- Licensed updates from a JSON manifest you host. Unlicensed sites see the update but don't get the package.
- Live and sandbox IDs: switch a dev site to Polar's sandbox with `define( 'POLARWP_SANDBOX', true );`. Sandbox and live licenses are stored separately.
- Admin errors for IDs in the wrong format (for example an organization slug instead of its ID).
- Hooks: `polarwp_sandbox`, `polarwp_license_is_valid`, `polarwp_license_activated`, `polarwp_license_deactivated`, `polarwp_license_status_changed`.
- Examples: a plugin (`examples/acme-pro`), update manifests and a gated download endpoint (`examples/update-server`).
- Scenario tests (`php tests/run.php`) and GitHub Actions CI on PHP 7.4 and 8.1 to 8.4.

[0.1.0]: https://github.com/mstonedev/polar-wp-licensing/releases/tag/v0.1.0
