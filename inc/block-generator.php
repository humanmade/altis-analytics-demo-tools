<?php
/**
 * Block Analytics Data Generator.
 *
 * Generate targeted fake analytics data for specific blocks with smooth traffic trends.
 */

namespace Altis\Analytics\Demo\BlockGenerator;

use Altis\Accelerate\Blocks;
use Altis\Accelerate\Utils;
use Exception;
use WP_Post;
use WP_Query;

/**
 * Get all published synced pattern blocks available for selection.
 *
 * @return WP_Post[] Array of wp_block posts.
 */
function get_available_blocks() : array {
	$query = new WP_Query( [
		'post_type' => 'wp_block',
		'post_status' => 'publish',
		'posts_per_page' => 100,
		'orderby' => 'title',
		'order' => 'ASC',
		'no_found_rows' => true,
	] );

	return $query->posts;
}

/**
 * Get block type (ab-test, personalization, or standard).
 *
 * @param WP_Post $block The block post.
 * @return string The block type.
 */
function get_block_type( WP_Post $block ) : string {
	$content = $block->post_content;

	if ( strpos( $content, 'wp:altis/ab-test' ) !== false ) {
		return 'ab-test';
	}

	if ( strpos( $content, 'wp:altis/personalization' ) !== false ) {
		return 'personalization';
	}

	return 'standard';
}

/**
 * Get variants from a block's content.
 *
 * @param WP_Post $block The block post.
 * @return array Array of variant information.
 */
function get_block_variants( WP_Post $block ) : array {
	$type = get_block_type( $block );
	$content = $block->post_content;
	$variants = [];

	if ( $type === 'ab-test' ) {
		// Count ab-test-variant blocks.
		preg_match_all( '/<!-- wp:altis\/ab-test-variant/', $content, $matches );
		$count = count( $matches[0] );

		for ( $i = 0; $i < $count; $i++ ) {
			$variants[] = [
				'id' => $i,
				'label' => sprintf( 'Variant %s', chr( 65 + $i ) ), // A, B, C, etc.
			];
		}
	} elseif ( $type === 'personalization' ) {
		// Count personalization-variant blocks.
		preg_match_all( '/<!-- wp:altis\/personalization-variant/', $content, $matches );
		$count = count( $matches[0] );

		// Try to extract audience IDs.
		preg_match_all( '/"audience":(\d+)/', $content, $audience_matches );

		for ( $i = 0; $i < $count; $i++ ) {
			$label = sprintf( 'Variant %d', $i + 1 );
			if ( ! empty( $audience_matches[1][ $i ] ) ) {
				$audience = get_post( $audience_matches[1][ $i ] );
				if ( $audience ) {
					$label = $audience->post_title;
				}
			}

			$variants[] = [
				'id' => $i,
				'label' => $label,
			];
		}
	}

	return $variants;
}

/**
 * Create smooth traffic distribution over days with growth trend and weekend dips.
 *
 * @param int $total_events Total events to distribute.
 * @param int $days Number of days.
 * @return int[] Events per day (smoothed).
 */
function smooth_daily_distribution( int $total_events, int $days ) : array {
	// Base distribution with slight growth.
	$base = [];
	for ( $day = 0; $day < $days; $day++ ) {
		// Growth factor: +10% over the period.
		$growth_factor = 1 + ( $day / $days ) * 0.1;

		// Weekend factor: 30% less traffic on Sat/Sun.
		$timestamp = strtotime( "-{$day} days" );
		$day_of_week = (int) date( 'N', $timestamp ); // 1=Mon, 7=Sun
		$weekend_factor = ( $day_of_week >= 6 ) ? 0.7 : 1.0;

		$base[ $day ] = ( $total_events / $days ) * $growth_factor * $weekend_factor;
	}

	// Apply 3-day moving average for smoothness.
	$smoothed = [];
	for ( $i = 0; $i < $days; $i++ ) {
		$window_start = max( 0, $i - 1 );
		$window_end = min( $days - 1, $i + 1 );
		$window = array_slice( $base, $window_start, $window_end - $window_start + 1 );
		$smoothed[ $i ] = array_sum( $window ) / count( $window );
	}

	return array_map( 'intval', $smoothed );
}

/**
 * Get hourly distribution weights for realistic traffic patterns.
 *
 * Peaks at 10am, 2pm, 7pm with lower traffic at night.
 *
 * @return int[] 24-hour weights.
 */
function get_hourly_weights() : array {
	// Hour 0-23 weights (reverse of original for proper weighting).
	return array_reverse( [ 1, 1, 1, 2, 2, 3, 3, 5, 8, 9, 6, 5, 10, 12, 7, 4, 5, 7, 10, 12, 14, 10, 8, 3 ] );
}

/**
 * Assign variant with performance targeting.
 *
 * @param array $variants Available variants.
 * @param string|null $winner_id Variant ID to favor (e.g., "0", "1", "2").
 * @param float $lift_percentage Performance lift as decimal (0.15 = 15%).
 * @return array Assigned variant with conversion rate.
 */
function assign_variant_with_target( array $variants, ?string $winner_id, float $lift_percentage ) : array {
	// Randomly select a variant (equal distribution).
	$variant = $variants[ array_rand( $variants ) ];

	// Base conversion rate.
	$base_rate = 0.05; // 5%

	// Adjust conversion rate if this is the winner.
	$is_winner = $winner_id !== null && (string) $variant['id'] === $winner_id;
	$conversion_rate = $is_winner ? $base_rate * ( 1 + $lift_percentage ) : $base_rate;

	return [
		'variant' => $variant,
		'conversion_rate' => $conversion_rate,
	];
}

/**
 * Generate smooth analytics events for selected blocks.
 *
 * @param int[] $block_ids Selected block IDs.
 * @param array $options Generation config with keys: days, traffic, winner_variant, lift.
 * @return void
 */
function generate_block_analytics( array $block_ids, array $options ) : void {
	$days = $options['days'] ?? 31;
	$traffic = $options['traffic'] ?? 'medium';
	$winner_variant = $options['winner_variant'] ?? null;
	$lift = ( $options['lift'] ?? 15 ) / 100; // Convert percentage to decimal.

	// Traffic volume presets.
	$volumes = [
		'low' => [ 'visitors' => 100, 'views' => 300, 'conversions' => 15 ],
		'medium' => [ 'visitors' => 500, 'views' => 1500, 'conversions' => 75 ],
		'high' => [ 'visitors' => 2000, 'views' => 6000, 'conversions' => 300 ],
	];

	$volume = $volumes[ $traffic ] ?? $volumes['medium'];

	// Track progress.
	$total_blocks = count( $block_ids );
	$progress = 0;
	\Altis\Analytics\Demo\update_option( 'total', 'block', $total_blocks * $volume['views'] );
	\Altis\Analytics\Demo\update_option( 'progress', 'block', 0 );

	// Time range.
	$max_timestamp = strtotime( 'today midnight' ) * 1000;
	$min_timestamp = $max_timestamp - ( DAY_IN_SECONDS * $days * 1000 );

	// Get smooth daily distribution.
	$daily_distribution = smooth_daily_distribution( $volume['views'], $days );
	$hourly_weights = get_hourly_weights();

	// Process each block.
	foreach ( $block_ids as $block_id ) {
		$block = get_post( $block_id );
		if ( ! $block ) {
			continue;
		}

		$block_type = get_block_type( $block );
		$variants = get_block_variants( $block );

		if ( empty( $variants ) ) {
			continue;
		}

		$client_id = $block->post_name;
		$events = [];

		// Generate events for each day.
		foreach ( $daily_distribution as $day_index => $events_for_day ) {
			$day_timestamp = $max_timestamp - ( $day_index * DAY_IN_SECONDS * 1000 );

			// Distribute events across hours.
			for ( $i = 0; $i < $events_for_day; $i++ ) {
				// Pick weighted hour.
				$hour = \Altis\Analytics\Demo\get_random_weighted_element( $hourly_weights );
				$event_timestamp = $day_timestamp - ( $hour * HOUR_IN_SECONDS * 1000 );

				// Generate unique visitor and session.
				$visitor_id = wp_generate_uuid4();
				$session_id = wp_generate_uuid4();

				// Assign variant with performance targeting.
				$assignment = assign_variant_with_target( $variants, $winner_variant, $lift );
				$variant = $assignment['variant'];
				$conversion_rate = $assignment['conversion_rate'];

				// Create experience impression event.
				$event = [
					'app_id' => defined( 'ALTIS_ANALYTICS_PINPOINT_ID' ) ? ALTIS_ANALYTICS_PINPOINT_ID : 'altis',
					'event_type' => 'experience_impression',
					'event_timestamp' => \Altis\Analytics\Demo\ch_format_date( $event_timestamp ),
					'attributes' => (object) [
						'clientId' => $client_id,
						'postId' => (string) $block_id,
						'variant' => (string) $variant['id'],
						'eventPostId' => (string) $block_id,
						'date' => gmdate( DATE_ISO8601, $event_timestamp / 1000 ),
					],
					'metrics' => (object) [],
					'endpoint_id' => $visitor_id,
					'endpoint_attributes' => (object) [],
					'endpoint_metrics' => (object) [],
					'endpoint_address' => '',
					'endpoint_optout' => 'NONE',
					'app_version' => '',
					'locale' => 'en-US',
					'make' => '',
					'model' => '',
					'model_version' => '',
					'platform' => 'web',
					'platform_version' => '',
					'country' => 'US',
					'city' => '',
					'postal_code' => '',
					'region' => '',
					'user_id' => '',
					'user_attributes' => (object) [],
					'session_id' => $session_id,
					'session_start' => \Altis\Analytics\Demo\ch_format_date( $event_timestamp ),
					'session_stop' => null,
					'session_duration' => null,
				];

				$events[] = $event;

				// Maybe add conversion event.
				if ( wp_rand( 1, 100 ) <= $conversion_rate * 100 ) {
					$conversion_event = $event;
					$conversion_event['event_type'] = 'conversion';
					$conversion_event['event_timestamp'] = \Altis\Analytics\Demo\ch_format_date( $event_timestamp + 5000 );
					$conversion_event['attributes'] = (object) [
						'clientId' => $client_id,
						'postId' => (string) $block_id,
						'variant' => (string) $variant['id'],
						'eventPostId' => (string) $block_id,
						'goal' => 'click_any_link',
						'date' => gmdate( DATE_ISO8601, ( $event_timestamp + 5000 ) / 1000 ),
					];
					$events[] = $conversion_event;
				}

				$progress++;
			}
		}

		// Write events to ClickHouse in batches.
		$batch_size = 400;
		$batches = array_chunk( $events, $batch_size );

		foreach ( $batches as $batch ) {
			$lines = array_map( function ( $event ) {
				return json_encode( $event );
			}, $batch );

			try {
				\Altis\Analytics\Demo\import_clickhouse( $lines );
			} catch ( Exception $e ) {
				\Altis\Analytics\Demo\update_option( 'failed', 'block', $e->getMessage() );
				\Altis\Analytics\Demo\update_option( 'running', 'block', false );
				return;
			}

			\Altis\Analytics\Demo\update_option( 'progress', 'block', $progress );

			// Sleep to avoid overload.
			sleep( 2 );
		}
	}

	// Mark as complete.
	\Altis\Analytics\Demo\update_option( 'success', 'block', true );
	\Altis\Analytics\Demo\update_option( 'running', 'block', false );
}
