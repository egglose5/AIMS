<?php
/**
 * Template Name: AIMS Sample Portal
 * Description: A theme-friendly sample portal demonstrating AIMS elements, widgets, and barcode scanning.
 */

get_header(); ?>

<div id="primary" class="content-area aims-sample-portal">
	<main id="main" class="site-main">

		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<header class="entry-header">
				<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
			</header>

			<div class="entry-content">
				<?php
				// 1. Demonstrate Widgets
				echo '<h2>' . esc_html__( 'System Status Widgets', 'ai-man-sys' ) . '</h2>';

				// Core Status Widget
				if ( class_exists( 'AIMS_Admin_Status_Widget' ) ) {
					$client = AIMS_Headless_Api_Client::from_plugin_options();
					$manifest = $client->get_manifest();
					$status_widget = new AIMS_Admin_Status_Widget( new AIMS_Admin_Meta_Object( array( 'manifest' => $manifest ) ) );
					echo '<div class="aims-portal-widget-container">';
					$status_widget->render();
					echo '</div>';
				}

				// 2. Demonstrate Elements & Barcode Scanning
				echo '<h2 style="margin-top: 40px;">' . esc_html__( 'Interactive Elements', 'ai-man-sys' ) . '</h2>';
				echo '<p>' . esc_html__( 'The following fields demonstrate mobile-friendly shortcodes and barcode scanning capabilities.', 'ai-man-sys' ) . '</p>';

				?>
				<div class="aims-portal-form" style="background: #f9f9f9; padding: 20px; border: 1px solid #eee; border-radius: 4px;">
					<div class="aims-form-row" style="margin-bottom: 20px;">
						<label for="portal-sku-scan" style="display: block; margin-bottom: 5px; font-weight: bold;"><?php esc_html_e( 'Scan Product SKU:', 'ai-man-sys' ); ?></label>
						<?php
						$sku_input = new AIMS_Admin_Input_Element( array(
							'id'          => 'portal-sku-scan',
							'name'        => 'sku',
							'placeholder' => __( 'Scan or enter SKU', 'ai-man-sys' ),
							'scan'        => true,
							'class'       => 'aims-portal-input',
							'attributes'  => array( 'style' => 'padding: 8px; width: 250px;' )
						) );
						echo $sku_input->render();
						?>
					</div>

					<div class="aims-form-row">
						<label for="portal-bucket-scan" style="display: block; margin-bottom: 5px; font-weight: bold;"><?php esc_html_e( 'Scan Bucket Code:', 'ai-man-sys' ); ?></label>
						<?php
						$bucket_input = new AIMS_Admin_Input_Element( array(
							'id'          => 'portal-bucket-scan',
							'name'        => 'bucket_code',
							'placeholder' => __( 'Scan or enter Bucket', 'ai-man-sys' ),
							'scan'        => true,
							'class'       => 'aims-portal-input',
							'attributes'  => array( 'style' => 'padding: 8px; width: 250px;' )
						) );
						echo $bucket_input->render();
						?>
					</div>
				</div>

				<?php
				// 3. Demonstrate Shortcode directly
				echo '<h2 style="margin-top: 40px;">' . esc_html__( 'Direct Shortcode Usage', 'ai-man-sys' ) . '</h2>';
				echo '<p>' . esc_html__( 'You can also use the shortcode [aims_barcode_scan target="my-input-id"] anywhere.', 'ai-man-sys' ) . '</p>';
				?>
				<input type="text" id="manual-target" placeholder="Manual target field" />
				<?php echo do_shortcode( '[aims_barcode_scan target="manual-target" label="Camera Scan"]' ); ?>

			</div><!-- .entry-content -->
		</article>

	</main><!-- #main -->
</div><!-- #primary -->

<?php
get_footer();
