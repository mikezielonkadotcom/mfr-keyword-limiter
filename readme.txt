=== MFR Keyword Limiter ===
Contributors: mikezielonka
Tags: media file renamer, alt text, image seo, wordpress seo, accessibility
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial release.
