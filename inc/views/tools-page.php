<div class="wrap">
	<h1><?php esc_html_e( 'Analytics Tools' ); ?></h1>
	<div class="card">
		<form action="tools.php?page=analytics-demo" method="post">
			<h2><?php esc_html_e( 'Historical demo data import' ); ?></h2>
			<p><?php esc_html_e( 'This tool adds demo analytics data spread over the past 7 or 14 days to help with testing.' ); ?></p>
			<?php if ( ! get_option( 'altis_analytics_demo_import_running', false ) ) { ?>
				<p>
					<input class="button button-primary" type="submit" name="altis-analytics-demo-week" value="<?php esc_attr_e( 'Import 7 Days' ); ?>" />
					&nbsp;
					<input class="button button-primary" type="submit" name="altis-analytics-demo-fortnight" value="<?php esc_attr_e( 'Import 14 Days' ); ?>" />
				</p>
				<?php wp_nonce_field( 'altis-analytics-demo-import', '_altisnonce' ); ?>
			<?php } else { ?>
				<p class="msg success"><?php esc_html_e( 'The demo data is being imported. This may take a few minutes. Once completed the import button will become available again.' ); ?></p>
			<?php } ?>
		</form>
	</div>
</div>
