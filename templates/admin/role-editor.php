<?php

$notice_status     = (string) ( $aims_role_editor['notice_status'] ?? '' );
$notice_message    = (string) ( $aims_role_editor['notice_message'] ?? '' );
$templates         = (array) ( $aims_role_editor['templates'] ?? array() );
$custom_roles      = (array) ( $aims_role_editor['custom_roles'] ?? array() );
$editing_role      = (array) ( $aims_role_editor['editing_role'] ?? array() );
$capability_groups = (array) ( $aims_role_editor['capability_groups'] ?? array() );
$save_action_url   = (string) ( $aims_role_editor['save_action_url'] ?? admin_url( 'admin-post.php' ) );
$delete_action_url = (string) ( $aims_role_editor['delete_action_url'] ?? admin_url( 'admin-post.php' ) );

$editing_role_name   = (string) ( $editing_role['role_name'] ?? '' );
$editing_role_slug   = (string) ( $editing_role['role_slug'] ?? '' );
$editing_template    = (string) ( $editing_role['template_key'] ?? '' );
$is_editing_existing = '' !== $editing_role_slug;

if ( '' !== $notice_status && '' !== $notice_message ) {
	$notice_class = 'success' === $notice_status ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
	echo '<div class="' . esc_attr( $notice_class ) . '"><p>' . esc_html( $notice_message ) . '</p></div>';
}

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">Built-in Role Templates</h2>';
echo '<p>These ship with AIMS and act as templates only. Use them to create custom roles on a customer site, then edit the custom copy instead of the built-in role.</p>';
if ( empty( $templates ) ) {
	echo '<p>No templates are available.</p>';
} else {
	echo '<table class="widefat fixed striped"><thead><tr><th>Template</th><th>Slug</th><th>Description</th><th>Subtypes</th></tr></thead><tbody>';
	foreach ( $templates as $template ) {
		echo '<tr>';
		echo '<td><strong>' . esc_html( (string) ( $template['role_name'] ?? '' ) ) . '</strong></td>';
		echo '<td><code>' . esc_html( (string) ( $template['role_slug'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( (string) ( $template['description'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', array_map( 'sanitize_text_field', (array) ( $template['person_subtypes'] ?? array() ) ) ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}
echo '</div>';

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">' . esc_html( $is_editing_existing ? 'Edit Custom Role' : 'Create Custom Role' ) . '</h2>';
echo '<form method="post" action="' . esc_url( $save_action_url ) . '">';
wp_nonce_field( 'aims_role_editor_save' );
echo '<input type="hidden" name="action" value="aims_role_editor_save" />';
echo '<table class="form-table" role="presentation">';
echo '<tr><th scope="row"><label for="role_name">Role Name</label></th><td><input type="text" class="regular-text" id="role_name" name="role_name" value="' . esc_attr( $editing_role_name ) . '" required /></td></tr>';
echo '<tr><th scope="row"><label for="role_slug">Role Slug</label></th><td><input type="text" class="regular-text code" id="role_slug" name="role_slug" value="' . esc_attr( $editing_role_slug ) . '" ' . ( $is_editing_existing ? 'readonly' : '' ) . ' /><p class="description">Use an AIMS-prefixed slug like <code>aims_custom_vendor_plus</code>. If left blank, AIMS will generate one from the role name.</p></td></tr>';
echo '<tr><th scope="row"><label for="template_key">Template</label></th><td><select id="template_key" name="template_key">';
echo '<option value="">No template</option>';
foreach ( $templates as $template_key => $template ) {
	$selected = selected( $editing_template, (string) $template_key, false );
	echo '<option value="' . esc_attr( (string) $template_key ) . '"' . $selected . '>' . esc_html( (string) ( $template['role_name'] ?? $template_key ) ) . '</option>';
}
echo '</select><p class="description">Templates contribute their default capability set and AIMS person subtype metadata.</p></td></tr>';
echo '</table>';

echo '<h3>Capabilities</h3>';
echo '<p>Responsibilities are now first-class capabilities, so anything checked here can be granted by the custom role editor and by other capability-based role plugins.</p>';
foreach ( $capability_groups as $group ) {
	echo '<fieldset style="margin:16px 0; padding:12px 16px; border:1px solid #dcdcde;">';
	echo '<legend style="padding:0 6px;"><strong>' . esc_html( (string) ( $group['label'] ?? 'Capabilities' ) ) . '</strong></legend>';
	foreach ( (array) ( $group['caps'] ?? array() ) as $cap_row ) {
		$checked = ! empty( $cap_row['checked'] ) ? ' checked="checked"' : '';
		echo '<label style="display:block; margin:8px 0;">';
		echo '<input type="checkbox" name="role_caps[]" value="' . esc_attr( (string) ( $cap_row['cap'] ?? '' ) ) . '"' . $checked . ' /> ';
		echo esc_html( (string) ( $cap_row['label'] ?? '' ) );
		echo ' <code>' . esc_html( (string) ( $cap_row['cap'] ?? '' ) ) . '</code>';
		echo '</label>';
	}
	echo '</fieldset>';
}

echo '<p><button type="submit" class="button button-primary">' . esc_html( $is_editing_existing ? 'Save Role' : 'Create Role' ) . '</button></p>';
echo '</form>';
echo '</div>';

echo '<div class="postbox" style="padding:16px; margin:16px 0;">';
echo '<h2 style="margin-top:0;">Custom AIMS Roles</h2>';
if ( empty( $custom_roles ) ) {
	echo '<p>No custom AIMS roles have been created yet.</p>';
} else {
	echo '<table class="widefat fixed striped"><thead><tr><th>Role</th><th>Slug</th><th>Template</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $custom_roles as $role_slug => $role ) {
		$edit_url = add_query_arg(
			array(
				'page'     => AIMS_Role_Editor_Page::PAGE_SLUG,
				'role_slug' => $role_slug,
			),
			admin_url( 'admin.php' )
		);

		echo '<tr>';
		echo '<td><strong>' . esc_html( (string) ( $role['role_name'] ?? $role_slug ) ) . '</strong></td>';
		echo '<td><code>' . esc_html( (string) $role_slug ) . '</code></td>';
		echo '<td>' . esc_html( (string) ( $role['template_key'] ?? '' ) ) . '</td>';
		echo '<td>';
		echo '<a class="button button-secondary" href="' . esc_url( $edit_url ) . '">Edit</a> ';
		echo '<form method="post" action="' . esc_url( $delete_action_url ) . '" style="display:inline-block; margin-left:8px;">';
		wp_nonce_field( 'aims_role_editor_delete' );
		echo '<input type="hidden" name="action" value="aims_role_editor_delete" />';
		echo '<input type="hidden" name="role_slug" value="' . esc_attr( (string) $role_slug ) . '" />';
		echo '<button type="submit" class="button button-link-delete">Delete</button>';
		echo '</form>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}
echo '</div>';
