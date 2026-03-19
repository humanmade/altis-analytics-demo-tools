# CLAUDE.md

Architecture guide and development context for Accelerate Analytics Demo Tools.

## What This Plugin Does

Generates synthetic analytics events and sends them to the Accelerate log
endpoint (or direct ClickHouse on local environments). Used for demos,
screenshots, and personalization previews — never production.

Three modes:
1. **Historical Import** — replays `data/events.log` with re-stamped timestamps
2. **Traffic Generator** — creates targeted block/post analytics from the admin UI
3. **Autopilot** — continuous background generation on a WP Cron schedule

## File Structure

```
plugin.php                  → Plugin bootstrap, requires inc/namespace.php
inc/namespace.php           → Config, admin UI, Historical Import, delivery (import_clickhouse),
                              autopilot scheduling, realtime bursts
inc/block-generator.php     → Traffic Generator: block/post discovery, event factory,
                              realism profiles, geo/device/referrer selectors
data/events.log             → Pre-built historical event data for the importer
```

## Critical Event Attribute Rules

These directly affect whether data appears in Accelerate dashboards.

### Block Events

- Every block impression needs **both** `blockLoad` AND `blockView` events
- `type` attribute: `'abtest'` (not `'ab-test'`), `'personalization'`, `'broadcast'`, `'standard'`
- `eventTestId` = `'block'` (not `'xb'`) for A/B tests
- `eventVariantId` = variant index (`'0'`, `'1'`, etc.) for A/B tests
- `audience` = variant index for personalization blocks (not `eventVariantId`)
- `blockId` = the WP post ID of the `wp_block` post

### Delivery

- `url` attribute is **REQUIRED** — log endpoint silently drops events without it
- Max **10 visitors per HTTP request** — larger payloads are silently dropped
- Batch size: 50 events per call to `import_clickhouse()`, then grouped by
  visitor and chunked into requests of up to 10 visitors each
- No `sleep()` or `usleep()` between HTTP requests — round-trip provides natural throttling
- App ID comes from `get_option('altis_config')` (format: `region:app_id:password`)

### P2BB (Probability to Be Best)

The p2bb cron (`altis_post_ab_test_cron`) queries ClickHouse filtering on:
- `test_id = 'block'` (from `eventTestId`)
- `test_variant_id != ''` (from `eventVariantId`)
- `test_post_id = {post_id}` (from `eventPostId`)
- `event_type IN ('blockView', 'conversion')`

If any of these attributes are wrong, p2bb shows 0% even though dashboard view
counts may look correct (they use a different query filtering on `block_id`).

### Conversion Rate Modelling

Winner variant gets: `base_rate * (1 + lift)`
Non-winners get: `base_rate * max(0.2, 1 - lift * 0.5)`

## Performance Constraints

- **Timestamp arithmetic must use explicit `(int)` casts**: PHP 8.1+ emits
  deprecation warnings for implicit float-to-int conversion. All `/ 1000`
  divisions on millisecond timestamps must be wrapped in `(int)`.
- **10 visitors per HTTP request max**: the log endpoint silently drops larger
  payloads. Events are grouped by `endpoint_id` and chunked via `array_chunk()`.
- **No artificial sleep between requests**: the previous `usleep(100000)` per
  request and `sleep(2)` per batch have been removed. HTTP round-trip latency
  (~250ms) provides natural throttling.
- **Historical Import uses 100ms pause**: `usleep(100000)` between file batches,
  replacing the previous 5-second `sleep()`.

## Namespace

```
Altis\Analytics\Demo\              → inc/namespace.php
Altis\Analytics\Demo\BlockGenerator\ → inc/block-generator.php
```

## Relationship to Accelerate Flux

Accelerate Flux (`accelerate-flux`) is the zero-config successor to this plugin.
Flux was built by adapting code from this plugin and discovered several
performance issues in the process (batched delivery, float-to-int casts,
excessive sleep delays). Those fixes have been backported here.

| Flux file             | Source here            |
|---|---|
| `data-generator.php`  | `inc/block-generator.php` |
| `delivery.php`         | `inc/namespace.php`       |
| `content-tracker.php`  | `inc/block-generator.php` |
