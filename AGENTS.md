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
- Traffic Generator (formerly Block Generator): generates synthetic experience
  events for selected blocks AND pageView events for selected posts/pages via
  `inc/block-generator.php::generate_block_analytics()` and
  `inc/block-generator.php::generate_post_events_range()`.
- Autopilot + Realtime: scheduled sitewide + block + post/page generation plus
  short bursts triggered by analytics screen pings.
- ClickHouse import format: `inc/namespace.php::import_clickhouse()` defines the
  expected event payload shape. Keep generated events aligned with this schema.

Data Pipeline (Cloud/Remote Sites)
-----------------------------------
Events do NOT go directly to ClickHouse. The ClickHouse user (`u_{app_id}`) only
has READ privileges. Instead:

1. Events are converted to Pinpoint BatchItem format in `import_clickhouse()`.
2. Posted to the log endpoint (`ALTIS_ACCELERATE_LOG_ENDPOINT`, typically
   `https://eu.accelerate.altis.cloud/log`).
3. The log endpoint inserts into ClickHouse on the server side.

Credentials come from:
- `altis_config` option in the site's `wp_options` table (format: `region:app_id:password`).
- Or derived from `ALTIS_CLICKHOUSE_USER` constant (format: `u_{app_id}`).

Direct ClickHouse writes (`import_clickhouse_direct()`) are only for local dev.

Batch sizing: max 10 visitors per request to the log endpoint. Larger payloads
get silently dropped.

Repo Layout
-----------
- `inc/namespace.php`: main plugin hooks, admin actions, AJAX handlers,
  import helpers, and ClickHouse/ES integration.
- `inc/block-generator.php`: traffic generator logic and data modeling (blocks
  and posts/pages).
- `inc/views/tools-page.php`: admin UI for Tools → Analytics Demo (Traffic
  Generator tab).
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

Traffic Generator Conventions
-----------------------------
- Timestamps should always fall within the intended day (no date rollbacks).
- Progress tracks events (block impressions + post pageViews).
- Standard blocks should behave as a single-variant experience.
- Posts/pages generate `pageView` events only (no conversions or variants).
- Volume is per content item (block or post) over a 31-day baseline; scale for
  custom day count.
- Selecting only posts (no blocks) or only blocks (no posts) is valid.
- Attribute modeling:
  - Geo: country → region → city.
  - Referrer/UTM: derived from referrer type.
  - Device/browser: correlated with device type.
  - Returning vs new: correlated with referrer mix.
  - Sitewide: top URLs + search term mix for dashboard panels.

Event Types & Attributes (Critical)
-------------------------------------
**Always audit against Accelerate source** (`altis-accelerate/`) before changing
attribute names or values. The source of truth is:
- `src/experiments.js` — front-end event tracking (ABTestBlock, PersonalizationBlock, etc.)
- `.setup/create-analytics-table.sql` — ClickHouse materialized columns
- `inc/blocks/namespace.php::get_views()` — dashboard query
- `inc/experiments/namespace.php` — p2bb/cron query
- `inc/global-blocks/namespace.php::register_test()` — test registration

**Event types per block interaction:**
Each block impression must emit TWO events:
1. `blockLoad` — block rendered on page
2. `blockView` — block scrolled into viewport (slightly after blockLoad)
3. `conversion` — if the visitor converts (with `goal` attribute)

**Block type attribute values:**
- A/B test → `type = 'abtest'` (NOT `ab-test`)
- Personalization → `type = 'personalization'`
- Broadcast → `type = 'broadcast'`
- Standard → `type = 'standard'`
Always set `type` for ALL block types, including standard.

**ClickHouse materialized columns** (from `attributes[...]`):
| Column            | Attribute key      | Notes                                    |
|-------------------|--------------------|------------------------------------------|
| `block_id`        | `blockId`          | The WP post ID of the block              |
| `blog_id`         | `blogId`           | `get_current_blog_id()`                  |
| `network_id`      | `networkId`        | `get_current_network_id()`               |
| `test_id`         | `eventTestId`      | `'block'` for A/B tests (NOT `'xb'`)     |
| `test_post_id`    | `eventPostId`      | The block's post ID                      |
| `test_variant_id` | `eventVariantId`   | Variant index (0, 1, 2...)               |
| `block_type`      | `type`             | See values above                         |
| `goal`            | `goal`             | e.g. `'click_any_link'` on conversions   |
| `audience`         | `audience`         | Variant ID for personalization blocks    |
| `page_session_id` | `pageSession`      | Set by `import_clickhouse()` automatically |
| `browser_session_id` | `session`       | Set by `import_clickhouse()` automatically |

**Attributes added automatically by `import_clickhouse()` during Pinpoint conversion:**
`session`, `pageSession`, `url`, `host`, `blog`, `network`, `blogId`, `networkId`, `date`.
These do NOT need to be set in the block generator.

**CRITICAL: The `url` attribute is required.** The Accelerate log endpoint silently
drops events that lack it (despite returning HTTP 202). This is added by
`import_clickhouse()` as `home_url('/')`. Without it, events are accepted but
never stored in ClickHouse.

**P2BB (Probability to Be Best) requirements:**
The p2bb calculation runs via the `altis_post_ab_test_cron` WP cron (hourly).
It queries ClickHouse filtering on:
- `test_id = 'block'` (from `eventTestId`)
- `test_variant_id != ''` (from `eventVariantId`)
- `test_post_id = {post_id}` (from `eventPostId`)
- `event_timestamp > test_start_time`
- `event_type IN ('blockView', 'conversion')`

If ANY of these attributes are wrong or missing, p2bb will show 0%.
The dashboard view counts (`get_views()`) use a DIFFERENT query that only
filters on `block_id` and `event_type` — so views can appear correct while
p2bb is broken. Always verify both paths.

**Personalization blocks:**
Use `audience` attribute (not `eventVariantId`) for variant grouping.
The dashboard groups by the `audience` column, not `test_variant_id`.

Autopilot + Realtime Conventions
--------------------------------
- Autopilot runs via cron and should not overlap with itself.
- Realtime bursts are capped to avoid chart spikes.
- Bursts should persist into the dataset (ClickHouse), not just the UI.

Performance Considerations
--------------------------
- Batch writes via log endpoint: max 10 visitors per request, 50 events per
  batch to `import_clickhouse()`. Larger payloads get silently dropped.
- Avoid excessive memory usage: watch event array sizes for high volume runs.
- Do NOT use `sleep()` between batches — causes PHP max execution timeout (30s).
- Single `wp_remote_post` timeout is 30s; keep request payloads reasonable.

Testing & Verification
----------------------
There are no automated tests. Use:
- `php -l inc/block-generator.php`
- `php -l inc/namespace.php`
- `php -l inc/views/tools-page.php`

Manual smoke checks:
- Tools → Analytics Demo → Traffic Generator creates events and conversions.
- Progress bar reaches 100% and refreshes to success state.
- Standard, A/B, personalization, and broadcast blocks all generate data.
- Selecting posts/pages generates pageView events (visible in dashboard top URLs).
- Selecting only posts (no blocks) works; selecting both works; estimates update.
- A/B blocks: verify p2bb shows non-zero after cron runs (may take up to 1 hour,
  or force with `wp cron event run altis_post_ab_test_cron`).
- Dashboard views AND p2bb should show consistent data — if views appear but
  p2bb is 0%, the event attributes are wrong (check `eventTestId`, `eventVariantId`,
  `eventPostId`).

Change Management
-----------------
- Keep README and UI descriptions updated when generator options change.
- Be careful with plugin name changes; ensure user-facing docs align.

Common Pitfalls (Lessons Learned)
----------------------------------
1. **Never write directly to ClickHouse on remote/cloud sites.** The CH user
   only has read access. Always route through the log endpoint.
2. **Always check the Accelerate JS front-end for attribute values.** The PHP
   backend and JS front-end sometimes use different naming than you'd expect
   (e.g. `eventTestId = 'block'` not `'xb'`, `type = 'abtest'` not `'ab-test'`).
3. **Dashboard views and p2bb use different queries.** Views come from
   `get_views()` (filters on `block_id`). P2BB comes from the experiment cron
   (filters on `test_id`, `test_variant_id`, `test_post_id`). Both must work.
4. **Log endpoint silently drops oversized payloads.** No error returned —
   events just disappear. Keep batches small (10 visitors / request).
5. **WP cron is lazy.** `altis_post_ab_test_cron` only fires on page visits.
   For immediate testing use `wp cron event run altis_post_ab_test_cron`.
6. **Old events in ClickHouse can't be retroactively fixed.** If attributes were
   wrong, you must regenerate data — the old rows persist with bad values.
7. **`import_clickhouse()` adds base attributes automatically.** Don't duplicate
   `blogId`, `networkId`, `session`, `pageSession`, `host`, `blog`, `network`
   in the block generator — they're merged during Pinpoint conversion.
8. **Each block needs both `blockLoad` AND `blockView` events.** The dashboard
   counts them separately. Missing `blockLoad` = incomplete metrics.
9. **Log endpoint silently drops events missing the `url` attribute.** Returns
   HTTP 202 but events never appear in ClickHouse. The `url` attribute is added
   by `import_clickhouse()` — always verify it's present when debugging missing data.
10. **Non-winner conversion rates use a penalty formula.** The formula
    `$base_rate * max(0.2, 1 - $lift * 0.5)` ensures losers have lower conversion
    than winners. With lift=15%, this produces ~24% dashboard improvement. Without
    the penalty, the gap is too small and random noise causes losers to appear as
    winners.

