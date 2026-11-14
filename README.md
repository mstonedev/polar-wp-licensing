# Polar WP Licensing

> **Unofficial.** This is an independent open-source project. It is not affiliated with, endorsed by or supported by Polar.

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

Sandbox mode works the same way, with sandbox-api.polar.sh in place of api.polar.sh. See [Live and sandbox](#live-and-sandbox-what-goes-where).

## Installation

Pick one of these three ways to get the library into your plugin.

**A. Composer, from GitHub.** Use this until the package is on Packagist. Add this to your plugin's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mstonedev/polar-wp-licensing" }
    ],
    "require": {
        "mstonedev/polar-wp-licensing": "dev-master"
    }
}
```

Then run `composer install`, and load Composer's autoloader in your main plugin file:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

Once releases are tagged (for example `v0.1.0`), require a version like `"^0.1"` instead of `dev-master`, so an update to this library can't change your plugin unexpectedly.

**B. Composer, from Packagist.** Once the package is published there, you only need:

```bash
composer require mstonedev/polar-wp-licensing
```

**C. No Composer.** Copy `polar-wp-licensing.php` and the `src/` folder into your plugin, for example as `vendor/polar-wp-licensing/`, and load it:

```php
require_once __DIR__ . '/vendor/polar-wp-licensing/polar-wp-licensing.php';
```

Whichever you choose, prefix the namespace before you release. See [Bundling in more than one plugin](#bundling-in-more-than-one-plugin).

## Setup in Polar

Polar has two separate environments, and you set your product up in both:

- **Sandbox** ([sandbox.polar.sh](https://sandbox.polar.sh)): fake payments and test license keys, for development.
- **Live** ([polar.sh](https://polar.sh)): real customers and real money.

In each one:

1. Create a product, for example "Acme Pro, 1 site, yearly".
2. Add a **License Keys** benefit to it. Set an activation limit to control how many sites one key can run on. Set an expiry if you sell yearly licenses that need renewal.
3. Copy two IDs:
   - the **organization ID**, from **Settings → General**
   - the **benefit ID** of the License Keys benefit

That gives you four IDs: a live pair and a sandbox pair. Each one is a long ID like `1f3c9a2e-7b4d-4c1a-9e8f-2a6b5c4d3e21`. Your organization's name or URL slug won't work. If you paste one by mistake, the plugin shows an error on the admin screen.

## Usage

Call `register()` once, in **your plugin's main file**: the PHP file with the `Plugin Name:` header, such as `acme-pro/acme-pro.php`. Put **both** pairs of Polar IDs in that call:

```php
require_once __DIR__ . '/vendor/autoload.php'; // or the loader file; see Installation

$licensing = \PolarWP\Licensing\Licensing::register( array(
    'plugin_file'         => __FILE__,

    // Live IDs: used on every customer's site.
    'organization_id'     => '1f3c9a2e-....',
    'benefit_id'          => '7d2b51c0-....',

    // Sandbox IDs: only used where sandbox mode is turned on (your dev site).
    'sandbox'             => array(
        'organization_id' => '9e04a7d1-....',
        'benefit_id'      => '3c88f2b6-....',
        // Optional: a test manifest; see Updates.
        'update_manifest_url' => 'https://updates.example.com/acme-pro-test.json',
    ),

    'update_manifest_url' => 'https://updates.example.com/acme-pro.json',
    'purchase_url'        => 'https://buy.polar.sh/...',
    'portal_url'          => 'https://polar.sh/your-org/portal',
) );

if ( $licensing->is_valid() ) {
    // Pro features.
}
```

Don't put IDs in the library's own files (`src/`). The library holds no IDs, so it works with any Polar account, and you can update it without losing your settings.

This adds **Settings → Acme Pro License** and a "License" link on the Plugins screen. To put the form on your own settings page instead, pass `'menu_parent' => false` and call `$licensing->render_license_form()`.

See `examples/acme-pro/acme-pro.php` for a complete example.

## Live and sandbox: what goes where

The short version: **your plugin's main file holds the IDs (in the `register()` call), `wp-config.php` only holds the sandbox switch, and customers only ever enter a license key.**

| What | Where it goes | Who sets it |
|---|---|---|
| Live organization ID and benefit ID | Your plugin's main file, in `register()`: `organization_id`, `benefit_id` | You, once |
| Sandbox organization ID and benefit ID | Your plugin's main file, in `register()`: the `sandbox` array | You, once |
| Sandbox switch: `define( 'POLARWP_SANDBOX', true );` | `wp-config.php` of **your own** dev site | You, only on your dev site |
| License key | **Settings → *Your Plugin* License** | Each customer |

### Why the IDs go in your code

The IDs identify **you as the seller**, so they're the same on every customer's site. They aren't secrets: the library only uses Polar's public license key endpoints, and the IDs alone give no access to your Polar account, your customers or your money. Shipping them inside your plugin is normal and safe.

Customers never need to touch `wp-config.php`, or know what an organization ID is.

### Why the sandbox switch goes in `wp-config.php`

`wp-config.php` belongs to one site, and it isn't part of your plugin, so a setting there can't ship to customers by accident. Add this to the `wp-config.php` of your local test site, above the "That's all, stop editing!" line:

```php
define( 'POLARWP_SANDBOX', true );
```

On that site the plugin then:

- talks to `sandbox-api.polar.sh` using your **sandbox** IDs, so test keys from sandbox.polar.sh work
- shows a yellow "Polar sandbox mode is on" notice in the admin, and a **SANDBOX** badge on the license screen
- stores the sandbox license **separately** from the live one, so a test key never ends up in your real license, and the other way round

Every other site uses your **live** IDs. To test against live Polar on your own site, remove the line, or set it to `false`.

Two more details:

- `POLARWP_SANDBOX` applies to every plugin on that site that uses this library. To switch just one plugin, or decide in code, use the filter instead:
  ```php
  add_filter( 'polarwp_sandbox', fn( $on, $slug ) => 'acme-pro' === $slug, 10, 2 );
  ```
- If sandbox mode is on but a plugin has no `sandbox` IDs, it stays on live Polar and shows an error in the admin. It never sends live IDs to the sandbox, or the other way round.

## Options

| Option | Default | |
|---|---|---|
| `plugin_file` | required | Main plugin file (`__FILE__`). |
| `organization_id` | required | Your **live** Polar organization ID. |
| `benefit_id` | none | Your **live** License Keys benefit ID. Rejects keys from your other products. Recommended. |
| `sandbox` | none | Your **sandbox** settings: `organization_id`, `benefit_id`, and optionally `update_manifest_url` (falls back to the live one). Only used when sandbox mode is on. |
| `slug` | plugin folder name | Used in option names and hooks. |
| `name`, `version` | plugin headers | |
| `update_manifest_url` | none | Enables updates. |
| `menu_parent` | `options-general.php` | `false` for no admin page. |
| `purchase_url`, `portal_url` | none | Links on the license screen. |
| `grace_period` | 14 days | How long a valid license keeps working while Polar can't be reached. |
| `check_interval` | 1 day | Minimum time between checks. |

## Hooks

- `polarwp_sandbox` (filter) `( bool $on, string $slug )`. Turn sandbox mode on or off per plugin. Defaults to the `POLARWP_SANDBOX` constant.
- `polarwp_license_is_valid` (filter) `( bool $valid, License $license )`. Use it to treat local or staging sites as licensed, for example. Don't use it on the site where you test licensing: it would make every license look valid, which hides exactly what you're trying to test.
- `polarwp_license_activated` (action) `( License $license )`
- `polarwp_license_deactivated` (action) `( License $license )`
- `polarwp_license_status_changed` (action) `( string $new, string $old, License $license )`

## Updates

1. Host a manifest like `examples/update-manifest.json`. `version` and `download_url` are required.
2. Host `examples/update-server/download.php` (or the equivalent in your stack) at the `download_url`. It checks the key with Polar before streaming the zip. Keep the zip outside the web root.
3. To release a new version, upload the new zip and bump `version` in the manifest.

**How fast sites see an update:** WordPress checks for updates about every 12 hours, and the library also caches your manifest for up to 12 hours. So a release can take up to about a day to show up everywhere. On a site you control, click **Dashboard → Updates → Check again** to look straight away.

Unlicensed sites still see that an update is available, but get "Automatic update is unavailable". That nudges customers to renew.

### Testing updates in sandbox mode

`download.php` checks keys against **live** Polar by default, so it refuses sandbox test keys. To test the whole update flow with a sandbox key:

1. Upload a second copy of the script, for example `download-test.php`, with `POLAR_SANDBOX` set to `true` (or set the `POLAR_SANDBOX=1` environment variable on a test server).
2. Host a test manifest whose `download_url` points at that copy.
3. Set it as `update_manifest_url` inside your plugin's `sandbox` settings.

**Live or sandbox is decided on your server, never by the download request.** Anyone can get a sandbox key for free with Polar's test card. If a request could say "check this key against the sandbox", anyone could download your plugin with one. So keep the live endpoint live-only.

## Before you release

- [ ] Your **live** `organization_id` and `benefit_id` are set. The admin shows an error if either is in the wrong format.
- [ ] You've tested the full flow in the sandbox: buy, activate, revoke, deactivate, update.
- [ ] You've also tested once against **live** Polar, with `POLARWP_SANDBOX` removed from your dev site. The switch only affects the site whose `wp-config.php` defines it, so it can't ship in your zip. But live is where real customers will be.
- [ ] Your live manifest and `download.php` are online, and `download.php` has `POLAR_SANDBOX = false`.
- [ ] The library's namespace is prefixed (see below).
- [ ] Your own docs tell **your customers** where to enter their key (**Settings → *Your Plugin* License**) and how to manage their sites in the Polar customer portal. They will never see this README.

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

## Running the tests

```bash
php tests/run.php
```

The scenario tests run the real `License`, `Updater` and live/sandbox switching code against stubbed WordPress functions and fake Polar responses, so they need nothing but PHP 8.1+. GitHub Actions runs them on every push, and also checks that the library still parses on PHP 7.4.

## Requirements

PHP 7.4+, WordPress 5.5+.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
