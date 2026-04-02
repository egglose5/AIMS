<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Audit_Log_Service {
	private const DEFAULT_SUBDIRECTORY = 'aims/audit';

	private $base_dir;

	public function __construct( string $base_dir = '' ) {
		$this->base_dir = '' !== $base_dir ? rtrim( $base_dir, '\\/' ) : '';
	}

	public function record_action( string $capability_key, string $action_key, string $reference_id, array $context = array() ): bool {
		$timestamp = (string) ( $context['ts'] ?? '' );
		if ( '' === $timestamp && function_exists( 'current_time' ) ) {
			$timestamp = (string) current_time( 'mysql' );
		}

		if ( '' === $timestamp ) {
			$timestamp = gmdate( 'Y-m-d H:i:s' );
		}

		$entry = array(
			'ts'             => $timestamp,
			'user_id'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'capability_key' => sanitize_key( $capability_key ),
			'action_key'     => sanitize_key( $action_key ),
			'reference_id'   => sanitize_text_field( $reference_id ),
			'status'         => sanitize_key( (string) ( $context['status'] ?? 'success' ) ),
			'surface'        => sanitize_key( (string) ( $context['surface'] ?? AIMS_Capabilities::SURFACE_WP_ADMIN ) ),
		);

		return $this->append_entry( $entry );
	}

	public function get_rows( array $filters = array(), int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$rows  = array();

		foreach ( $this->get_log_file_paths() as $path ) {
			$entries = $this->read_log_file( $path );

			foreach ( $entries as $entry ) {
				if ( ! $this->matches_filters( $entry, $filters ) ) {
					continue;
				}

				$rows[] = $entry;

				if ( count( $rows ) >= $limit ) {
					return $rows;
				}
			}
		}

		return $rows;
	}

	public function get_log_directory(): string {
		if ( '' !== $this->base_dir ) {
			return $this->base_dir;
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
			if ( '' !== $basedir ) {
				return trailingslashit( untrailingslashit( $basedir ) ) . self::DEFAULT_SUBDIRECTORY;
			}
		}

		return trailingslashit( untrailingslashit( AIMS_PLUGIN_PATH ) ) . self::DEFAULT_SUBDIRECTORY;
	}

	private function append_entry( array $entry ): bool {
		$directory = $this->get_log_directory();
		if ( ! $this->ensure_directory( $directory ) ) {
			return false;
		}

		$json = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( '' === (string) $json ) {
			return false;
		}

		$bytes = @file_put_contents(
			$this->get_log_file_path( $entry['ts'] ),
			$json . PHP_EOL,
			FILE_APPEND | LOCK_EX
		);

		return false !== $bytes;
	}

	private function get_log_file_path( string $timestamp ): string {
		$date = substr( preg_replace( '/[^0-9\-: ]/', '', $timestamp ), 0, 10 );
		if ( ! preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}

		return trailingslashit( $this->get_log_directory() ) . $date . '.jsonl';
	}

	private function ensure_directory( string $directory ): bool {
		if ( is_dir( $directory ) ) {
			return true;
		}

		return @mkdir( $directory, 0775, true );
	}

	private function get_log_file_paths(): array {
		$directory = $this->get_log_directory();
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$paths = glob( trailingslashit( $directory ) . '*.jsonl' );
		if ( false === $paths ) {
			return array();
		}

		rsort( $paths, SORT_STRING );

		return array_values( $paths );
	}

	private function read_log_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return array();
		}

		$rows = array();
		for ( $index = count( $lines ) - 1; $index >= 0; --$index ) {
			$line  = trim( (string) $lines[ $index ] );
			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$rows[] = array(
				'ts'             => (string) ( $entry['ts'] ?? '' ),
				'user_id'        => (int) ( $entry['user_id'] ?? 0 ),
				'capability_key' => sanitize_key( (string) ( $entry['capability_key'] ?? '' ) ),
				'action_key'     => sanitize_key( (string) ( $entry['action_key'] ?? '' ) ),
				'reference_id'   => sanitize_text_field( (string) ( $entry['reference_id'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $entry['status'] ?? '' ) ),
				'surface'        => sanitize_key( (string) ( $entry['surface'] ?? '' ) ),
			);
		}

		return $rows;
	}

	private function matches_filters( array $entry, array $filters ): bool {
		$user_id = isset( $filters['user_id'] ) ? (int) $filters['user_id'] : 0;
		if ( $user_id > 0 && $user_id !== (int) ( $entry['user_id'] ?? 0 ) ) {
			return false;
		}

		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		if ( '' !== $status && $status !== (string) ( $entry['status'] ?? '' ) ) {
			return false;
		}

		$action_key = isset( $filters['action_key'] ) ? sanitize_key( (string) $filters['action_key'] ) : '';
		if ( '' !== $action_key && $action_key !== (string) ( $entry['action_key'] ?? '' ) ) {
			return false;
		}

		$search = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		if ( '' === $search ) {
			return true;
		}

		$haystack = strtolower(
			implode(
				' ',
				array(
					(string) ( $entry['reference_id'] ?? '' ),
					(string) ( $entry['action_key'] ?? '' ),
					(string) ( $entry['capability_key'] ?? '' ),
				)
			)
		);

		return false !== strpos( $haystack, $search );
	}
}
