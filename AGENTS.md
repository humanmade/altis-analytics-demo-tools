Altis Analytics Demo Tools — AGENTS.md
======================================

Purpose
-------
This repo contains a WordPress plugin used to generate demo analytics data for
Accelerate/Altis Analytics. It is intentionally non-production and optimized
for realistic-looking demo charts, screenshots, and personalization demos.

Key Concepts
------------
- Historical import: replays demo data from `data/events.log` via
  `inc/namespace.php::import_data()` and sends it to Elasticsearch/ClickHouse.
- Block Generator: generates synthetic experience events for selected blocks via
  `inc/block-generator.php::generate_block_analytics()`.
- Autopilot + Realtime: scheduled sitewide + block generation plus short bursts
  triggered by analytics screen pings.
- ClickHouse import format: `inc/namespace.php::import_clickhouse()` defines the
  expected event payload shape. Keep generated events aligned with this schema.

Repo Layout
-----------
- `inc/namespace.php`: main plugin hooks, admin actions, AJAX handlers,
  import helpers, and ClickHouse/ES integration.
- `inc/block-generator.php`: block generator logic and data modeling.
- `inc/views/tools-page.php`: admin UI for Tools → Analytics Demo.
- `data/events.log`: source for historical import events.
- `plugin.php`: plugin header and bootstrap.

Development Guidelines
----------------------
- WordPress coding standards: use sanitization/escaping consistently
  (`sanitize_key`, `sanitize_text_field`, `esc_html`, etc.).
- Authorization: pair nonces with `current_user_can()` checks for any write or
  sensitive action.
- Keep demo data deterministic in shape but not in exact values; avoid obviously
  artificial patterns.
- Avoid writing to repo-tracked files from runtime code.
- Use the `get_option` / `update_option` helpers for status/progress tracking.

Block Generator Conventions
---------------------------
- Timestamps should always fall within the intended day (no date rollbacks).
- Progress should track impressions only, unless explicitly changed by product.
- Standard blocks should behave as a single-variant experience.
- Volume is per block over a 31-day baseline; scale for custom day count.
- Attribute modeling:
  - Geo: country → region → city.
  - Referrer/UTM: derived from referrer type.
  - Device/browser: correlated with device type.
  - Returning vs new: correlated with referrer mix.

Autopilot + Realtime Conventions
--------------------------------
- Autopilot runs via cron and should not overlap with itself.
- Realtime bursts are capped to avoid chart spikes.
- Bursts should persist into the dataset (ClickHouse), not just the UI.

Performance Considerations
--------------------------
- Batch writes to ClickHouse (default batch size = 400).
- Avoid excessive memory usage: watch event array sizes for high volume runs.
- Use `sleep()` only where needed to reduce backend load.

Testing & Verification
----------------------
There are no automated tests. Use:
- `php -l inc/block-generator.php`
- `php -l inc/namespace.php`
- `php -l inc/views/tools-page.php`

Manual smoke checks:
- Tools → Analytics Demo → Block Generator creates impressions and conversions.
- Progress bar reaches 100% and refreshes to success state.
- Standard, A/B, and personalization blocks all generate data.

Change Management
-----------------
- Keep README and UI descriptions updated when generator options change.
- Be careful with plugin name changes; ensure user-facing docs align.
