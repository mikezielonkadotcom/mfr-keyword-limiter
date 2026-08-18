# MFR Keyword Limiter

Smart focus-keyword distribution for Media File Renamer AI alt/title prompts — keeps AI-generated image metadata natural while priority images always get the focus keyword.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Media File Renamer (AI features)

## Behavior

Filters MFR's `mfrh_ai_prompt`:

- Featured image + WPRM recipe image always keep the focus-keyword prompt
- Up to two additional post images may use the focus keyword
- Remaining images get natural, descriptive metadata prompts
- Focus keyword detected from Yoast SEO, Rank Math, or SEOPress
- Applies to new uploads and bulk AI metadata updates

## Updates

Ships with the Update Machine SDK — updates delivered from `updatemachine.com/mfr-keyword-limiter/update.json`. Telemetry disclosure lives in [readme.txt](readme.txt).

## Development

- Main plugin file: `mfr-keyword-limiter.php`
- Changelog: `readme.txt`
