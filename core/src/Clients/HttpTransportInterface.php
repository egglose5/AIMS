<?php

declare( strict_types=1 );

namespace AIMS\Core\Clients;

interface HttpTransportInterface {
	/**
	 * Sends an HTTP request and returns a normalized response payload.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Request URL.
	 * @param array<string,mixed> $options Request options.
	 * @return array<string,mixed>
	 */
	public function send( string $method, string $url, array $options = array() ): array;
}
