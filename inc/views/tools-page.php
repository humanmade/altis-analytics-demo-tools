<?php

namespace Altis\Analytics\Demo;

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'historical';

?>

<div class="wrap">
	<h1><?php esc_html_e( 'Analytics Tools' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<a href="?page=analytics-demo&tab=historical" class="nav-tab <?php echo $active_tab === 'historical' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Historical Import' ); ?>
		</a>
		<a href="?page=analytics-demo&tab=blocks" class="nav-tab <?php echo $active_tab === 'blocks' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Block Generator' ); ?>
		</a>
	</h2>

	<?php if ( $active_tab === 'historical' ) : ?>

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

	<?php endif; // historical tab ?>

	<?php if ( $active_tab === 'blocks' ) : ?>

		<div class="card" style="max-width:800px;">
			<h2><?php esc_html_e( 'Block Analytics Data Generator' ); ?></h2>
			<p><?php esc_html_e( 'Generate smooth, realistic analytics data for specific blocks to create polished demo screenshots.' ); ?></p>

			<?php if ( \Altis\Analytics\Demo\get_option( 'success', 'block', false ) ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'Block data generation completed successfully!' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( \Altis\Analytics\Demo\get_option( 'failed', 'block', false ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php esc_html_e( 'Generation failed: ' ); ?>
						<?php echo esc_html( \Altis\Analytics\Demo\get_option( 'failed', 'block', '' ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! \Altis\Analytics\Demo\get_option( 'running', 'block', false ) ) : ?>

				<form id="altis-block-generator-form" action="tools.php?page=analytics-demo&tab=blocks" method="post">
					<?php wp_nonce_field( 'altis-analytics-block-generator', '_blocknonce' ); ?>

					<h3><?php esc_html_e( 'Select Blocks' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Choose which blocks to generate analytics data for:' ); ?></p>

					<?php if ( empty( $available_blocks ) ) : ?>
						<p><em><?php esc_html_e( 'No synced patterns found. Create some blocks first.' ); ?></em></p>
					<?php else : ?>
						<p>
							<button type="button" class="button" data-select-type="ab-test"><?php esc_html_e( 'Select all A/B Tests' ); ?></button>
							<button type="button" class="button" data-select-type="personalization"><?php esc_html_e( 'Select all Personalization' ); ?></button>
							<button type="button" class="button" data-select-type="standard"><?php esc_html_e( 'Select all Standard' ); ?></button>
						</p>
						<div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;margin:10px 0;">
							<?php foreach ( $available_blocks as $block ) :
								$block_type = BlockGenerator\get_block_type( $block );
								$variants = BlockGenerator\get_block_variants( $block );
								$variant_count = count( $variants );
								$type_label = $block_type === 'ab-test' ? 'A/B Test' : ( $block_type === 'personalization' ? 'Personalized' : 'Standard' );
								?>
								<label style="display:block;padding:8px;border-bottom:1px solid #f0f0f0;">
									<input type="checkbox" name="block_ids[]" value="<?php echo esc_attr( $block->ID ); ?>" data-block-type="<?php echo esc_attr( $block_type ); ?>" data-variants="<?php echo esc_attr( $variant_count ); ?>" />
									<strong><?php echo esc_html( $block->post_title ); ?></strong>
									<span style="color:#666;font-size:12px;">
										(<?php echo esc_html( $type_label ); ?><?php echo $variant_count > 0 ? ', ' . esc_html( $variant_count ) . ' variants' : ', 1 variant'; ?>)
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Generation Options' ); ?></h3>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Days of Data' ); ?></th>
							<td>
								<input type="number" name="days" value="31" min="7" max="90" style="width:80px;" />
								<p class="description"><?php esc_html_e( 'Number of days of historical data (default: 31)' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Traffic Volume' ); ?></th>
							<td>
								<input type="range" name="volume_range" min="100" max="100000" step="500" value="1500" style="width:320px;" />
								<input type="number" name="volume" min="100" max="100000" step="500" value="1500" style="width:100px;" />
								<p class="description"><?php esc_html_e( 'Impressions per block over 31 days (scaled for chosen days).' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Traffic Shape' ); ?></th>
							<td>
								<select name="shape">
									<?php foreach ( BlockGenerator\get_traffic_shapes() as $shape_key => $shape_label ) : ?>
										<option value="<?php echo esc_attr( $shape_key ); ?>" <?php selected( $shape_key, 'growth' ); ?>><?php echo esc_html( $shape_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Choose how traffic trends over time.' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Realism Preset' ); ?></th>
							<td>
								<select name="preset">
									<?php foreach ( BlockGenerator\get_realism_presets() as $preset_key => $preset_label ) : ?>
										<option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $preset_key, 'balanced' ); ?>><?php echo esc_html( $preset_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Controls geo, referrer, device/browser, and returning/new distributions.' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Variant Performance (Optional)' ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Variant to win:' ); ?>
									<input type="text" name="winner_variant" placeholder="0, 1, 2..." style="width:80px;" />
								</label>
								<br />
								<label style="margin-top:8px;display:inline-block;">
									<?php esc_html_e( 'Performance lift:' ); ?>
									<input type="number" name="lift" value="15" min="0" max="100" style="width:80px;" /> %
								</label>
								<p class="description">
									<?php esc_html_e( 'Leave empty for equal performance. Enter variant ID (0=first, 1=second, etc.) to make it win by specified percentage.' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<div class="notice inline" style="margin:10px 0;">
						<p><strong><?php esc_html_e( 'Preview' ); ?></strong></p>
						<p>
							<?php esc_html_e( 'Estimated impressions:' ); ?> <span id="altis-block-estimate-impressions">0</span><br />
							<?php esc_html_e( 'Estimated conversions:' ); ?> <span id="altis-block-estimate-conversions">0</span><br />
							<?php esc_html_e( 'Estimated runtime:' ); ?> <span id="altis-block-estimate-runtime">0s</span>
						</p>
					</div>

					<p class="submit">
						<input type="submit" name="altis-analytics-block-generator-submit" class="button button-primary" value="<?php esc_attr_e( 'Generate Data' ); ?>" />
					</p>
				</form>

			<?php else : ?>

				<p class="description"><?php esc_html_e( 'Generating block analytics data. This may take a while...' ); ?></p>
				<progress id="altis-block-data-generation-progress" style="width:100%;height:30px;" max="<?php echo esc_attr( $total['block'] ); ?>" value="<?php echo esc_attr( $progress['block'] ); ?>"></progress>
				<p id="altis-block-progress-text" style="text-align:center;color:#666;">
					<?php echo esc_html( sprintf( '%d / %d impressions', $progress['block'], $total['block'] ) ); ?>
				</p>

				<script type="text/javascript">
					(function() {
						var progressBar = document.getElementById('altis-block-data-generation-progress');
						var progressText = document.getElementById('altis-block-progress-text');
						var total = progressBar.getAttribute('max');
						var progress = progressBar.getAttribute('value');

						if ( progress >= total ) {
							return;
						}

						var timer = setInterval(function() {
							fetch(ajaxurl + '?action=get_block_generation_progress&_wpnonce=<?php echo esc_js( $block_nonce ); ?>')
								.then(function(response) {
									return response.json();
								})
								.then(function(result) {
									if (!result.success) {
										clearInterval(timer);
										setTimeout(function() {
											window.location.href = window.location.href;
										}, 1000);
										return;
									}

									progressBar.setAttribute('max', result.data.total);
									progressBar.setAttribute('value', result.data.progress);
									progressText.textContent = result.data.progress + ' / ' + result.data.total + ' impressions';

									// Refresh when complete.
									if (result.data.progress >= result.data.total) {
										clearInterval(timer);
										setTimeout(function() {
											window.location.href = window.location.href;
										}, 1000);
									}
								});
						}, 3000);
					})();
				</script>

			<?php endif; ?>
		</div>

		<script type="text/javascript">
			(function() {
				var form = document.getElementById('altis-block-generator-form');
				if (!form) {
					return;
				}

				var volumeRange = form.querySelector('input[name="volume_range"]');
				var volumeInput = form.querySelector('input[name="volume"]');
				var daysInput = form.querySelector('input[name="days"]');
				var winnerInput = form.querySelector('input[name="winner_variant"]');
				var liftInput = form.querySelector('input[name="lift"]');
				var estimateImpressions = document.getElementById('altis-block-estimate-impressions');
				var estimateConversions = document.getElementById('altis-block-estimate-conversions');
				var estimateRuntime = document.getElementById('altis-block-estimate-runtime');

				function syncVolumeInputs(value) {
					volumeRange.value = value;
					volumeInput.value = value;
				}

				function formatRuntime(seconds) {
					if (seconds < 60) {
						return seconds + 's';
					}
					var minutes = Math.floor(seconds / 60);
					var remaining = seconds % 60;
					return minutes + 'm ' + remaining + 's';
				}

				function updateEstimates() {
					var checked = form.querySelectorAll('input[name="block_ids[]"]:checked');
					var blockCount = checked.length;
					var days = parseInt(daysInput.value || '31', 10);
					var volume = parseInt(volumeInput.value || '1500', 10);

					if (!blockCount || !days || !volume) {
						estimateImpressions.textContent = '0';
						estimateConversions.textContent = '0';
						estimateRuntime.textContent = '0s';
						return;
					}

					var impressionsPerBlock = Math.round(volume * (days / 31));
					if (impressionsPerBlock < 1) {
						impressionsPerBlock = 1;
					}
					var totalImpressions = impressionsPerBlock * blockCount;

					var baseRate = 0.05;
					var lift = parseInt(liftInput.value || '0', 10) / 100;
					var hasWinner = winnerInput.value !== '';
					var winnerShareSum = 0;

					if (hasWinner) {
						checked.forEach(function (checkbox) {
							var variants = parseInt(checkbox.getAttribute('data-variants') || '1', 10);
							if (variants < 1) {
								variants = 1;
							}
							winnerShareSum += (1 / variants);
						});
					}

					var winnerShare = hasWinner && blockCount ? (winnerShareSum / blockCount) : 0;
					var conversionRate = baseRate * (1 + (lift * winnerShare));
					var totalConversions = Math.round(totalImpressions * conversionRate);

					var totalEvents = totalImpressions + totalConversions;
					var batches = Math.ceil(totalEvents / 400);
					var runtimeSeconds = batches * 2;

					estimateImpressions.textContent = totalImpressions.toLocaleString();
					estimateConversions.textContent = totalConversions.toLocaleString();
					estimateRuntime.textContent = formatRuntime(runtimeSeconds);
				}

				form.addEventListener('click', function (event) {
					var target = event.target;
					if (target && target.getAttribute('data-select-type')) {
						var type = target.getAttribute('data-select-type');
						var checkboxes = form.querySelectorAll('input[name="block_ids[]"]');
						checkboxes.forEach(function (checkbox) {
							if (checkbox.getAttribute('data-block-type') === type) {
								checkbox.checked = true;
							}
						});
						updateEstimates();
					}
				});

				form.addEventListener('change', function (event) {
					if (event.target === volumeRange) {
						syncVolumeInputs(volumeRange.value);
					}
					if (event.target === volumeInput) {
						syncVolumeInputs(volumeInput.value);
					}
					updateEstimates();
				});

				updateEstimates();
			})();
		</script>

	<?php endif; // blocks tab ?>

</div>
