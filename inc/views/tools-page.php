<?php

namespace Altis\Analytics\Demo;

?>

<div class="wrap">
	<h1><?php esc_html_e( 'Analytics Tools' ); ?></h1>

	<?php foreach ( $destinations as $destination => $label ) { ?>

	<div class="card" style="float:left;margin:20px 20px 0 0;overflow:hidden;">
		<form action="tools.php?page=analytics-demo" method="post">
			<input type="hidden" name="destination" value="<?php echo esc_attr( $destination ); ?>" />
			<h2><?php esc_html_e( sprintf( 'Historical demo data import to %s', $label ) ); ?></h2>
			<p><?php esc_html_e( 'This tool adds demo analytics data spread over the past 7 or 14 days to help with testing.' ); ?></p>
			<?php if ( get_option( 'success', $destination, false ) ) { ?>
				<p class="message success"><?php esc_html_e( 'The import completed successfully.' ); ?></p>
				<p><a href="<?php echo esc_attr( get_edit_post_link( $personalized_page ) ); ?>"><?php esc_html_e( 'A sample Personalized Content Block can be viewed here.' ); ?></a></p>
				<p><a href="<?php echo esc_attr( get_edit_post_link( $ab_test_page ) ); ?>"><?php esc_html_e( 'A sample A/B Test Block can be viewed here.' ); ?></a></p>
			<?php } ?>
			<?php if ( get_option( 'failed', $destination, false ) ) { ?>
				<p class="message error">
					<?php esc_html_e( 'The import failed' ); ?>:
					<?php echo esc_html( get_option( 'failed', $destination, '' ) ); ?>
				</p>
			<?php } ?>
			<?php if ( ! get_option( 'running', $destination, false ) ) { ?>
				<p>
					<input class="button button-primary" type="submit" name="altis-analytics-demo-week" value="<?php esc_attr_e( 'Import 7 Days' ); ?>" />
					&nbsp;
					<input class="button button-primary" type="submit" name="altis-analytics-demo-fortnight" value="<?php esc_attr_e( 'Import 14 Days' ); ?>" />
				</p>
				<?php wp_nonce_field( 'altis-analytics-demo-import', '_altisnonce' ); ?>
				<p>
					<?php esc_html_e( 'Use the following settings if you experience errors. A lower number of items per request will make the process take longer but is easier on Elasticsearch, and a higher wait time between requests allows Elasticsearch more time to process events.' ); ?>
				<p>
					<label><input style="width:5rem;" type="number" step="50" min="50" name="altis-analytics-demo-per-page" value="<?php echo intval( DEFAULT_PER_PAGE ); ?>" /> <?php esc_html_e( 'Events per request' ); ?></label>
				</p>
				<p>
					<label><input style="width:5rem;" type="number" step="1" min="1" name="altis-analytics-demo-sleep" value="<?php echo intval( DEFAULT_SLEEP ); ?>" /> <?php esc_html_e( 'Seconds between requests' ); ?></label>
				</p>
			<?php } else { ?>
				<p class="description"><?php esc_html_e( 'The demo data is being imported. This may take a while.' ); ?></p>
				<progress id="altis-demo-data-import-progress-<?php echo esc_attr( $destination ); ?>" style="width:100%" max="<?php echo esc_attr( $total[ $destination ] ); ?>" value="<?php echo esc_attr( $progress[ $destination ] ); ?>"></progress>
				<script type="text/javascript">
					(function() {
						var progressBar = document.getElementById('altis-demo-data-import-progress-<?php echo esc_attr( $destination ); ?>');
						var total = progressBar.getAttribute( 'max' );
						var progress = progressBar.getAttribute( 'value' );
						if ( progress >= total ) {
							return;
						}
						var timer = setInterval( function() {
							fetch( ajaxurl + '?action=get_analytics_demo_data_import_progress&destination=<?php echo esc_js( $destination ); ?>&_wpnonce=<?php echo esc_js( $nonce ); ?>' )
								.then( function ( response ) {
									return response.json();
								} )
								.then( function ( result ) {
									if ( ! result.success ) {
										clearInterval( timer );
										setTimeout( function () {
											window.location.href = window.location.href;
										}, 1000 );
									}
									progressBar.setAttribute( 'max', result.data.total );
									progressBar.setAttribute( 'value', result.data.progress );
									// Refresh the page when complete.
									if ( result.data.progress >= result.data.total ) {
										clearInterval( timer );
										setTimeout( function () {
											window.location.href = window.location.href;
										}, 1000 );
									}
								} );
						}, 3000 );
					})();
				</script>
			<?php } ?>
		</form>
	</div>

	<?php } ?>

</div>
