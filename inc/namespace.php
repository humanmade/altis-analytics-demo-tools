<?php
/**
 * Altis Analytics Demo Data Importer.
 */

namespace Altis\Analytics\Demo;

use Altis\Analytics\Blocks;
use Altis\Analytics\Experiments;
use Altis\Analytics\Utils;
use Exception;
use Throwable;
use WP_Error;
use WP_Post;
use WP_Query;

const DEFAULT_PER_PAGE = 400;
const DEFAULT_SLEEP = 5;

/**
 * Sets up the plugin hooks.
 */
function setup() {
	add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\\handle_request' );
	add_action( 'altis_analytics_import_demo_data', __NAMESPACE__ . '\\import_data', 10, 4 );
	add_action( 'wp_ajax_get_analytics_demo_data_import_progress', __NAMESPACE__ . '\\ajax_get_progress' );

	// Data destinations.
	$destinations = get_destinations();
	if ( isset( $destinations['es'] ) ) {
		add_action( 'altis_analytics_demo_import_setup_es', __NAMESPACE__ . '\\setup_elasticsearch', 10, 2 );
		add_action( 'altis_analytics_demo_import_send_es', __NAMESPACE__ . '\\import_elasticsearch', 10, 2 );
	}
	if ( isset( $destinations['ch'] ) ) {
		add_action( 'altis_analytics_demo_import_send_ch', __NAMESPACE__ . '\\import_clickhouse', 10, 2 );
	}

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
 * Get available data destinations.
 *
 * @return array
 */
function get_destinations() : array {
	$destinations = apply_filters( 'altis.analytics_demo.destinations', [
		'es' => __( 'Elasticsearch' ),
		'ch' => __( 'ClickHouse' ),
	] );
	return $destinations;
}

/**
 * Include the analytics demo tools admin page view.
 */
function tools_page() {
	$total = [
		'es' => (int) get_option( 'total', 'es', 100 ),
		'ch' => (int) get_option( 'total', 'ch', 100 ),
	];
	$progress = [
		'es' => (int) get_option( 'progress', 'es', 100 ),
		'ch' => (int) get_option( 'progress', 'ch', 100 ),
	];
	$nonce = wp_create_nonce( 'get_analytics_demo_data_import_progress' );
	$personalized_page = get_demo_personalization_block_page();
	$ab_test_page = get_demo_ab_test_block_page();
	$destinations = get_destinations();

	include __DIR__ . '/views/tools-page.php';
}

function get_option( string $key, string $destination, $default = false ) {
	return \get_option( "altis_analytics_demo_import_{$destination}_{$key}", $default );
}

function update_option( string $key, string $destination, $value ) {
	return \update_option( "altis_analytics_demo_import_{$destination}_{$key}", $value );
}

/**
 * Verifies the form submission and sets up the background processing
 * task to import data.
 */
function handle_request() {
	$time_range = null;
	$destination = 'es';

	if ( isset( $_POST['altis-analytics-demo-week'] ) ) {
		$time_range = 7;
	}

	if ( isset( $_POST['altis-analytics-demo-fortnight'] ) ) {
		$time_range = 14;
	}

	if ( isset( $_POST['destination'] ) ) {
		$destination = sanitize_key( wp_unslash( $_POST['destination'] ) );
	}

	if ( empty( $time_range ) ) {
		return;
	}

	if ( ! check_admin_referer( 'altis-analytics-demo-import', '_altisnonce' ) ) {
		return;
	}

	if ( get_option( 'running', $destination, false ) ) {
		return;
	}

	$per_page = intval( wp_unslash( $_POST['altis-analytics-demo-per-page'] ?? DEFAULT_PER_PAGE ) );
	$sleep = intval( wp_unslash( $_POST['altis-analytics-demo-sleep'] ?? DEFAULT_SLEEP ) );

	// Create audiences.
	maybe_create_audiences();

	// Create experience blocks.
	maybe_create_personalization_block();
	maybe_create_ab_test_block();
	maybe_create_completed_ab_test();

	// Prepare import metrics.
	update_option( 'total', $destination, 100 );
	update_option( 'progress', $destination, 0 );
	update_option( 'running', $destination, true );
	update_option( 'failed', $destination, false );
	update_option( 'success', $destination, false );

	// Run the import in the background.
	wp_schedule_single_event( time(), 'altis_analytics_import_demo_data', [ $time_range, $per_page, $sleep, $destination ] );
}

/**
 * Return the current import progress via AJAX.
 */
function ajax_get_progress() {
	$destination = sanitize_key( wp_unslash( $_REQUEST['destination'] ) );
	if ( empty( $destination ) ) {
		wp_send_json_error( new WP_Error( 400, 'Destination required' ) );
		return;
	}

	if ( ! check_ajax_referer( 'get_analytics_demo_data_import_progress', false, false ) ) {
		wp_send_json_error( new WP_Error( 401, 'Invalid nonce provided' ) );
		return;
	}

	$total = (int) get_option( 'total', $destination, 100 );
	$progress = (int) get_option( 'progress', $destination, 0 );
	$failed = get_option( 'failed', $destination, false );

	if ( $failed ) {
		update_option( 'running', $destination, false );
		wp_send_json_error( [ 'message' => $failed ] );
	}

	if ( $progress >= $total ) {
		update_option( 'running', $destination, false );
		update_option( 'failed', $destination, false );
		update_option( 'success', $destination, true );
	}

	wp_send_json_success( [
		'total' => $total,
		'progress' => $progress,
	] );
}

/**
 * Create some sample persistent utm data.
 *
 * @return array
 */
function generate_utm_data() {
	$campaigns = [
		'Qrr',
		'Krr',
		'q2promo',
		'q3promo',
		'wordonthefuture',
	];

	// Mediums -> Sources.
	$mediums = [
		'social' => [
			'LinkedIn',
			'Twitter',
			'Facebook',
			'Instagram',
			'Snapchat',
			'Reddit',
		],
		'search' => [
			'google',
			'bing',
			'duckduckgo',
		],
		'newsletter' => [
			'issue12',
			'issue6',
		],
	];

	$medium_types = array_keys( $mediums );

	$terms = [
		'[UK]',
		'[US]',
		'[JP]',
	];

	$contents = [
		'b2b',
		'b2c',
		'enterprise',
		'retail',
	];

	$data['utm_campaign'] = $campaigns[ array_rand( $campaigns ) ];
	$data['utm_medium'] = $medium_types[ array_rand( $medium_types ) ];
	$data['utm_source'] = $mediums[ $data['utm_medium'] ][ array_rand( $mediums[ $data['utm_medium'] ] ) ];
	$data['utm_term'] = $terms[ array_rand( $terms ) ];
	$data['utm_content'] = $contents[ array_rand( $contents ) ];

	return $data;
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
 * @param int $time_range Number of days back to spread the event entries out over.
 * @param int $per_page Number of records per bulk request.
 * @param int $sleep Seconds to sleep in between requests.
 * @param string $destination One of 'es' or 'ch' or custom destination.
 */
function import_data( int $time_range = 7, int $per_page = DEFAULT_PER_PAGE, int $sleep = DEFAULT_SLEEP, string $destination = 'unspecified' ) {
	update_option( 'running', $destination, true );

	try {

		// Grab the file contents.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$handle = fopen( dirname( __FILE__, 2 ) . '/data/events.log', 'r' );

		if ( ! $handle ) {
			trigger_error( 'Demo data file could not be found', E_USER_ERROR );
			update_option( 'running', $destination, false );
			return;
		}

		$line_count = 0;
		$progress = 0;
		while ( ! feof( $handle ) ) {
			fgets( $handle );
			$line_count++;
		}

		// Enable progress tracking.
		update_option( 'total', $destination, $line_count );

		// Reset to the start of the file.
		rewind( $handle );

		// Get replacement reference with no trailingslash.
		$home_url = home_url();

		// Get the demo audience and XB posts.
		$audiences = get_demo_audiences();
		$personalized_page = get_demo_personalization_block_page();
		$personalized_page_url = get_the_permalink( $personalized_page );
		$ab_test_page = get_demo_ab_test_block_page();
		$ab_test_block = Blocks\get_block_post( 'f7s8fgs9-e525-4fc0-b27d-66d677dd3008' );
		$ab_test_page_url = get_the_permalink( $ab_test_page );

		// Get the earliest starting time.
		$max_session_start_time = strtotime( 'today midnight' ) * 1000;
		$min_session_start_time = $max_session_start_time - ( DAY_IN_SECONDS * $time_range * 1000 );

		// Setup destination.
		do_action( "altis_analytics_demo_import_setup_{$destination}", $max_session_start_time, $min_session_start_time );

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
				preg_match( '/"session":"([a-z0-9-]+)"/', $line, $matches );
				if ( isset( $matches[1] ) ) {

					if ( isset( $sessions[ $matches[1] ] ) ) {
						$time_stamp = $sessions[ $matches[1] ]['time_stamp'];
						$session_id = $sessions[ $matches[1] ]['session_id'];
						$visitor_id = $sessions[ $matches[1] ]['visitor_id'];
						$utm_params = $sessions[ $matches[1] ]['utm_params'];
					} else {
						// Calculate session start time using weighted random numbers so hours are useful.
						$day = get_random_weighted_element( array_fill( 0, $time_range, 1 ) );
						$hour = get_random_weighted_element( array_reverse( [ 1, 1, 1, 2, 2, 3, 3, 5, 8, 9, 6, 5, 10, 12, 7, 4, 5, 7, 10, 12, 14, 10, 8, 3 ] ) );
						$time_stamp = $max_session_start_time - ( $day * DAY_IN_SECONDS * 1000 ) - ( $hour * HOUR_IN_SECONDS * 1000 );

						// Modulate session ID so reimports dont produce weird looking results.
						$session_id = wp_generate_uuid4();

						// Randomly modulate the visitor ID to allow for some recurring traffic.
						$visitor_id = false;
						if ( wp_rand( 0, 10 ) < 4 ) {
							$visitor_id = wp_generate_uuid4();
						}

						// Randomly assign some persistent UTM params.
						$utm_params = [
							'original' => [],
							'extra' => [],
						];
						if ( wp_rand( 0, 10 ) < 4 ) {
							$utm_params['original'] = generate_utm_data();
							if ( wp_rand( 0, 10 ) < 6 ) {
								$utm_params['extra'] = generate_utm_data();
							}
						}

						// Store to group session events together.
						$sessions[ $matches[1] ] = [
							'time_stamp' => $time_stamp,
							'session_id' => $session_id,
							'visitor_id' => $visitor_id,
							'utm_params' => $utm_params,
						];
					}

					// Replace endpoint ID.
					if ( $visitor_id ) {
						$line = preg_replace( '/"Id":"([a-z0-9-]+)"/', '"Id":"' . $visitor_id . '"', $line );
					}

					// Replace session ID.
					$line = preg_replace( '/"session":"([a-z0-9-]+)"/', '"session":"' . $session_id . '"', $line );

					// Replace event timestamp - spread this out over time.
					$line = preg_replace( '/"event_timestamp":\d+/', '"event_timestamp":' . $time_stamp, $line );

					// Add ISO date string attribute.
					if ( strpos( $line, '"date":' ) !== false ) {
						$line = preg_replace( '/"date":"[^"]+"/', '"date":"' . gmdate( DATE_ISO8601, $time_stamp / 1000 ) . '"', $line );
					} else {
						$line = preg_replace( '/"attributes":{/', '"attributes":{"date":"' . gmdate( DATE_ISO8601, $time_stamp / 1000 ) . '",', $line );
					}

					// Replace URL.
					$line = str_replace( 'https://altis-dev.altis.dev', $home_url, $line );

					// Replace blog & network IDs.
					$line = str_replace( '"blogId":"1"', sprintf( '"blogId":"%s"', get_current_blog_id() ), $line );
					$line = str_replace( '"networkId":"1"', sprintf( '"networkId":"%s"', get_current_network_id() ), $line );

					// Modify Experience Block analytics events.
					if ( strpos( $line, '"event_type":"experience' ) !== false || strpos( $line, '"event_type":"conversion' ) !== false ) {
						// Replace audience IDs with our built in ones.
						if ( strpos( $line, '"Country":"FR"' ) !== false ) {
							$line = preg_replace( '/"audience":"(\d+)"/', '"audience":"' . ( $audiences[0]->ID ?? '$1' ) . '"', $line );
						}
						if ( strpos( $line, '"Country":"JP"' ) !== false ) {
							$line = preg_replace( '/"audience":"(\d+)"/', '"audience":"' . ( $audiences[1]->ID ?? '$1' ) . '"', $line );
						}
						// Replace post ID and URL for personalized content.
						if ( strpos( $line, '"clientId":"2a7d3480-e525-4fc0-b27d-66d677dd3008"' ) !== false ) {
							$line = preg_replace( '/"postId":"(\d+)"/', '"postId":"' . ( $personalized_page->ID ?? '$1' ) . '"', $line );
							$line = preg_replace( '/"url":"([^"]+)"/', '"url":"' . ( $personalized_page_url ?: '$1' ) . '"', $line );
						}
						// Replace post ID and URL for A/B test content.
						if ( strpos( $line, '"clientId":"f7s8fgs9-e525-4fc0-b27d-66d677dd3008"' ) !== false ) {
							$line = preg_replace( '/"postId":"(\d+)"/', '"postId":"' . ( $ab_test_page->ID ?? '$1' ) . '"', $line );
							$line = preg_replace( '/"test_xb_(\d+)":/', '"test_xb_' . ( $ab_test_block->ID ?? '$1' ) . '":', $line );
							$line = preg_replace( '/"eventPostId":"(\d+)"/', '"eventPostId":"' . ( $ab_test_block->ID ?? '$1' ) . '"', $line );
							$line = preg_replace( '/"url":"([^"]+)"/', '"url":"' . ( $ab_test_page_url ?: '$1' ) . '"', $line );
						}
					}

					// Add persistent UTM params.
					if ( ! empty( $utm_params['original'] ) ) {
						$utm_string = array_reduce( array_keys( $utm_params['original'] ), function ( $carry, $key ) use ( $utm_params ) {
							return sprintf( '%s"initial_%s":["%s"],', $carry, $key, $utm_params['original'][ $key ] );
						}, '' );
						$utm_string .= array_reduce( array_keys( $utm_params['original'] ), function ( $carry, $key ) use ( $utm_params ) {
							$value = $utm_params['original'][ $key ];
							if ( ! empty( $utm_params['extra'][ $key ] ) ) {
								$value .= '","' . $utm_params['extra'][ $key ];
							}
							return sprintf( '%s"%s":["%s"],', $carry, $key, $value );
						}, '' );
						$line = str_replace( '"Attributes":{', '"Attributes":{' . $utm_string, $line );
					}

					// Append line.
					$lines[] = $line;
				} else {
					continue;
				}
			}

			// Only POST data after we've collected requested number of rows or the file has reached the end.
			if ( count( $lines ) < $per_page && ! feof( $handle ) ) {
				continue;
			}

			// Handle delivery.
			do_action( "altis_analytics_demo_import_send_{$destination}", $lines, $time_stamp );

			// Store total processed.
			update_option( 'progress', $destination, $progress );

			// Have a sleep to avoid overloading ES.
			sleep( $sleep );

			// Reset lines.
			$lines = [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $handle );

		// Add a flag so we know when the import has run.
		update_option( 'success', $destination, true );

		// Process A/B test blocks.
		do_action( 'altis_post_ab_test_cron', 'xb', 1 );
	} catch ( Throwable $e ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		trigger_error( 'A problem occurred while importing analytics data. ' . $e->getMessage(), E_USER_WARNING );
		// Add a flag to check if the import failed for any reason.
		update_option( 'failed', $destination, $e->getMessage() );
	}

	update_option( 'running', $destination, false );

	wp_cache_flush();

	// Delete caches.
	if ( function_exists( 'wp_cache_delete_group' ) ) {
		wp_cache_delete_group( 'altis-audiences' );
	}
}

function setup_elasticsearch( int $max_session_start_time, int $min_session_start_time ) {
	$version = Utils\get_elasticsearch_version();

	// Create indexes for all the days we're adding data to.
	$mapping_file = version_compare( $version, '7', '>=' ) ? 'mapping.json' : 'mapping-6.json';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$mapping = file_get_contents( dirname( __FILE__, 2 ) . '/data/' . $mapping_file );
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
}

function import_elasticsearch( array $lines, int $time_stamp ) {
	$version = Utils\get_elasticsearch_version();

	// Post to correct day index.
	$index_name = date( 'Y-m-d', $time_stamp / 1000 );

	// ND-JSON metadata line to create a record.
	$metadata = '{"index":{}}';

	// Add the document to ES.
	$path = version_compare( $version, '7', '>=' ) ? '' : 'record/';
	$response = wp_remote_post(
		sprintf( '%s/analytics-%s/%s_bulk', Utils\get_elasticsearch_url(), $index_name, $path ),
		[
			'headers' => [
				'Content-Type' => 'application/x-ndjson',
			],
			// Must have an action metadata line followed by the record and end with a newline.
			'body' => "{$metadata}\n" . implode( "{$metadata}\n", $lines ) . "\n",
			'blocking' => true,
			'timeout' => 60,
		]
	);

	if ( is_wp_error( $response ) ) {
		throw new Exception( $response->get_error_message() );
	}

	if ( wp_remote_retrieve_response_code( $response ) > 299 ) {
		throw new Exception( wp_remote_retrieve_body( $response ) );
	}
}

function ch_format_date( $date ) {
	if ( ! $date ) {
		return $date;
	}
	if ( is_numeric( $date ) ) {
		$date = date( 'Y-m-d H:i:s.000', $date / 1000 );
	}
	$date = str_replace( 'T', ' ', $date );
	$date = str_replace( 'Z', '', $date );
	return $date;
}

function import_clickhouse( array $lines ) {

	$lines = array_map( function ( $line ) {
		$event = json_decode( $line, true );

		return json_encode( [
			'app_id' => ALTIS_ANALYTICS_PINPOINT_ID,
			'event_type' => $event['event_type'],
			'event_timestamp' => ch_format_date( $event['event_timestamp'] ),
			'attributes' => (object) array_map( function ( $att ) { return is_array( $att ) ? $att[0] : $att; }, $event['attributes'] ?? [] ),
			'metrics' => (object) array_map( function ( $att ) { return is_array( $att ) ? $att[0] : $att; }, $event['metrics'] ?? [] ),
			'endpoint_id' => $event['endpoint']['Id'],
			'endpoint_attributes' => (object) array_map( function ( $att ) { return is_array( $att ) ? $att : [ $att ]; }, $event['endpoint']['Attributes'] ?? [] ),
			'endpoint_metrics' => (object) array_map( function ( $att ) { return is_array( $att ) ? $att[0] : $att; }, $event['endpoint']['Metrics'] ?? [] ),
			'endpoint_address' => $event['endpoint']['Address'] ?? '',
			'endpoint_optout' => $event['endpoint']['OptOut'] ?? 'ALL',
			'app_version' => $event['endpoint']['Demographic']['AppVersion'] ?? '',
			'locale' => $event['endpoint']['Demographic']['Locale'] ?? '',
			'make' => $event['endpoint']['Demographic']['Make'] ?? '',
			'model' => $event['endpoint']['Demographic']['Model'] ?? '',
			'model_version' => $event['endpoint']['Demographic']['ModelVersion'] ?? '',
			'platform' => $event['endpoint']['Demographic']['Platform'] ?? '',
			'platform_version' => $event['endpoint']['Demographic']['PlatformVersion'] ?? '',
			'country' => $event['endpoint']['Location']['Country'] ?? '',
			'city' => $event['endpoint']['Location']['City'] ?? '',
			'postal_code' => $event['endpoint']['Location']['PostalCode'] ?? '',
			'region' => $event['endpoint']['Location']['Region'] ?? '',
			'user_id' => $event['endpoint']['User']['UserId'] ?? '',
			'user_attributes' => (object) array_map( function ( $att ) { return is_array( $att ) ? $att : [ $att ]; }, $event['endpoint']['User']['UserAttributes'] ?? [] ),
			'session_id' => $event['session']['session_id'] ?? '',
			'session_start' => ch_format_date( $event['session']['start_timestamp'] ?? null ),
			'session_stop' => ch_format_date( $event['session']['stop_timestamp'] ?? null ),
			'session_duration' => $event['session']['duration'] ?? null,
		] );
	}, $lines );

	$clickhouse_port = defined( 'ALTIS_CLICKHOUSE_PORT' ) ? ALTIS_CLICKHOUSE_PORT : 8123;
	$clickhouse_host = defined( 'ALTIS_CLICKHOUSE_HOST' ) ? ALTIS_CLICKHOUSE_HOST : 'clickhouse';
	$clickhouse_url = sprintf( '%s://%s:%s',
		strpos( $clickhouse_port, '443' ) !== false ? 'https' : 'http',
		$clickhouse_host,
		$clickhouse_port
	);

	$response = wp_remote_post(
		sprintf( '%s?query=%s',
			$clickhouse_url,
			urlencode( 'INSERT INTO default.analytics FORMAT JSONEachRow' )
		),
		[
			'body' => implode( "\n", $lines ),
			'blocking' => true,
			'timeout' => 60,
		]
	);

	if ( is_wp_error( $response ) ) {
		throw new Exception( $response->get_error_message() );
	}

	if ( wp_remote_retrieve_response_code( $response ) > 299 ) {
		throw new Exception( wp_remote_retrieve_body( $response ) );
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
		wp_delete_post( $existing[0]->ID, true );
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
 * Fetch the automatically created demo personalization block page.
 *
 * @return WP_Post|null
 */
function get_demo_personalization_block_page() : ?WP_Post {
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
 * Fetch the automatically created demo personalization block page.
 *
 * @return WP_Post|null
 */
function get_demo_ab_test_block_page() : ?WP_Post {
	$existing = new WP_Query(
		[
			'post_type' => 'page',
			'meta_key' => '_altis_analytics_demo_data_abtest',
			'posts_per_page' => 1,
		]
	);

	if ( ! $existing->found_posts ) {
		return null;
	}

	return $existing->posts[0];
}

/**
 * Create an Personalized Content Block for showcasing conversion goals.
 *
 * @return int
 */
function maybe_create_personalization_block() {
	$existing = get_demo_personalization_block_page();

	if ( $existing ) {
		return $existing->ID;
	}

	$audiences = get_demo_audiences();

	if ( empty( $audiences ) ) {
		// Something's wrong I can feel it.
		return 0;
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
<div class="wp-block-button"><a class="wp-block-button__link" href="#soundcloud" style="border-radius:12px" rel="noreferrer noopener">Go To SoundCloud</a></div>
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
		'post_title' => 'Insights Demo',
		'post_content' => $content,
	] );

	update_post_meta( $page_id, '_altis_analytics_demo_data', true );

	return $page_id;
}

/**
 * Generate a completed AB test for titles.
 *
 * @return void
 */
function maybe_create_completed_ab_test() {
	$page = get_demo_personalization_block_page();

	if ( ! $page ) {
		return;
	}

	$page_id = $page->ID;

	// Add A/B test experiment data.
	if ( ! function_exists( 'Altis\\Analytics\\Experiments\\update_ab_test_results_for_post' ) ) {
		return;
	}

	Experiments\update_ab_test_variants_for_post( 'titles', $page_id, [
		'Insights Demo',
		'Analytics Demo',
		'Experience Demo',
	] );
	Experiments\update_ab_test_traffic_percentage_for_post( 'titles', $page_id, 50 );
	Experiments\update_ab_test_start_time_for_post( 'titles', $page_id, Utils\milliseconds() - ( 14 * 24 * 60 * 60 * 1000 ) );
	Experiments\update_ab_test_end_time_for_post( 'titles', $page_id, Utils\milliseconds() + ( 14 * 24 * 60 * 60 * 1000 ) );

	// This will trigger the end of the test and a notification.
	$results = Experiments\analyse_ab_test_results( [
		[
			'cardinality#impressions' => [ 'value' => 3233 ],
			'filter#conversions' => [ 'doc_count' => 346 ],
		],
		[
			'cardinality#impressions' => [ 'value' => 2785 ],
			'filter#conversions' => [ 'doc_count' => 569 ],
		],
		[
			'cardinality#impressions' => [ 'value' => 3114 ],
			'filter#conversions' => [ 'doc_count' => 411 ],
		],
	], 'titles', $page_id );

	$results = wp_parse_args( $results, [
		'timestamp' => 0,
		'winning' => null,
		'winner' => null,
		'aggs' => [],
		'variants' => [],
	] );

	Experiments\update_ab_test_results_for_post( 'titles', $page_id, $results );
}

/**
 * Create an Experience Block for showcasing conversion goals.
 *
 * @return int
 */
function maybe_create_ab_test_block() {
	$existing = get_demo_ab_test_block_page();

	if ( $existing ) {
		return $existing->ID;
	}

	$content = '
<!-- wp:altis/ab-test {"clientId":"f7s8fgs9-e525-4fc0-b27d-66d677dd3008"} -->
<!-- wp:altis/ab-test-variant {"fallback":true,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>Hey! Why not check out our latest documentary, it\'s pretty good if we say so ourselves.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#default" style="border-radius:12px">WATCH NOW</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/ab-test-variant -->

<!-- wp:altis/ab-test-variant {"fallback":false,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>Watch our latest documentary and get 10% off your subscription!</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#discount" style="border-radius:12px">WATCH NOW</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/personalization-variant -->

<!-- wp:altis/ab-test-variant {"fallback":false,"goal":"click_any_link"} -->
<!-- wp:paragraph -->
<p>You won\'t BELIEVE how much our latest documentary will leave you SPEECHLESS.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"borderRadius":12} -->
<div class="wp-block-button"><a class="wp-block-button__link" href="#speechless" style="border-radius:12px" rel="noreferrer noopener">WATCH NOW</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:altis/ab-test-variant -->
<!-- /wp:altis/ab-test -->
';

	$page_id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'A/B Test Block Demo',
		'post_content' => $content,
	] );

	update_post_meta( $page_id, '_altis_analytics_demo_data_abtest', true );

	// Set the test start date back to 2 weeks ago.
	$xb_post = Blocks\get_block_post( 'f7s8fgs9-e525-4fc0-b27d-66d677dd3008' );
	if ( ! empty( $xb_post ) ) {
		Experiments\update_ab_test_start_time_for_post( 'xb', $xb_post->ID, Utils\milliseconds() - ( 14 * 24 * 60 * 60 * 1000 ) );
	}

	return $page_id;
}
