<?php

declare( strict_types=1 );

namespace AIMS\Tests\Support;

use AIMS\Core\Clients\HttpTransportInterface;

final class FakeHttpTransport implements HttpTransportInterface {
	/** @var array<int, array<string, mixed>> */
	public array $requests = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $responses;

	/**
	 * @param array<string, array<string, mixed>> $responses
	 */
	public function __construct( array $responses = array() ) {
		$this->responses = $responses;
	}

	public function send( string $method, string $url, array $options = array() ): array {
		$this->requests[] = array(
			'method'  => strtoupper( $method ),
			'url'     => $url,
			'options' => $options,
		);

		foreach ( $this->responses as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}

		return array(
			'success' => false,
			'status'  => 404,
			'body'    => '',
			'json'    => null,
			'error'   => 'No fake response configured for ' . $url,
		);
	}
}
