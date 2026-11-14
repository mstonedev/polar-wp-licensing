# Polar WP Licensing

Sell premium WordPress plugins with [Polar](https://polar.sh) license keys. You bundle this library in your plugin, and it handles:

- **Activation.** Customers paste their key into a license screen. Keys with an activation limit use up one seat per site.
- **Background checks.** A daily cron job re-checks the key, so licenses that are refunded, revoked or expired stop working without anyone visiting the license screen.
- **Grace period.** If Polar can't be reached, a valid license keeps working for 14 days (configurable). Customers aren't locked out during an outage.
- **Licensed updates.** WordPress's normal update screen gets updates from a JSON manifest you host. Only licensed sites get the download package.

No secret ships in your plugin: it only calls Polar's public customer portal license key endpoints, which are scoped by your organization ID.

## How it fits together

```
Customer buys on Polar ──► Polar emails a license key (License Keys benefit)
                                   │
WordPress site ── activate/validate ──► api.polar.sh/v1/customer-portal/license-keys/*
       │
       └─ update check ──► your update-manifest.json
       └─ download ──► your download.php ── validate ──► Polar ──► streams the zip
```

## Setup in Polar

1. Create a product, for example "Acme Pro, 1 site, yearly".
2. Add a **License Keys** benefit to it. Set an activation limit to control how many sites one key can run on. Set an expiry if you sell yearly licenses that need renewal.
3. Note your **organization ID** and the **benefit ID**.
4. Do all of this in the sandbox (sandbox.polar.sh) first.

## Usage

```php
require_once __DIR__ . '/vendor/polar-wp-licensing/polar-wp-licensing.php'; // or Composer autoload

$licensing = \PolarWP\Licensing\Licensing::register( array(
    'plugin_file'         => __FILE__,
    'organization_id'     => 'your-org-id',
    'benefit_id'          => 'your-benefit-id',      // optional, recommended
    'sandbox'             => true,                   // false in production
    'update_manifest_url' => 'https://updates.example.com/acme-pro.json',
    'purchase_url'        => 'https://buy.polar.sh/...',
    'portal_url'          => 'https://polar.sh/your-org/portal',
) );

if ( $licensing->is_valid() ) {
    // Pro features.
}
```

This adds **Settings → Acme Pro License** and a "License" link on the Plugins screen. To put the form on your own settings page instead, pass `'menu_parent' => false` and call `$licensing->render_license_form()`.

See `examples/acme-pro/acme-pro.php` for a complete example.

### Options

| Option | Default | |
|---|---|---|
| `plugin_file` | required | Main plugin file (`__FILE__`). |
| `organization_id` | required | Your Polar organization ID. |
| `slug` | plugin folder name | Used in option names and hooks. |
| `name`, `version` | plugin headers | |
| `benefit_id` | none | Rejects keys from your other products. |
| `sandbox` | `false` | Use `sandbox-api.polar.sh`. |
| `update_manifest_url` | none | Enables updates. |
| `menu_parent` | `options-general.php` | `false` for no admin page. |
| `purchase_url`, `portal_url` | none | Links on the license screen. |
| `grace_period` | 14 days | How long a valid license keeps working while Polar can't be reached. |
| `check_interval` | 1 day | Minimum time between checks. |

### Hooks

- `polarwp_license_is_valid` (filter) `( bool $valid, License $license )`. Use it to treat local or staging sites as licensed, for example.
- `polarwp_license_activated` (action) `( License $license )`
- `polarwp_license_deactivated` (action) `( License $license )`
- `polarwp_license_status_changed` (action) `( string $new, string $old, License $license )`

## Updates

1. Host a manifest like `examples/update-manifest.json`. `version` and `download_url` are required.
2. Host `examples/update-server/download.php` (or the equivalent in your stack) at the `download_url`. It checks the key with Polar before streaming the zip. Keep the zip outside the web root.
3. To release a new version, upload the new zip and bump `version` in the manifest. Sites see the update within 12 hours.

Unlicensed sites still see that an update is available, but get "Automatic update is unavailable". That nudges customers to renew.

## Bundling in more than one plugin

If two plugins on one site bundle different versions of this library, whichever loads first wins. Before you ship, prefix the namespace with [Strauss](https://github.com/BrianHenryIE/strauss) or PHP-Scoper (for example `Acme\Vendor\PolarWP\Licensing`).

## Behaviour reference

| Polar says | Stored status | `is_valid()` |
|---|---|---|
| `granted`, not expired | `valid` | true |
| `granted`, `expires_at` in the past | `expired` | false |
| `revoked` | `revoked` | false |
| `disabled` | `disabled` | false |
| 404 / 403 / 422 (unknown key, activation removed in portal) | `invalid` | false |
| Network error, 429, 5xx | unchanged | unchanged, until the grace period runs out |

## Requirements

PHP 7.4+, WordPress 5.5+. License: GPL-2.0-or-later.
