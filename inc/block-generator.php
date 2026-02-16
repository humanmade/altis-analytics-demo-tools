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
	$post_types = [ 'wp_block' ];
	if ( post_type_exists( 'broadcast' ) ) {
		$post_types[] = 'broadcast';
	}

	$query = new WP_Query( [
		'post_type' => $post_types,
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

	if ( $block->post_type === 'broadcast' || strpos( $content, 'wp:altis/broadcast' ) !== false ) {
		return 'broadcast';
	}

	$meta_type = get_post_meta( $block->ID, '_xb_type', true );
	if ( ! empty( $meta_type ) ) {
		if ( $meta_type === 'abtest' ) {
			return 'ab-test';
		}
		if ( $meta_type === 'personalization' ) {
			return 'personalization';
		}
	}

	if ( get_post_meta( $block->ID, '_xb_abtest', true ) ) {
		return 'ab-test';
	}

	if ( get_post_meta( $block->ID, '_xb_personalization', true ) ) {
		return 'personalization';
	}

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
		$blocks = parse_blocks( $content );
		$count = 0;
		foreach ( $blocks as $parsed ) {
			if ( ( $parsed['blockName'] ?? '' ) === 'altis/variant' ) {
				$count++;
			}
		}
		$count = max( 1, $count );

		for ( $i = 0; $i < $count; $i++ ) {
			$variants[] = [
				'id' => $i,
				'label' => sprintf( 'Variant %s', chr( 65 + $i ) ), // A, B, C, etc.
			];
		}
	} elseif ( $type === 'personalization' ) {
		$blocks = parse_blocks( $content );
		$count = 0;
		foreach ( $blocks as $parsed ) {
			if ( ( $parsed['blockName'] ?? '' ) === 'altis/variant' ) {
				$count++;
			}
		}
		$count = max( 1, $count );

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

			if ( strpos( $content, '"fallback":true' ) !== false && $i === ( $count - 1 ) ) {
				$label = 'Fallback';
			}

			$variants[] = [
				'id' => $i,
				'label' => $label,
			];
		}
	}

	if ( $type === 'standard' ) {
		$variants[] = [
			'id' => 0,
			'label' => 'Standard',
		];
	}

	if ( $type === 'broadcast' ) {
		$variants[] = [
			'id' => 0,
			'label' => 'Broadcast',
		];
	}

	return $variants;
}

/**
 * Get available traffic shape presets.
 *
 * @return array Shape keys mapped to labels.
 */
function get_traffic_shapes() : array {
	return [
		'steady' => 'Steady',
		'growth' => 'Growth',
		'daily-swing' => 'Daily-swing',
		'weekly-swing' => 'Weekly-swing',
	];
}

/**
 * Get available realism presets.
 *
 * @return array Preset keys mapped to labels.
 */
function get_realism_presets() : array {
	return [
		'balanced' => 'Balanced',
		'us-heavy' => 'US-heavy',
		'referral-heavy' => 'Referral-heavy',
	];
}

/**
 * Create traffic distribution over days with optional shape modifiers.
 *
 * @param int $total_events Total events to distribute.
 * @param int $days Number of days.
 * @param string $shape Traffic shape.
 * @return int[] Events per day (smoothed).
 */
function smooth_daily_distribution( int $total_events, int $days, string $shape = 'growth' ) : array {
	// Base distribution with optional growth/weekend swings.
	$base = [];
	for ( $day = 0; $day < $days; $day++ ) {
		$growth_factor = 1;
		if ( $shape === 'growth' ) {
			// Growth factor: +10% over the period.
			$growth_factor = 1 + ( $day / $days ) * 0.1;
		}

		// Weekend factor: 30% less traffic on Sat/Sun.
		$timestamp = strtotime( "-{$day} days" );
		$day_of_week = (int) date( 'N', $timestamp ); // 1=Mon, 7=Sun
		$weekend_factor = 1.0;
		if ( $shape === 'weekly-swing' ) {
			$weekend_factor = ( $day_of_week >= 6 ) ? 0.7 : 1.0;
		}

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

	$smoothed = array_map( 'intval', $smoothed );
	return adjust_distribution_sum( $smoothed, $total_events );
}

/**
 * Get hourly distribution weights for realistic traffic patterns.
 *
 * Peaks at 10am, 2pm, 7pm with lower traffic at night.
 *
 * @return int[] 24-hour weights.
 */
function get_hourly_weights( string $shape = 'steady' ) : array {
	if ( $shape === 'daily-swing' ) {
		return [ 1, 1, 1, 1, 2, 2, 3, 5, 8, 10, 11, 8, 7, 10, 12, 9, 6, 5, 6, 9, 12, 10, 6, 3 ];
	}

	// Hour 0-23 weights.
	return [ 1, 1, 1, 2, 2, 3, 3, 5, 8, 9, 6, 5, 10, 12, 7, 4, 5, 7, 10, 12, 14, 10, 8, 3 ];
}

/**
 * Ensure the daily distribution sums to the target total.
 *
 * @param int[] $distribution Daily distribution.
 * @param int $total_events Total events to match.
 * @return int[] Adjusted distribution.
 */
function adjust_distribution_sum( array $distribution, int $total_events ) : array {
	$current_total = array_sum( $distribution );
	$delta = $total_events - $current_total;

	if ( $delta === 0 ) {
		return $distribution;
	}

	$days = count( $distribution );
	if ( $delta > 0 ) {
		for ( $i = 0; $i < $delta; $i++ ) {
			$index = $i % $days;
			$distribution[ $index ]++;
		}
		return $distribution;
	}

	$delta = abs( $delta );
	for ( $i = 0; $i < $delta; $i++ ) {
		$index = $i % $days;
		if ( $distribution[ $index ] > 0 ) {
			$distribution[ $index ]--;
		}
	}
	return $distribution;
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
 * Get the realism profile for attribute distribution.
 *
 * @param string $preset Preset key.
 * @return array
 */
function get_realism_profile( string $preset ) : array {
	$profiles = [
		'balanced' => [
			'geo' => [
				'US' => [
					'weight' => 45,
					'locale' => 'en-US',
					'regions' => [
						'CA' => [ 'weight' => 25, 'cities' => [ 'San Francisco' => 20, 'Los Angeles' => 50, 'San Diego' => 15, 'Sacramento' => 15 ] ],
						'NY' => [ 'weight' => 20, 'cities' => [ 'New York' => 70, 'Buffalo' => 15, 'Rochester' => 15 ] ],
						'TX' => [ 'weight' => 20, 'cities' => [ 'Austin' => 35, 'Dallas' => 40, 'Houston' => 25 ] ],
						'WA' => [ 'weight' => 15, 'cities' => [ 'Seattle' => 70, 'Spokane' => 30 ] ],
					],
				],
				'GB' => [
					'weight' => 10,
					'locale' => 'en-GB',
					'regions' => [
						'ENG' => [ 'weight' => 80, 'cities' => [ 'London' => 70, 'Manchester' => 15, 'Birmingham' => 15 ] ],
						'SCT' => [ 'weight' => 20, 'cities' => [ 'Edinburgh' => 60, 'Glasgow' => 40 ] ],
					],
				],
				'DE' => [
					'weight' => 8,
					'locale' => 'de-DE',
					'regions' => [
						'BE' => [ 'weight' => 40, 'cities' => [ 'Berlin' => 70, 'Potsdam' => 30 ] ],
						'BY' => [ 'weight' => 30, 'cities' => [ 'Munich' => 70, 'Nuremberg' => 30 ] ],
						'HH' => [ 'weight' => 30, 'cities' => [ 'Hamburg' => 70, 'Lubeck' => 30 ] ],
					],
				],
				'FR' => [
					'weight' => 7,
					'locale' => 'fr-FR',
					'regions' => [
						'IDF' => [ 'weight' => 70, 'cities' => [ 'Paris' => 80, 'Versailles' => 20 ] ],
						'ARA' => [ 'weight' => 30, 'cities' => [ 'Lyon' => 70, 'Grenoble' => 30 ] ],
					],
				],
				'CA' => [
					'weight' => 6,
					'locale' => 'en-CA',
					'regions' => [
						'ON' => [ 'weight' => 60, 'cities' => [ 'Toronto' => 70, 'Ottawa' => 30 ] ],
						'BC' => [ 'weight' => 40, 'cities' => [ 'Vancouver' => 70, 'Victoria' => 30 ] ],
					],
				],
				'AU' => [
					'weight' => 4,
					'locale' => 'en-AU',
					'regions' => [
						'NSW' => [ 'weight' => 60, 'cities' => [ 'Sydney' => 80, 'Newcastle' => 20 ] ],
						'VIC' => [ 'weight' => 40, 'cities' => [ 'Melbourne' => 80, 'Geelong' => 20 ] ],
					],
				],
			],
			'referrers' => [
				'direct' => 30,
				'search' => 35,
				'social' => 20,
				'email' => 8,
				'referral' => 7,
			],
			'devices' => [
				'desktop' => 55,
				'mobile' => 40,
				'tablet' => 5,
			],
			'returning_rate' => 0.35,
		],
		'us-heavy' => [
			'geo' => [
				'US' => [
					'weight' => 70,
					'locale' => 'en-US',
					'regions' => [
						'CA' => [ 'weight' => 30, 'cities' => [ 'San Francisco' => 20, 'Los Angeles' => 55, 'San Diego' => 15, 'Sacramento' => 10 ] ],
						'NY' => [ 'weight' => 20, 'cities' => [ 'New York' => 70, 'Buffalo' => 15, 'Rochester' => 15 ] ],
						'TX' => [ 'weight' => 20, 'cities' => [ 'Austin' => 35, 'Dallas' => 40, 'Houston' => 25 ] ],
						'FL' => [ 'weight' => 15, 'cities' => [ 'Miami' => 60, 'Orlando' => 40 ] ],
						'IL' => [ 'weight' => 15, 'cities' => [ 'Chicago' => 80, 'Naperville' => 20 ] ],
					],
				],
				'GB' => [ 'weight' => 8, 'locale' => 'en-GB', 'regions' => [ 'ENG' => [ 'weight' => 100, 'cities' => [ 'London' => 70, 'Manchester' => 20, 'Birmingham' => 10 ] ] ] ],
				'CA' => [ 'weight' => 7, 'locale' => 'en-CA', 'regions' => [ 'ON' => [ 'weight' => 70, 'cities' => [ 'Toronto' => 70, 'Ottawa' => 30 ] ], 'BC' => [ 'weight' => 30, 'cities' => [ 'Vancouver' => 70, 'Victoria' => 30 ] ] ] ],
				'DE' => [ 'weight' => 5, 'locale' => 'de-DE', 'regions' => [ 'BE' => [ 'weight' => 100, 'cities' => [ 'Berlin' => 70, 'Potsdam' => 30 ] ] ] ],
				'FR' => [ 'weight' => 5, 'locale' => 'fr-FR', 'regions' => [ 'IDF' => [ 'weight' => 100, 'cities' => [ 'Paris' => 80, 'Versailles' => 20 ] ] ] ],
				'AU' => [ 'weight' => 5, 'locale' => 'en-AU', 'regions' => [ 'NSW' => [ 'weight' => 70, 'cities' => [ 'Sydney' => 80, 'Newcastle' => 20 ] ], 'VIC' => [ 'weight' => 30, 'cities' => [ 'Melbourne' => 80, 'Geelong' => 20 ] ] ] ],
			],
			'referrers' => [
				'direct' => 32,
				'search' => 36,
				'social' => 18,
				'email' => 7,
				'referral' => 7,
			],
			'devices' => [
				'desktop' => 58,
				'mobile' => 38,
				'tablet' => 4,
			],
			'returning_rate' => 0.4,
		],
		'referral-heavy' => [
			'geo' => [
				'US' => [
					'weight' => 50,
					'locale' => 'en-US',
					'regions' => [
						'CA' => [ 'weight' => 25, 'cities' => [ 'San Francisco' => 20, 'Los Angeles' => 50, 'San Diego' => 15, 'Sacramento' => 15 ] ],
						'NY' => [ 'weight' => 20, 'cities' => [ 'New York' => 70, 'Buffalo' => 15, 'Rochester' => 15 ] ],
						'TX' => [ 'weight' => 20, 'cities' => [ 'Austin' => 35, 'Dallas' => 40, 'Houston' => 25 ] ],
						'WA' => [ 'weight' => 15, 'cities' => [ 'Seattle' => 70, 'Spokane' => 30 ] ],
					],
				],
				'GB' => [ 'weight' => 10, 'locale' => 'en-GB', 'regions' => [ 'ENG' => [ 'weight' => 100, 'cities' => [ 'London' => 70, 'Manchester' => 20, 'Birmingham' => 10 ] ] ] ],
				'DE' => [ 'weight' => 8, 'locale' => 'de-DE', 'regions' => [ 'BE' => [ 'weight' => 100, 'cities' => [ 'Berlin' => 70, 'Potsdam' => 30 ] ] ] ],
				'FR' => [ 'weight' => 7, 'locale' => 'fr-FR', 'regions' => [ 'IDF' => [ 'weight' => 100, 'cities' => [ 'Paris' => 80, 'Versailles' => 20 ] ] ] ],
				'CA' => [ 'weight' => 5, 'locale' => 'en-CA', 'regions' => [ 'ON' => [ 'weight' => 100, 'cities' => [ 'Toronto' => 70, 'Ottawa' => 30 ] ] ] ],
				'AU' => [ 'weight' => 5, 'locale' => 'en-AU', 'regions' => [ 'NSW' => [ 'weight' => 100, 'cities' => [ 'Sydney' => 80, 'Newcastle' => 20 ] ] ] ],
			],
			'referrers' => [
				'direct' => 20,
				'search' => 20,
				'social' => 30,
				'email' => 15,
				'referral' => 15,
			],
			'devices' => [
				'desktop' => 45,
				'mobile' => 48,
				'tablet' => 7,
			],
			'returning_rate' => 0.25,
		],
	];

	return $profiles[ $preset ] ?? $profiles['balanced'];
}

/**
 * Select a weighted key from a weights array.
 *
 * @param array $weights Weighted array.
 * @return string|int
 */
function select_weighted_key( array $weights ) {
	return \Altis\Analytics\Demo\get_random_weighted_element( $weights );
}

/**
 * Select geo data from a preset profile.
 *
 * @param array $profile Profile data.
 * @return array
 */
function select_geo_data( array $profile ) : array {
	$countries = [];
	foreach ( $profile['geo'] as $country_code => $country_data ) {
		$countries[ $country_code ] = $country_data['weight'];
	}
	$country = select_weighted_key( $countries );
	$country_data = $profile['geo'][ $country ];

	$regions = [];
	foreach ( $country_data['regions'] as $region_code => $region_data ) {
		$regions[ $region_code ] = $region_data['weight'];
	}
	$region = select_weighted_key( $regions );
	$region_data = $country_data['regions'][ $region ];

	$city = select_weighted_key( $region_data['cities'] );

	return [
		'country' => $country,
		'region' => $region,
		'city' => $city,
		'locale' => $country_data['locale'] ?? 'en-US',
	];
}

/**
 * Select referrer and UTM data.
 *
 * @param array $profile Profile data.
 * @return array
 */
function select_referrer_data( array $profile ) : array {
	$type = select_weighted_key( $profile['referrers'] );
	$campaigns = [ 'spring-launch', 'q2-promo', 'q3-update', 'winter-release', 'webinar-series' ];

	if ( $type === 'direct' ) {
		return [
			'referer' => '',
			'utm_source' => '',
			'utm_medium' => '',
			'utm_campaign' => '',
		];
	}

	$sources = [
		'search' => [
			'google' => 'https://www.google.com/',
			'bing' => 'https://www.bing.com/',
			'duckduckgo' => 'https://duckduckgo.com/',
		],
		'social' => [
			'linkedin' => 'https://www.linkedin.com/',
			'twitter' => 'https://twitter.com/',
			'facebook' => 'https://www.facebook.com/',
			'reddit' => 'https://www.reddit.com/',
		],
		'email' => [
			'newsletter' => 'https://mail.example.com/',
			'customerio' => 'https://customer.io/',
		],
		'referral' => [
			'partner' => 'https://partner.example.com/',
			'community' => 'https://community.example.com/',
		],
	];

	$source = select_weighted_key( array_fill_keys( array_keys( $sources[ $type ] ), 1 ) );
	$referer = $sources[ $type ][ $source ] ?? '';

	$utm_medium_map = [
		'search' => 'organic',
		'social' => 'social',
		'email' => 'email',
		'referral' => 'referral',
	];

	return [
		'referer' => $referer,
		'utm_source' => $source,
		'utm_medium' => $utm_medium_map[ $type ] ?? '',
		'utm_campaign' => $campaigns[ array_rand( $campaigns ) ],
	];
}

/**
 * Select a search term and query string.
 *
 * @return array
 */
function select_search_data() : array {
	$terms = [
		'altis accelerate',
		'personalization examples',
		'ab test best practices',
		'content experimentation',
		'wordpress analytics',
		'editor performance',
		'campaign results',
		'content optimization',
	];

	if ( wp_rand( 1, 100 ) > 35 ) {
		return [
			'search' => '',
			'query_string' => '',
		];
	}

	$term = $terms[ array_rand( $terms ) ];
	return [
		'search' => $term,
		'query_string' => 's=' . rawurlencode( $term ),
	];
}

/**
 * Build a pool of sitewide URLs to simulate traffic.
 *
 * @return array[]
 */
function get_sitewide_url_pool() : array {
	$pool = [];
	$home = home_url( '/' );
	$pool[] = [
		'url' => $home,
		'post_id' => 0,
		'post_type' => 'home',
		'weight' => 40,
	];

	$posts = get_posts( [
		'post_type' => [ 'page', 'post' ],
		'post_status' => 'publish',
		'posts_per_page' => 10,
		'orderby' => 'date',
		'order' => 'DESC',
		'no_found_rows' => true,
	] );

	foreach ( $posts as $post ) {
		$pool[] = [
			'url' => get_permalink( $post ),
			'post_id' => $post->ID,
			'post_type' => $post->post_type,
			'weight' => $post->post_type === 'page' ? 15 : 10,
		];
	}

	return $pool;
}

/**
 * Select a sitewide URL from the pool.
 *
 * @param array[] $pool URL pool.
 * @return array
 */
function select_sitewide_url( array $pool ) : array {
	$weights = [];
	foreach ( $pool as $index => $entry ) {
		$weights[ $index ] = $entry['weight'];
	}
	$choice = select_weighted_key( $weights );
	return $pool[ $choice ];
}

/**
 * Select device/browser data.
 *
 * @param array $profile Profile data.
 * @return array
 */
function select_device_data( array $profile ) : array {
	$device_type = select_weighted_key( $profile['devices'] );
	$browsers = [
		'desktop' => [ 'Chrome' => 60, 'Edge' => 12, 'Firefox' => 12, 'Safari' => 10, 'Other' => 6 ],
		'mobile' => [ 'Safari' => 55, 'Chrome' => 40, 'Other' => 5 ],
		'tablet' => [ 'Safari' => 70, 'Chrome' => 25, 'Other' => 5 ],
	];
	$browser = select_weighted_key( $browsers[ $device_type ] ?? $browsers['desktop'] );

	$browser_make = [
		'Chrome' => 'Blink',
		'Edge' => 'Blink',
		'Firefox' => 'Gecko',
		'Safari' => 'WebKit',
		'Other' => '',
	];

	$browser_version = [
		'Chrome' => '121.0',
		'Edge' => '121.0',
		'Firefox' => '122.0',
		'Safari' => '17.0',
		'Other' => '',
	];

	return [
		'device_type' => $device_type,
		'browser' => $browser,
		'make' => $browser_make[ $browser ] ?? '',
		'model_version' => $browser_version[ $browser ] ?? '',
		'platform' => 'web',
	];
}

/**
 * Generate block analytics events within a time range.
 *
 * @param int[] $block_ids Block IDs to generate for.
 * @param array $options Generation options.
 * @param int $start_ms Start timestamp in milliseconds.
 * @param int $end_ms End timestamp in milliseconds.
 * @param int $impressions_per_block Impressions per block to generate.
 * @return void
 */
function generate_block_events_range( array $block_ids, array $options, int $start_ms, int $end_ms, int $impressions_per_block ) : void {
	if ( $end_ms <= $start_ms ) {
		return;
	}

	$winner_variant = $options['winner_variant'] ?? null;
	$lift = ( $options['lift'] ?? 0 ) / 100;
	$shape = $options['shape'] ?? 'growth';
	$preset = $options['preset'] ?? 'balanced';

	$hourly_weights = get_hourly_weights( $shape );
	$realism_profile = get_realism_profile( $preset );

	foreach ( $block_ids as $block_id ) {
		$block = get_post( $block_id );
		if ( ! $block ) {
			continue;
		}

		$variants = get_block_variants( $block );
		if ( empty( $variants ) ) {
			continue;
		}

		$block_type = get_block_type( $block );

		$client_id = $block->post_name;
		$events = [];
		$returning_visitors = [];

		for ( $i = 0; $i < $impressions_per_block; $i++ ) {
			$event_timestamp = get_random_timestamp_in_range( $start_ms, $end_ms, $hourly_weights );

			$is_returning = ( wp_rand( 1, 100 ) <= ( $realism_profile['returning_rate'] * 100 ) );
			if ( $is_returning && ! empty( $returning_visitors ) ) {
				$visitor_id = $returning_visitors[ array_rand( $returning_visitors ) ];
			} else {
				$visitor_id = wp_generate_uuid4();
				$returning_visitors[] = $visitor_id;
			}
			$session_id = wp_generate_uuid4();

			$assignment = assign_variant_with_target( $variants, $winner_variant, $lift );
			$variant = $assignment['variant'];
			$conversion_rate = $assignment['conversion_rate'];

			$geo = select_geo_data( $realism_profile );
			$referrer = select_referrer_data( $realism_profile );
			$device = select_device_data( $realism_profile );

			$base_attributes = [
				'blockId' => (string) $block_id,
				'clientId' => $client_id,
				'postId' => (string) $block_id,
				'variant' => (string) $variant['id'],
				'eventPostId' => (string) $block_id,
				'date' => gmdate( DATE_ISO8601, $event_timestamp / 1000 ),
				'referer' => $referrer['referer'],
				'utm_source' => $referrer['utm_source'],
				'utm_medium' => $referrer['utm_medium'],
				'utm_campaign' => $referrer['utm_campaign'],
				'device_type' => $device['device_type'],
				'browser' => $device['browser'],
				'visitor_type' => $is_returning ? 'returning' : 'new',
				'country' => $geo['country'],
				'region' => $geo['region'],
				'city' => $geo['city'],
			];

			if ( $block_type !== 'standard' ) {
				$base_attributes['type'] = $block_type === 'ab-test' ? 'abtest' : ( $block_type === 'personalization' ? 'personalization' : 'broadcast' );
				$base_attributes['eventTestId'] = $block_type === 'ab-test' ? 'xb' : '';
				$base_attributes['eventVariantId'] = (string) $variant['id'];
			}

			$event = build_event_payload( 'blockView', $event_timestamp, $base_attributes, $geo, $device, $visitor_id, $session_id );

			$events[] = $event;

			if ( wp_rand( 1, 100 ) <= $conversion_rate * 100 ) {
				$conversion_attributes = $base_attributes;
				$conversion_attributes['goal'] = 'click_any_link';
				$conversion_attributes['date'] = gmdate( DATE_ISO8601, ( $event_timestamp + 5000 ) / 1000 );
				$conversion_event = build_event_payload( 'conversion', $event_timestamp + 5000, $conversion_attributes, $geo, $device, $visitor_id, $session_id );
				$events[] = $conversion_event;
			}
		}

		import_events_to_clickhouse( $events, 'block-range' );
		\Altis\Analytics\Demo\debug_log( 'Block range batch imported', [
			'block_id' => $block_id,
			'events' => count( $events ),
		] );
	}
}

/**
 * Generate sitewide analytics events within a time range.
 *
 * @param int $count Number of events.
 * @param array $options Generation options.
 * @param int $start_ms Start timestamp in milliseconds.
 * @param int $end_ms End timestamp in milliseconds.
 * @return void
 */
function generate_sitewide_events_range( int $count, array $options, int $start_ms, int $end_ms ) : void {
	if ( $count < 1 || $end_ms <= $start_ms ) {
		return;
	}

	$shape = $options['shape'] ?? 'growth';
	$preset = $options['preset'] ?? 'balanced';
	$hourly_weights = get_hourly_weights( $shape );
	$realism_profile = get_realism_profile( $preset );
	$url_pool = get_sitewide_url_pool();
	$events = [];
	$returning_visitors = [];
	$home_url = home_url( '/' );
	$host = wp_parse_url( $home_url, PHP_URL_HOST );

	for ( $i = 0; $i < $count; $i++ ) {
		$event_timestamp = get_random_timestamp_in_range( $start_ms, $end_ms, $hourly_weights );

		$is_returning = ( wp_rand( 1, 100 ) <= ( $realism_profile['returning_rate'] * 100 ) );
		if ( $is_returning && ! empty( $returning_visitors ) ) {
			$visitor_id = $returning_visitors[ array_rand( $returning_visitors ) ];
		} else {
			$visitor_id = wp_generate_uuid4();
			$returning_visitors[] = $visitor_id;
		}
		$session_id = wp_generate_uuid4();

		$geo = select_geo_data( $realism_profile );
		$referrer = select_referrer_data( $realism_profile );
		$device = select_device_data( $realism_profile );
		$search = select_search_data();
		$url_entry = select_sitewide_url( $url_pool );

		$events[] = build_event_payload( 'pageView', $event_timestamp, [
			'url' => $url_entry['url'],
			'host' => $host ?: '',
			'referer' => $referrer['referer'],
			'utm_source' => $referrer['utm_source'],
			'utm_medium' => $referrer['utm_medium'],
			'utm_campaign' => $referrer['utm_campaign'],
			'date' => gmdate( DATE_ISO8601, $event_timestamp / 1000 ),
			'queryString' => $search['query_string'],
			'search' => $search['search'],
			'hash' => '',
			'postType' => $url_entry['post_type'],
			'postId' => (string) $url_entry['post_id'],
			'device_type' => $device['device_type'],
			'browser' => $device['browser'],
			'visitor_type' => $is_returning ? 'returning' : 'new',
			'country' => $geo['country'],
			'region' => $geo['region'],
			'city' => $geo['city'],
		], $geo, $device, $visitor_id, $session_id );
	}

		import_events_to_clickhouse( $events, 'sitewide-range' );
		\Altis\Analytics\Demo\debug_log( 'Sitewide range batch imported', [
			'events' => count( $events ),
		] );
}

/**
 * Generate sitewide events per minute within a time range.
 *
 * @param int $start_ms Start timestamp in milliseconds.
 * @param int $end_ms End timestamp in milliseconds.
 * @param int $events_per_minute Events per minute.
 * @param array $options Generation options.
 * @return void
 */
function generate_sitewide_events_per_minute_range( int $start_ms, int $end_ms, int $events_per_minute, array $options ) : void {
	if ( $events_per_minute < 1 || $end_ms <= $start_ms ) {
		return;
	}

	$preset = $options['preset'] ?? 'balanced';
	$realism_profile = get_realism_profile( $preset );
	$url_pool = get_sitewide_url_pool();

	$start_minute = (int) floor( $start_ms / ( 60 * 1000 ) ) * ( 60 * 1000 );
	$end_minute = (int) floor( $end_ms / ( 60 * 1000 ) ) * ( 60 * 1000 );
	$home_url = home_url( '/' );
	$host = wp_parse_url( $home_url, PHP_URL_HOST );
	$returning_visitors = [];

	for ( $minute = $start_minute; $minute <= $end_minute; $minute += 60 * 1000 ) {
		$events = [];
		for ( $i = 0; $i < $events_per_minute; $i++ ) {
			$event_timestamp = $minute + ( wp_rand( 0, 59 ) * 1000 );
			if ( $event_timestamp < $start_ms || $event_timestamp > $end_ms ) {
				continue;
			}

			$is_returning = ( wp_rand( 1, 100 ) <= ( $realism_profile['returning_rate'] * 100 ) );
			if ( $is_returning && ! empty( $returning_visitors ) ) {
				$visitor_id = $returning_visitors[ array_rand( $returning_visitors ) ];
			} else {
				$visitor_id = wp_generate_uuid4();
				$returning_visitors[] = $visitor_id;
			}
			$session_id = wp_generate_uuid4();

			$geo = select_geo_data( $realism_profile );
			$referrer = select_referrer_data( $realism_profile );
			$device = select_device_data( $realism_profile );
			$search = select_search_data();
			$url_entry = select_sitewide_url( $url_pool );

			$events[] = build_event_payload( 'pageView', $event_timestamp, [
				'url' => $url_entry['url'],
				'host' => $host ?: '',
				'referer' => $referrer['referer'],
				'utm_source' => $referrer['utm_source'],
				'utm_medium' => $referrer['utm_medium'],
				'utm_campaign' => $referrer['utm_campaign'],
				'date' => gmdate( DATE_ISO8601, $event_timestamp / 1000 ),
				'queryString' => $search['query_string'],
				'search' => $search['search'],
				'hash' => '',
				'postType' => $url_entry['post_type'],
				'postId' => (string) $url_entry['post_id'],
				'device_type' => $device['device_type'],
				'browser' => $device['browser'],
				'visitor_type' => $is_returning ? 'returning' : 'new',
				'country' => $geo['country'],
				'region' => $geo['region'],
				'city' => $geo['city'],
			], $geo, $device, $visitor_id, $session_id );
		}

		import_events_to_clickhouse( $events, 'sitewide-minute' );
		\Altis\Analytics\Demo\debug_log( 'Sitewide minute batch imported', [
			'events' => count( $events ),
		] );
	}
}

/**
 * Build a ClickHouse-ready event payload.
 *
 * @param string $event_type Event type.
 * @param int $event_timestamp Timestamp in milliseconds.
 * @param array $attributes Event attributes.
 * @param array $geo Geo data.
 * @param array $device Device data.
 * @param string $visitor_id Visitor ID.
 * @param string $session_id Session ID.
 * @return array
 */
function build_event_payload( string $event_type, int $event_timestamp, array $attributes, array $geo, array $device, string $visitor_id, string $session_id ) : array {
	return [
		'app_id' => defined( 'ALTIS_ANALYTICS_PINPOINT_ID' ) ? ALTIS_ANALYTICS_PINPOINT_ID : 'altis',
		'event_type' => $event_type,
		'event_timestamp' => \Altis\Analytics\Demo\ch_format_date( $event_timestamp ),
		'attributes' => (object) $attributes,
		'metrics' => (object) [],
		'endpoint_id' => $visitor_id,
		'endpoint_attributes' => (object) [],
		'endpoint_metrics' => (object) [],
		'endpoint_address' => '',
		'endpoint_optout' => 'NONE',
		'app_version' => '',
		'locale' => $geo['locale'],
		'make' => $device['make'],
		'model' => $device['browser'],
		'model_version' => $device['model_version'],
		'platform' => $device['platform'],
		'platform_version' => '',
		'country' => $geo['country'],
		'city' => $geo['city'],
		'postal_code' => '',
		'region' => $geo['region'],
		'user_id' => '',
		'user_attributes' => (object) [],
		'session_id' => $session_id,
		'session_start' => \Altis\Analytics\Demo\ch_format_date( $event_timestamp ),
		'session_stop' => null,
		'session_duration' => null,
	];
}

/**
 * Import events to ClickHouse in batches.
 *
 * @param array $events Event payloads.
 * @return void
 */
function import_events_to_clickhouse( array $events, string $context = '' ) : void {
	if ( empty( $events ) ) {
		return;
	}

	$batch_size = 50;
	$batches = array_chunk( $events, $batch_size );

	foreach ( $batches as $batch ) {
		$lines = array_map( function ( $event ) {
			return json_encode( $event );
		}, $batch );

		try {
			\Altis\Analytics\Demo\import_clickhouse( $lines );
		} catch ( Exception $e ) {
			\Altis\Analytics\Demo\debug_log( 'ClickHouse import failed', [
				'context' => $context,
				'message' => $e->getMessage(),
			] );
			return;
		}

		sleep( 1 );
	}
}

/**
 * Get a random timestamp in range, optionally biased by hourly weights.
 *
 * @param int $start_ms Start timestamp in milliseconds.
 * @param int $end_ms End timestamp in milliseconds.
 * @param int[] $hourly_weights Hour weights.
 * @return int
 */
function get_random_timestamp_in_range( int $start_ms, int $end_ms, array $hourly_weights ) : int {
	$start_seconds = (int) floor( $start_ms / 1000 );
	$end_seconds = (int) floor( $end_ms / 1000 );

	if ( $end_seconds <= $start_seconds ) {
		return $start_ms;
	}

	$random_seconds = wp_rand( $start_seconds, $end_seconds );
	$timestamp = $random_seconds * 1000;

	if ( ! empty( $hourly_weights ) ) {
		$hour = \Altis\Analytics\Demo\get_random_weighted_element( $hourly_weights );
		$minute = wp_rand( 0, 59 );
		$second = wp_rand( 0, 59 );
		$day_start = strtotime( gmdate( 'Y-m-d', $random_seconds ) ) * 1000;
		$timestamp = $day_start + ( $hour * HOUR_IN_SECONDS * 1000 ) + ( $minute * MINUTE_IN_SECONDS * 1000 ) + ( $second * 1000 );
		if ( $timestamp < $start_ms || $timestamp > $end_ms ) {
			$timestamp = $random_seconds * 1000;
		}
	}

	return $timestamp;
}

/**
 * Generate smooth analytics events for selected blocks.
 *
 * @param int[] $block_ids Selected block IDs.
 * @param array $options Generation config with keys: days, volume, winner_variant, lift, shape, preset.
 * @return void
 */
function generate_block_analytics( array $block_ids, array $options ) : void {
	\Altis\Analytics\Demo\update_option( 'last_status', 'block', 'running' );
	\Altis\Analytics\Demo\debug_log( 'Block generation start', [
		'block_ids' => $block_ids,
		'options' => $options,
	] );

	register_shutdown_function( function () {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
			\Altis\Analytics\Demo\update_option( 'failed', 'block', 'Fatal error: ' . $error['message'] );
			\Altis\Analytics\Demo\update_option( 'running', 'block', false );
			\Altis\Analytics\Demo\update_option( 'last_status', 'block', 'failed' );
			\Altis\Analytics\Demo\debug_log( 'Block generation fatal error', [ 'error' => $error ] );
		}
	} );

	try {
		$days = $options['days'] ?? 31;
		$volume = $options['volume'] ?? 1500;
		$winner_variant = $options['winner_variant'] ?? null;
		$lift = ( $options['lift'] ?? 15 ) / 100; // Convert percentage to decimal.
		$shape = $options['shape'] ?? 'growth';
		$preset = $options['preset'] ?? 'balanced';

		$volume = max( 100, min( 100000, intval( $volume ) ) );
		$days = max( 7, min( 90, intval( $days ) ) );
		$shape = array_key_exists( $shape, get_traffic_shapes() ) ? $shape : 'growth';
		$preset = array_key_exists( $preset, get_realism_presets() ) ? $preset : 'balanced';

		$volume_per_block = (int) round( $volume * ( $days / 31 ) );
		$volume_per_block = max( 1, $volume_per_block );

		// Track progress.
		$total_blocks = count( $block_ids );
		$progress = 0;
		\Altis\Analytics\Demo\update_option( 'total', 'block', $total_blocks * $volume_per_block );
		\Altis\Analytics\Demo\update_option( 'progress', 'block', 0 );

		// Time range.
		$max_timestamp = strtotime( 'today midnight' ) * 1000;

		// Get smooth daily distribution.
		$daily_distribution = smooth_daily_distribution( $volume_per_block, $days, $shape );
		$hourly_weights = get_hourly_weights( $shape );
		$realism_profile = get_realism_profile( $preset );

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
			$returning_visitors = [];

			// Generate events for each day.
			foreach ( $daily_distribution as $day_index => $events_for_day ) {
				$day_timestamp = $max_timestamp - ( $day_index * DAY_IN_SECONDS * 1000 );

				// Distribute events across hours.
				for ( $i = 0; $i < $events_for_day; $i++ ) {
				// Pick weighted hour.
				$hour = \Altis\Analytics\Demo\get_random_weighted_element( $hourly_weights );
				$minute = wp_rand( 0, 59 );
				$second = wp_rand( 0, 59 );
				$event_timestamp = $day_timestamp + ( $hour * HOUR_IN_SECONDS * 1000 ) + ( $minute * MINUTE_IN_SECONDS * 1000 ) + ( $second * 1000 );

				// Generate unique visitor and session.
				$is_returning = ( wp_rand( 1, 100 ) <= ( $realism_profile['returning_rate'] * 100 ) );
				if ( $is_returning && ! empty( $returning_visitors ) ) {
					$visitor_id = $returning_visitors[ array_rand( $returning_visitors ) ];
				} else {
					$visitor_id = wp_generate_uuid4();
					$returning_visitors[] = $visitor_id;
				}
				$session_id = wp_generate_uuid4();

				// Assign variant with performance targeting.
				$assignment = assign_variant_with_target( $variants, $winner_variant, $lift );
				$variant = $assignment['variant'];
				$conversion_rate = $assignment['conversion_rate'];

				// Select realism attributes.
				$geo = select_geo_data( $realism_profile );
				$referrer = select_referrer_data( $realism_profile );
				$device = select_device_data( $realism_profile );

				$base_attributes = [
					'blockId' => (string) $block_id,
					'clientId' => $client_id,
					'postId' => (string) $block_id,
					'variant' => (string) $variant['id'],
					'eventPostId' => (string) $block_id,
					'date' => gmdate( DATE_ISO8601, $event_timestamp / 1000 ),
					'referer' => $referrer['referer'],
					'utm_source' => $referrer['utm_source'],
					'utm_medium' => $referrer['utm_medium'],
					'utm_campaign' => $referrer['utm_campaign'],
					'device_type' => $device['device_type'],
					'browser' => $device['browser'],
					'visitor_type' => $is_returning ? 'returning' : 'new',
					'country' => $geo['country'],
					'region' => $geo['region'],
					'city' => $geo['city'],
				];

				if ( $block_type !== 'standard' ) {
					$base_attributes['type'] = $block_type === 'ab-test' ? 'abtest' : ( $block_type === 'personalization' ? 'personalization' : 'broadcast' );
					$base_attributes['eventTestId'] = $block_type === 'ab-test' ? 'xb' : '';
					$base_attributes['eventVariantId'] = (string) $variant['id'];
				}

				// Create block view event.
				$event = [
					'app_id' => defined( 'ALTIS_ANALYTICS_PINPOINT_ID' ) ? ALTIS_ANALYTICS_PINPOINT_ID : 'altis',
					'event_type' => 'blockView',
					'event_timestamp' => \Altis\Analytics\Demo\ch_format_date( $event_timestamp ),
					'attributes' => (object) $base_attributes,
					'metrics' => (object) [],
					'endpoint_id' => $visitor_id,
					'endpoint_attributes' => (object) [],
					'endpoint_metrics' => (object) [],
					'endpoint_address' => '',
					'endpoint_optout' => 'NONE',
					'app_version' => '',
					'locale' => $geo['locale'],
					'make' => $device['make'],
					'model' => $device['browser'],
					'model_version' => $device['model_version'],
					'platform' => $device['platform'],
					'platform_version' => '',
					'country' => $geo['country'],
					'city' => $geo['city'],
					'postal_code' => '',
					'region' => $geo['region'],
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
					$conversion_attributes = $base_attributes;
					$conversion_attributes['goal'] = 'click_any_link';
					$conversion_attributes['date'] = gmdate( DATE_ISO8601, ( $event_timestamp + 5000 ) / 1000 );
					$conversion_event['attributes'] = (object) $conversion_attributes;
					$events[] = $conversion_event;
				}

					$progress++;
				}
			}

			// Write events to ClickHouse in batches.
			$batch_size = 50;
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
					\Altis\Analytics\Demo\update_option( 'last_status', 'block', 'failed' );
					\Altis\Analytics\Demo\debug_log( 'Block generation failed', [ 'message' => $e->getMessage() ] );
					return;
				}

				\Altis\Analytics\Demo\update_option( 'progress', 'block', $progress );
				\Altis\Analytics\Demo\update_option( 'last_progress_at', 'block', time() );
				\Altis\Analytics\Demo\debug_log( 'Block generation progress', [
					'block_id' => $block_id,
					'progress' => $progress,
				] );

				// Sleep to avoid overload.
				sleep( 2 );
			}
		}

		// Mark as complete.
		\Altis\Analytics\Demo\update_option( 'success', 'block', true );
		\Altis\Analytics\Demo\update_option( 'running', 'block', false );
		\Altis\Analytics\Demo\update_option( 'last_status', 'block', 'completed' );
		\Altis\Analytics\Demo\debug_log( 'Block generation complete', [ 'blocks' => count( $block_ids ) ] );
	} catch ( Throwable $e ) {
		\Altis\Analytics\Demo\update_option( 'failed', 'block', $e->getMessage() );
		\Altis\Analytics\Demo\update_option( 'running', 'block', false );
		\Altis\Analytics\Demo\update_option( 'last_status', 'block', 'failed' );
		\Altis\Analytics\Demo\debug_log( 'Block generation exception', [ 'message' => $e->getMessage() ] );
	}
}
