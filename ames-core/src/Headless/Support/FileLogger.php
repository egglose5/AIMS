<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Support;

final class FileLogger {
	private string $logDirectory;

	public function __construct( string $logDirectory ) {
		$this->logDirectory = rtrim( $logDirectory, DIRECTORY_SEPARATOR );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $event, array $context = array() ): void {
		$this->write( 'info', $event, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $event, array $context = array() ): void {
		$this->write( 'error', $event, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function write( string $level, string $event, array $context ): void {
		if ( ! is_dir( $this->logDirectory ) ) {
			return;
		}

		$payload = json_encode(
			array(
				'logged_at' => gmdate( 'c' ),
				'level'     => $level,
				'event'     => $event,
				'context'   => $context,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $payload ) {
			return;
		}

		@file_put_contents( $this->logDirectory . DIRECTORY_SEPARATOR . 'aims-core.log', $payload . PHP_EOL, FILE_APPEND );
	}
}
