<div class="wrap">
	<h1><?php esc_html_e( 'Analytics Tools' ); ?></h1>
	<div class="card">
		<form action="tools.php?page=analytics-demo" method="post">
			<h2><?php esc_html_e( 'Historical demo data import' ); ?></h2>
			<p><?php esc_html_e( 'This tool adds demo analytics data spread over the past 7 or 14 days to help with testing.' ); ?></p>
			<?php if ( get_option( 'altis_analytics_demo_import_success', false ) ) { ?>
				<p class="message success"><?php esc_html_e( 'The import completed successfully.' ); ?></p>
			<?php } ?>
			<?php if ( get_option( 'altis_analytics_demo_import_failed', false ) ) { ?>
				<p class="message error">
					<?php esc_html_e( 'The import failed' ); ?>:
					<?php echo esc_html( get_option( 'altis_analytics_demo_import_failed', '' ) ); ?>
				</p>
			<?php } ?>
			<?php if ( ! get_option( 'altis_analytics_demo_import_running', false ) ) { ?>
				<p>
					<input class="button button-primary" type="submit" name="altis-analytics-demo-week" value="<?php esc_attr_e( 'Import 7 Days' ); ?>" />
					&nbsp;
					<input class="button button-primary" type="submit" name="altis-analytics-demo-fortnight" value="<?php esc_attr_e( 'Import 14 Days' ); ?>" />
				</p>
				<?php wp_nonce_field( 'altis-analytics-demo-import', '_altisnonce' ); ?>
			<?php } else { ?>
				<p class="description"><?php esc_html_e( 'The demo data is being imported. This may take a while.' ); ?></p>
				<progress id="altis-demo-data-import-progress" style="width:100%" max="<?php echo esc_attr( $total ); ?>" value="<?php echo esc_attr( $progress ); ?>"></progress>
				<script type="text/javascript">
					(function() {
						var progressBar = document.getElementById('altis-demo-data-import-progress');
						var total = progressBar.getAttribute( 'max' );
						var progress = progressBar.getAttribute( 'value' );
						if ( progress >= total ) {
							return;
						}
						var timer = setInterval( function() {
							fetch( ajaxurl + '?action=get_analytics_demo_data_import_progress&_wpnonce=<?php echo esc_js( $nonce ); ?>' )
								.then( function ( response ) {
									return response.json();
								} )
								.then( function ( result ) {
									if ( ! result.success ) {
										console.log( data );
										clearInterval( timer );
									}
									progressBar.setAttribute( 'max', result.data.total );
									progressBar.setAttribute( 'value', result.data.progress );
									// Refresh the page when complete.
									if ( result.data.progress >= result.data.total ) {
										clearInterval( timer );
										window.location.href= window.location.href;
									}
								} );
						}, 1000 );
					})();
				</script>
			<?php } ?>
		</form>
	</div>
</div>
