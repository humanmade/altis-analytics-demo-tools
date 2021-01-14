<?php
/**
 * Altis Analytics Demo Data Importer.
 */

namespace Altis\Analytics\Demo;

use Altis\Analytics\Utils;
use Exception;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Sets up the plugin hooks.
 */
function setup() {
	add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\\handle_request' );
	add_action( 'altis_analytics_import_demo_data', __NAMESPACE__ . '\\import_data' );
	add_action( 'wp_ajax_get_analytics_demo_data_import_progress', __NAMESPACE__ . '\\ajax_get_progress' );

	// Add altis-audiences as a redis group for easy removal.
	if ( function_exists( 'wp_cache_add_redis_hash_groups' ) ) {
		wp_cache_add_redis_hash_groups( 'altis-audiences' );
	}
}

/**
 * Adds the tools submenu page for the plugin.
 */
function admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'Analytics Demo Tools' ),
		__( 'Analytics Demo' ),
		'manage_options',
		'analytics-demo',
		__NAMESPACE__ . '\\tools_page'
	);
}

/**
 * Include the analytics demo tools admin page view.
 */
function tools_page() {
	$total = (int) get_option( 'altis_analytics_demo_import_total', 100 );
	$progress = (int) get_option( 'altis_analytics_demo_import_progress', 0 );
	$nonce = wp_create_nonce( 'get_analytics_demo_data_import_progress' );
	$xb_page = get_demo_experience_block_page();

	include __DIR__ . '/views/tools-page.php';
	delete_option( 'altis_analytics_demo_import_success' );
	delete_option( 'altis_analytics_demo_import_failed' );
}

/**
 * Verifies the form submission and sets up the background processing
 * task to import data.
 */
function handle_request() {
	$time_range = null;

	if ( isset( $_POST['altis-analytics-demo-week'] ) ) {
		$time_range = 7;
	}

	if ( isset( $_POST['altis-analytics-demo-fortnight'] ) ) {
		$time_range = 14;
	}

	if ( empty( $time_range ) ) {
		return;
	}

	if ( ! check_admin_referer( 'altis-analytics-demo-import', '_altisnonce' ) ) {
		return;
	}

	if ( get_option( 'altis_analytics_demo_import_running', false ) ) {
		return;
	}

	// Create audiences.
	maybe_create_audiences();

	// Create an experience block.
	maybe_create_experience_block();

	// Prepare import metrics.
	update_option( 'altis_analytics_demo_import_total', 100 );
	update_option( 'altis_analytics_demo_import_progress', 0 );
	update_option( 'altis_analytics_demo_import_running', true );

	// Run the import in the background.
	wp_schedule_single_event( time(), 'altis_analytics_import_demo_data', [ $time_range ] );
}

/**
 * Return the current import progress via AJAX.
 */
function ajax_get_progress() {
	if ( ! check_ajax_referer( 'get_analytics_demo_data_import_progress', false, false ) ) {
		wp_send_json_error( new WP_Error( 401, 'Invalid nonce provided' ) );
		return;
	}

	$total = (int) get_option( 'altis_analytics_demo_import_total', 100 );
	$progress = (int) get_option( 'altis_analytics_demo_import_progress', 0 );

	wp_send_json_success( [
		'total' => $total,
		'progress' => $progress,
	] );
}

/**
 * Imports ElasticSearch document queries line by line from /data/events.log.
 *
 * The events are preprocessed in the following way:
 * - Time stamp is randomised over the $time_range or number of days back to create records for
 *   with a weighted random value for the hour of day, eg. peaks in the morning and evening.
 * - The session ID that connects events together is randomly generated to prevent unrealistic data.
 * - The visitor's unique ID is be randomly generated 40% of the time to mimic new visitors.
 *
 * @param integer $time_range Number of days back to spread the event entries out over.
 */
function import_data( int $time_range = 7 ) {
	update_option( 'altis_analytics_demo_import_running', true );

	try {
		// Grab the file contents.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$handle = fopen( dirname( __FILE__, 2 ) . '/data/events.log', 'r' );

		if ( ! $handle ) {
			trigger_error( 'Demo data file could not be found', E_USER_ERROR );
			delete_option( 'altis_analytics_demo_import_running' );
			return;
		}

		$line_count = 0;
		$progress = 0;
		while ( ! feof( $handle ) ) {
			fgets( $handle );
			$line_count++;
		}

		// Enable progress tracking.
		update_option( 'altis_analytics_demo_import_total', $line_count );

		// Reset to the start of the file.
		rewind( $handle );

		// Get replacement reference with no trailingslash.
		$home_url = home_url();

		// Get the demo audience and XB posts.
		$audiences = get_demo_audiences();
		$xb_page = get_demo_experience_block_page();
		$xb_page_url = get_the_permalink( $xb_page );

		// Get the earliest starting time.
		$max_session_start_time = strtotime( 'today midnight' ) * 1000;
		$min_session_start_time = $max_session_start_time - ( DAY_IN_SECONDS * $time_range * 1000 );

		// Create indexes for all the days we're adding data to.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$mapping = file_get_contents( dirname( __FILE__, 2 ) . '/data/mapping.json' );
		$index_date = $max_session_start_time;
		while ( $index_date > $min_session_start_time ) {
			$index_name = date( 'Y-m-d', $index_date / 1000 );
			$index_date -= DAY_IN_SECONDS * 1000;
			wp_remote_post(
				sprintf( '%s/analytics-%s', Utils\get_elasticsearch_url(), $index_name ),
				[
					'headers' => [
						'Content-Type' => 'application/json',
					],
					'method' => 'PUT',
					'body' => $mapping,
				]
			);
		}

		// Current endpoint ID.
		$sessions = [];

		// Build up 100 lines at a time for a bulk import.
		$lines = [];

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			$progress++;

			if ( ! empty( $line ) ) {

				// Get session ID - we only increment the time stamp when a new one is encountered
				// so the data is at least somewhat reasonable.
				preg_match( '/"session":\["([a-z0-9-]+)"\]/', $line, $matches );
				if ( isset( $matches[1] ) ) {

					if ( isset( $sessions[ $matches[1] ] ) ) {
						$time_stamp = $sessions[ $matches[1] ]['time_stamp'];
						$session_id = $sessions[ $matches[1] ]['session_id'];
						$visitor_id = $sessions[ $matches[1] ]['visitor_id'];
					} else {
						// Calculate session start time using weighted random numbers so hours are useful.
						$day = get_random_weighted_element( [ 1, 1, 1, 1, 1, 1, 1 ] );
						$hour = get_random_weighted_element( array_reverse( [ 1, 1, 1, 2, 2, 3, 3, 5, 8, 9, 6, 5, 10, 12, 7, 4, 5, 7, 10, 12, 14, 10, 8, 3 ] ) );
						$time_stamp = $max_session_start_time - ( $day * DAY_IN_SECONDS * 1000 ) - ( $hour * HOUR_IN_SECONDS * 1000 );

						// Modulate session ID so reimports dont produce weird looking results.
						$session_id = wp_generate_uuid4();

						// Randomly modulate the visitor ID to allow for some recurring traffic.
						$visitor_id = false;
						if ( wp_rand( 0, 10 ) < 4 ) {
							$visitor_id = wp_generate_uuid4();
						}

						// Store to group session events together.
						$sessions[ $matches[1] ] = [
							'time_stamp' => $time_stamp,
							'session_id' => $session_id,
							'visitor_id' => $visitor_id,
						];
					}

					// Replace endpoint ID.
					if ( $visitor_id ) {
						$line = preg_replace( '/"Id":"([a-z0-9-]+)"/', '"Id":"' . $visitor_id . '"', $line );
					}

					// Replace session ID.
					$line = preg_replace( '/"session":\["([a-z0-9-]+)"\]/', '"session":"' . $session_id . '"', $line );

					// Replace event timestamp - spread this out over time.
					$line = preg_replace( '/"event_timestamp":\d+/', '"event_timestamp":' . $time_stamp, $line );

					// Add ISO date string attribute.
					$line = preg_replace( '/"attributes":{/', '"attributes":{"date":"' . gmdate( DATE_ISO8601, $time_stamp / 1000 ) . '",', $line );

					// Replace URL.
					$line = str_replace( 'https://altis-dev.altis.dev', $home_url, $line );

					// Replace blog & network IDs.
					$line = str_replace( '"blogId":"1"', sprintf( '"blogId":"%s"', get_current_blog_id() ), $line );
					$line = str_replace( '"networkId":"1"', sprintf( '"networkId":"%s"', get_current_network_id() ), $line );

					// Modify Experience Block analytics events.
					if ( strpos( $line, '"event_type":"experience' ) !== false || strpos( $line, '"event_type":"conversion' ) !== false ) {
						// Replace audience IDs with our built in ones.
						if ( strpos( $line, '"Country":"FR"' ) !== false ) {
							$line = preg_replace( '/"audience":"(\d+)"/', '"audience":' . $audiences[0]->ID ?? '$1', $line );
						}
						if ( strpos( $line, '"Country":"JP"' ) !== false ) {
							$line = preg_replace( '/"audience":"(\d+)"/', '"audience":' . $audiences[1]->ID ?? '$1', $line );
						}
						// Replace post ID and URL.
						$line = preg_replace( '/"postId":"(\d+)"/', '"postId":"' . ( $xb_page->ID ?? '$1' ) . '"', $line );
						$line = preg_replace( '/"url":"([^"]+)"/', '"url":"' . ( $xb_page_url ?: '$1' ) . '"', $line );
					}

					// Append line.
					$lines[] = $line;
				}
			}

			// Only POST data after we've collected 100 rows or the file has reached the end.
			if ( count( $lines ) < 100 && ! feof( $handle ) ) {
				continue;
			}

			// Post to correct day index.
			$index_name = date( 'Y-m-d', $time_stamp / 1000 );

			// ND-JSON metadata line to create a record.
			$metadata = '{"index":{}}';

			// Add the document to ES.
			wp_remote_post(
				sprintf( '%s/analytics-%s/record/_bulk', Utils\get_elasticsearch_url(), $index_name ),
				[
					'headers' => [
						'Content-Type' => 'application/x-ndjson',
					],
					// Must have an action metadata line followed by the record and end with a newline.
					'body' => "{$metadata}\n" . implode( "{$metadata}\n", $lines ) . "\n",
					'blocking' => true,
				]
			);

			// Store total processed.
			update_option( 'altis_analytics_demo_import_progress', $progress );

			// Have a little sleep for 0.25s to avoid overloading ES.
			usleep( 250000 );

			// Reset lines.
			$lines = [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $handle );

		// Add a flag so we know when the import has run.
		update_option( 'altis_analytics_demo_import_success', true );
	} catch ( Exception $e ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		trigger_error( 'A problem occurred while importing analytics data. ' . $e->getMessage(), E_USER_ERROR );
		// Add a flag to check if the import failed for any reason.
		update_option( 'altis_analytics_demo_import_failed', $e->getMessage() );
	}

	delete_option( 'altis_analytics_demo_import_running' );

	// Delete caches.
	if ( function_exists( 'wp_cache_delete_group' ) ) {
		wp_cache_delete_group( 'altis-audiences' );
	}
}

/**
 * Given an array with the values as integer weights a random key
 * will be returned with the weight taken into account. Higher weights
 * mean the key is more likely to be returned.
 *
 * @param array $weighted_values An array of weighted values eg. [ 'foo' => 10, 'bar' => 20 ].
 * @return int|string The selected array key.
 */
function get_random_weighted_element( array $weighted_values ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
	$rand = mt_rand( 1, (int) array_sum( $weighted_values ) );

	foreach ( $weighted_values as $key => $value ) {
		$rand -= $value;
		if ( $rand <= 0 ) {
			return $key;
		}
	}
}

/**
 * Fetch the automatically created demo audiences.
 *
 * @return array
 */
function get_demo_audiences() : array {
	$existing = new WP_Query(
		[
			'post_type' => 'audience',
			'meta_key' => '_altis_analytics_demo_data',
			'posts_per_page' => 2,
			'orderby' => 'post_name',
			'order' => 'ASC',
		]
	);

	return $existing->posts;
}

/**
 * Create default audiences if none exist yet.
 *
 * @return void
 */
function maybe_create_audiences() {
	$existing = get_demo_audiences();

	// Audiences already exist.
	if ( count( $existing ) === 2 ) {
		return;
	}

	// Could happen, if it does we'll just delete it and recreate it.
	if ( count( $existing ) === 1 ) {
		foreach ( $existing as $audience ) {
			wp_delete_post( $audience->ID, true );
		}
	}

	// Custom audiences.
	$audiences = [
		[
			'title' => 'France',
			'config' => [
				'include' => 'all',
				'groups' => [
					[
						'include' => 'any',
						'rules' => [
							[
								'field' => 'endpoint.Location.Country',
								'operator' => '=',
								'value' => 'FR',
							],
						],
					],
				],
			],
		],
		[
			'title' => 'Japan',
			'config' => [
				'include' => 'all',
				'groups' => [
					[
						'include' => 'any',
						'rules' => [
							[
								'field' => 'endpoint.Location.Country',
								'operator' => '=',
								'value' => 'JP',
							],
						],
					],
				],
			],
		],
	];

	foreach ( $audiences as $audience ) {
		$post_id = wp_insert_post( [
			'post_type' => 'audience',
			'post_status' => 'publish',
			'post_title' => $audience['title'],
			'post_name' => strtolower( $audience['title'] ),
		] );

		update_post_meta( $post_id, '_altis_analytics_demo_data', true );
		update_post_meta( $post_id, 'audience', $audience['config'] );
	}
}

/**
 * Fetch the automatically created demo experience block page.
 *
 * @return WP_Post|null
 */
function get_demo_experience_block_page() : ?WP_Post {
	$existing = new WP_Query(
		[
			'post_type' => 'page',
			'meta_key' => '_altis_analytics_demo_data',
			'posts_per_page' => 1,
		]
	);

	if ( ! $existing->found_posts ) {
		return null;
	}

	return $existing->posts[0];
}

/**
 * Create an Experience Block for show casing conversion goals.
 *
 * @return void
 */
function maybe_create_experience_block() {
	$existing = get_demo_experience_block_page();

	if ( $existing ) {
		return;
	}

	$audiences = get_demo_audiences();

	if ( empty( $audiences ) ) {
		// Something's wrong I can feel it.
		return;
	}

	$content = sprintf( '
<!-- wp:altis/personalization {"clientId":"2a7d3480-e525-4fc0-b27d-66d677dd3008"} -->
<!-- wp:altis/personalization-variant {"audience":%d,"fallback":false,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>Hey! Why not check out our latest documentary on finding the perfect Breton Crêpes. Made in collaboration with Canal+.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#canalplus" style="border-radius:12px">WATCH NOW</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/personalization-variant -->

<!-- wp:altis/personalization-variant {"audience":%d,"fallback":false,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>You can now get access to all the latest news, documentaries and podcasts delivered directly via Line. Click the button below to subscribe!</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#line" style="border-radius:12px">Subscribe via Line</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/personalization-variant -->

<!-- wp:altis/personalization-variant {"fallback":true,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>Hey! Why not check out our SoundCloud while you\'re here? Click the button below for the latest Podcasts and Corporate theme song mixes.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#soundcloud" style="border-radius:12px" target="_blank" rel="noreferrer noopener">Go To SoundCloud</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/personalization-variant -->
<!-- /wp:altis/personalization -->
',
		$audiences[0]->ID,
		$audiences[1]->ID
	);

	$page_id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'XB Analytics Demo',
		'post_content' => $content,
	] );

	update_post_meta( $page_id, '_altis_analytics_demo_data', true );
}
