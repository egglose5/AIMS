<?php

declare( strict_types=1 );

namespace AIMS\Core\Clients;

final class NativeHttpTransport implements HttpTransportInterface {
	public function send( string $method, string $url, array $options = array() ): array {
		$method  = strtoupper( $method );
		$headers = $this->normalizeHeaders( $options['headers'] ?? array() );
		$body    = $options['body'] ?? null;
		$query   = $options['query'] ?? array();
		$timeout  = (int) ( $options['timeout'] ?? 30 );

		if ( ! empty( $query ) && is_array( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}

		$headers['accept'] = $headers['accept'] ?? 'application/json';

		if ( is_array( $body ) ) {
			$body = json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$headers['content-type'] = $headers['content-type'] ?? 'application/json';
		}

		if ( function_exists( 'curl_init' ) ) {
			return $this->sendWithCurl( $method, $url, $headers, $body, $timeout );
		}

		return $this->sendWithStreams( $method, $url, $headers, $body, $timeout );
	}

	private function sendWithCurl( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST   => $method,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_HTTPHEADER     => $this->formatHeaderLines( $headers ),
				CURLOPT_HEADER         => true,
			)
		);

		if ( null !== $body && '' !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		}

		$response = curl_exec( $ch );
		$error    = curl_error( $ch );
		$info     = curl_getinfo( $ch );
		curl_close( $ch );

		if ( false === $response ) {
			return array(
				'success' => false,
				'status'  => 0,
				'body'    => '',
				'json'    => null,
				'error'   => $error,
			);
		}

		$headerSize = (int) ( $info['header_size'] ?? 0 );
		$rawHeaders = substr( $response, 0, $headerSize );
		$bodyText   = substr( $response, $headerSize );

		return array(
			'success' => (int) ( $info['http_code'] ?? 0 ) >= 200 && (int) ( $info['http_code'] ?? 0 ) < 300,
			'status'  => (int) ( $info['http_code'] ?? 0 ),
			'headers' => $this->parseHeaderBlock( $rawHeaders ),
			'body'    => $bodyText,
			'json'    => $this->decodeJson( $bodyText ),
			'error'   => $error,
		);
	}

	private function sendWithStreams( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		$headerLines = $this->formatHeaderLines( $headers );
		$context     = stream_context_create(
			array(
				'http' => array(
					'method'           => $method,
					'header'           => implode( "\r\n", $headerLines ),
					'content'          => null !== $body ? $body : '',
					'timeout'          => $timeout,
					'ignore_errors'    => true,
				),
			)
		);

		$response = @file_get_contents( $url, false, $context );
		$rawHeaders = isset( $http_response_header ) && is_array( $http_response_header ) ? implode( "\r\n", $http_response_header ) : '';
		$status = 0;

		if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $matches ) ) {
			$status = (int) $matches[1];
		}

		$bodyText = is_string( $response ) ? $response : '';

		return array(
			'success' => $status >= 200 && $status < 300,
			'status'  => $status,
			'headers' => $this->parseHeaderBlock( $rawHeaders ),
			'body'    => $bodyText,
			'json'    => $this->decodeJson( $bodyText ),
			'error'   => false === $response ? 'Unable to read response body.' : '',
		);
	}

	/**
	 * @param array<int|string,mixed> $headers
	 * @return array<string,string>
	 */
	private function normalizeHeaders( array $headers ): array {
		$normalized = array();

		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}

			$normalized[ $name ] = trim( (string) $value );
		}

		return $normalized;
	}

	/**
	 * @param array<string,string> $headers
	 * @return array<int,string>
	 */
	private function formatHeaderLines( array $headers ): array {
		$lines = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $this->headerNameToLabel( $name ) . ': ' . $value;
		}

		return $lines;
	}

	private function headerNameToLabel( string $name ): string {
		return implode( '-', array_map( 'ucfirst', explode( '-', $name ) ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function decodeJson( string $body ): ?array {
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @return array<string,string>
	 */
	private function parseHeaderBlock( string $rawHeaders ): array {
		$result = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $rawHeaders ) ?: array() as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}

			[ $name, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $name ) {
				$result[ strtolower( $name ) ] = $value;
			}
		}

		return $result;
	}
}
