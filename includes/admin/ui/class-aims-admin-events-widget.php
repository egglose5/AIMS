<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Admin_Events_Widget extends AIMS_Admin_Widget {
	public function render(): void {
		$outline = $this->get_data( 'outline', array() );
		?>
		<div class="aims-widget aims-events-widget">
			<p>Events are the operational bridge between Square sales, runtime assignments, and physical inventory commitment.</p>
			<ul class="aims-list-disc">
				<?php foreach ( $outline as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
