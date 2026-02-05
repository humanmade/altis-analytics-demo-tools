Accelerate Analytics Demo Tools
===============================

This plugin provides tools for importing historical demo data and generating
realistic demo analytics for Accelerate/Altis Analytics. It is designed for
screenshots, demos, and personalization previews.

The plugin should be considered to be in Beta state and is not intended for use in production anywhere.

## Installation & Usage

1. Install the plugin to `wp-content/plugins` or wherever your plugin directory is located.
2. Activate the plugin
3. In the admin area under "Tools" go to the "Analytics Demo" page
4. Use either the Historical Import tab or the Block Generator tab.

## Historical Import

The importer can be run multiple times, new session IDs will be created each time
and there is a 40% chance of a new endpoint ID being generated. This means when
looking for recurring visitors vs new you should see roughly a 60/40 split.

## Block Generator

The Block Generator creates targeted analytics data for specific blocks with
smooth trends and realistic attributes. This is ideal for A/B tests,
personalization demos, and polished screenshots.

Features:
- Block selection (A/B test, personalization, and standard blocks)
- Days of data (7–90)
- Traffic volume slider (up to 100k impressions per block over 31 days)
- Traffic shape presets (Steady, Growth, Daily-swing, Weekly-swing)
- Realism presets (Balanced, US-heavy, Referral-heavy)
- Variant winner lift (optional)
- Preview estimates for impressions, conversions, and runtime

Generated attributes include:
- country, region, city
- referrer + UTM source/medium/campaign
- device type + browser
- returning vs new visitor flag

## Notes

- Demo data is synthetic and intended for non-production use only.
- Higher volumes and many blocks can take longer to process.

The importer can be run multiple times, new session IDs will be created each time and there is a 40% chance of a new endpoint ID being generated.

This means when looking for recurring visitors vs new you should see roughly a 60/40 split.
