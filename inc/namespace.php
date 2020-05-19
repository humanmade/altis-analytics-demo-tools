<?php
/**
 * Demo data importer.
 */

namespace Altis\Analytics\Demo;

use Exception;
use function Altis\Analytics\Utils\get_elasticsearch_url;

function setup() {
	add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\\handle_request' );
	add_action( 'altis_analytics_import_demo_data', __NAMESPACE__ . '\\import_data' );
}

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

function tools_page() {
	include __DIR__ . '/views/tools-page.php';
}

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

	update_option( 'altis_analytics_demo_import_running', true );
	wp_schedule_single_event( time(), 'altis_analytics_import_demo_data', [ $time_range ] );
}

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

		// Get replacement reference with no trailingslash.
		$home_url = home_url();

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
				sprintf( '%s/analytics-%s', get_elasticsearch_url(), $index_name ),
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

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			if ( empty( $line ) ) {
				continue;
			}

			// Get session ID - we only increment the time stamp when a new one is encountered
			// so the data is at least somewhat reasonable.
			preg_match( '/"session":\["([a-z0-9-]+)"\]/', $line, $matches );
			if ( ! isset( $matches[1] ) ) {
				continue;
			}

			if ( isset( $sessions[ $matches[1] ] ) ) {
				$time_stamp = $sessions[ $matches[1] ]['time_stamp'];
				$session_id = $sessions[ $matches[1] ]['session_id'];
			} else {
				// Calculate session start time using weighted random numbers so hours are useful.
				$day = get_random_weighted_element( [ 1, 1, 1, 1, 1, 1, 1 ] );
				$hour = get_random_weighted_element( array_reverse( [ 1, 1, 1, 2, 2, 3, 3, 5, 8, 9, 6, 5, 10, 12, 7, 4, 5, 7, 10, 12, 14, 10, 8, 3 ] ) );
				$time_stamp = $max_session_start_time - ( $day * DAY_IN_SECONDS * 1000 ) - ( $hour * HOUR_IN_SECONDS * 1000 );

				// Modulate session ID so reimports dont produce weird looking results.
				$session_id = wp_generate_uuid4();

				// Store to group session events together.
				$sessions[ $matches[1] ] = [
					'time_stamp' => $time_stamp,
					'session_id' => $session_id,
				];
			}

			// Replace session ID.
			$line = preg_replace( '/"session":\["([a-z0-9-]+)"\]/', '"session":["' . $session_id . '"]', $line );

			// Replace event timestamp - spread this out over time.
			$line = preg_replace( '/"event_timestamp":\d+/', '"event_timestamp":' . $time_stamp, $line );

			// Replace URL.
			$line = str_replace( 'https://altis-dev.altis.dev', $home_url, $line );

			// Post to correct day index.
			$index_name = date( 'Y-m-d', $time_stamp / 1000 );

			// Add the document to ES.
			wp_remote_post(
				sprintf( '%s/analytics-%s/record/', get_elasticsearch_url(), $index_name ),
				[
					'headers' => [
						'Content-Type' => 'application/json',
					],
					'body' => $line,
				]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $handle );
	} catch ( Exception $e ) {
		trigger_error( 'A problem occurred while importing analytics data. ' . $e->getMessage(), E_USER_ERROR );
	}

	delete_option( 'altis_analytics_demo_import_running' );
}

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