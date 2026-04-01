<?php

declare( strict_types=1 );

namespace AmesCore\Inventory;

use RuntimeException;

final class MovementException extends RuntimeException {
	private string $errorCode;
	private array $context;

	public function __construct( string $errorCode, string $message, array $context = array() ) {
		parent::__construct( $message );

		$this->errorCode = $errorCode;
		$this->context   = $context;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getContext(): array {
		return $this->context;
	}
}
