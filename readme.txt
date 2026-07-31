=== MFR Keyword Limiter ===
Contributors: mikezielonka
Tags: media file renamer, alt text, image seo, wordpress seo, accessibility
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart focus keyword distribution for Media File Renamer AI alt/title prompts.

== Description ==

MFR Keyword Limiter helps keep AI-generated image alt and title metadata natural and accessible while still ensuring the most important images can use the post focus keyword.

The plugin filters Media File Renamer's `mfrh_ai_prompt` prompt for image alt/title generation:

* Featured image and WPRM recipe image always keep the focus keyword prompt.
* Up to two additional post images may use the focus keyword.
* Remaining images are prompted to use natural, descriptive metadata without forcing the target keyword.
* Focus keywords are detected from Yoast SEO, Rank Math, and SEOPress.
* Works during new uploads and bulk AI metadata updates.

== Telemetry & privacy ==

The updater sends a small telemetry payload to the update server:

| Field | Sent on hourly update check | Sent on activation (registration) |
|---|---|---|
| `site_url` | Yes | Yes (also part of the HMAC signature) |
| `site_name` | Yes | Yes |
| `plugin_version` | Yes | Yes |
| `plugin_slug` | No (implied by URL) | Yes |
| `sdk_version` | Yes | Yes (also sent on challenge init) |
| `php_version` | Yes | No |
| `wp_version` | Yes | No |
| `environment_type` | Yes | No |
| `usage` | Optional, plugin-provided | No |

That is the complete list. No admin email (removed in um-updater v4.1.0 because the site key already identifies the install), no locale, and no user data. `usage` is absent unless this plugin explicitly opts in with a flat usage snapshot. Zero-config challenge registration sends only `site_url`, `plugin_slug`, `plugin_version`, and `sdk_version`; it does not send the site name.

Optional usage snapshots are for plugin feature flags/counters, not user data. The SDK keeps at most 20 keys, allows only short scalar values, caps the serialized object at 2KB, and drops invalid usage data instead of sending it.

Optional update and feature telemetry is off by default. If a site owner enables it in plugin settings, the plugin sends standard updater diagnostics plus the bounded feature data described beside the control. It never sends post content, user data, secrets, or free-form values. Updates keep working when sharing is off, and transport uses HTTPS.

== Installation ==

1. Upload the `mfr-keyword-limiter` folder to `/wp-content/plugins/` or install the release zip through Plugins > Add New > Upload Plugin.
2. Activate MFR Keyword Limiter.
3. Use Media File Renamer's AI metadata tools as usual.

== Frequently Asked Questions ==

= Does this replace Media File Renamer? =

No. It only adjusts Media File Renamer's AI prompt through the `mfrh_ai_prompt` filter.

= Which focus keyword plugins are supported? =

Yoast SEO, Rank Math, and SEOPress.

= Which images always keep the keyword? =

The post featured image, the WPRM recipe image, and the WPRM recipe featured image.

== Changelog ==

= 1.0.3 =
* Updated the bundled Update Machine SDK to v4.7.1 with safer registration recovery, bounded domain-lock self-healing, and final-plugin cleanup.

= 1.0.1 =
* Updated the bundled Update Machine SDK to v4.4.2.
* Added the canonical GitHub Release and Update Machine publishing workflow.

= 1.0.0 =
* Initial release.
