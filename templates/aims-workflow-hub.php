<?php
/**
 * Template Name: AIMS Workflow Hub
 * Description: Theme-editor-friendly landing page for AIMS workflow shortcodes and widgets.
 */

get_header();
?>

<div id="primary" class="content-area aims-workflow-hub-page">
	<main id="main" class="site-main">
		<?php echo do_shortcode( '[aims_workflow_hub]' ); ?>
	</main>
</div>

<?php
get_footer();
