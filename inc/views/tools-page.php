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
				<p class="description"><?php esc_html_e( 'The demo data is being imported. This will take a few minutes. You can refresh the page periodically to check progress.' ); ?></p>
			<?php } ?>
		</form>
	</div>
</div>
