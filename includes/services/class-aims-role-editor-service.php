<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Role_Editor_Service {
	public function get_page_model( string $editing_role_slug = '' ): array {
		$editing_role_slug = sanitize_key( $editing_role_slug );
		$editing_role      = AIMS_Capabilities::get_role_definition( $editing_role_slug );
		$selected_caps     = array_keys( (array) ( $editing_role['caps'] ?? array() ) );

		return array(
			'templates'         => AIMS_Capabilities::get_role_templates(),
			'custom_roles'      => AIMS_Capabilities::get_custom_role_registry(),
			'editing_role'      => is_array( $editing_role ) ? $editing_role : null,
			'editing_role_slug' => $editing_role_slug,
			'supported_surfaces' => AIMS_Capabilities::get_supported_surfaces(),
			'capability_groups' => $this->build_capability_groups( $selected_caps ),
		);
	}

	public function save_role( array $request ): array {
		$role_name    = sanitize_text_field( (string) ( $request['role_name'] ?? '' ) );
		$role_slug    = sanitize_key( (string) ( $request['role_slug'] ?? '' ) );
		$template_key = sanitize_key( (string) ( $request['template_key'] ?? '' ) );
		$role_caps    = $this->normalize_selected_caps( (array) ( $request['role_caps'] ?? array() ) );

		if ( '' === $role_slug && '' !== $role_name ) {
			$role_slug = 'aims_custom_' . sanitize_key( sanitize_title( $role_name ) );
		}

		return AIMS_Capabilities::create_or_update_custom_role(
			$role_slug,
			$role_name,
			$template_key,
			$role_caps
		);
	}

	public function delete_role( string $role_slug ): bool {
		return AIMS_Capabilities::delete_custom_role( $role_slug );
	}

	private function build_capability_groups( array $selected_caps ): array {
		$selected_caps = array_fill_keys( array_map( 'sanitize_key', $selected_caps ), true );
		$groups        = array();

		foreach ( AIMS_Capabilities::get_capability_groups() as $group_key => $group ) {
			$cap_rows = array();
			foreach ( (array) ( $group['caps'] ?? array() ) as $cap ) {
				$cap = sanitize_key( (string) $cap );
				if ( '' === $cap || 'read' === $cap ) {
					continue;
				}

				$cap_rows[] = array(
					'cap'     => $cap,
					'label'   => $this->humanize_capability_label( $cap ),
					'checked' => ! empty( $selected_caps[ $cap ] ),
				);
			}

			if ( empty( $cap_rows ) ) {
				continue;
			}

			$groups[ $group_key ] = array(
				'label'       => (string) ( $group['label'] ?? ucfirst( $group_key ) ),
				'description' => (string) ( $group['description'] ?? '' ),
				'stack_level' => (string) ( $group['stack_level'] ?? $group_key ),
				'caps'        => $cap_rows,
			);
		}

		return $groups;
	}

	private function normalize_selected_caps( array $caps ): array {
		$available = array_fill_keys( AIMS_Capabilities::get_caps(), true );
		$selected  = array();

		foreach ( $caps as $cap ) {
			$cap = sanitize_key( (string) $cap );
			if ( '' === $cap || empty( $available[ $cap ] ) ) {
				continue;
			}

			$selected[ $cap ] = true;
		}

		return $selected;
	}

	private function humanize_capability_label( string $cap ): string {
		$label = str_replace(
			array( 'aims_resp_', 'manage_aims_', 'view_aims_', 'review_aims_', 'run_aims_', 'bypass_aims_' ),
			'',
			$cap
		);
		$label = str_replace( array( 'aims_', '_' ), array( '', ' ' ), $label );

		return ucwords( trim( $label ) );
	}
}
