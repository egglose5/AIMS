<?php

declare( strict_types=1 );

namespace AmesCore\Headless;

final class CoreConfig {
	private string $rootPath;
	private string $sinkPath;
	private string $vaultPath;
	private string $logsPath;
	private string $sqlitePath;
	private string $sharedSecret;
	private string $archiveSecret;
	private string $wooUrl;
	private string $wooConsumerKey;
	private string $wooConsumerSecret;
	private string $squareUrl;
	private string $squareToken;
	private string $squareLocationId;
	private string $squareVersion;

	private function __construct( array $values ) {
		$this->rootPath          = $values['root_path'];
		$this->sinkPath          = $values['sink_path'];
		$this->vaultPath         = $values['vault_path'];
		$this->logsPath          = $values['logs_path'];
		$this->sqlitePath        = $values['sqlite_path'];
		$this->sharedSecret      = $values['shared_secret'];
		$this->archiveSecret     = $values['archive_secret'];
		$this->wooUrl            = $values['woo_url'];
		$this->wooConsumerKey    = $values['woo_consumer_key'];
		$this->wooConsumerSecret = $values['woo_consumer_secret'];
		$this->squareUrl         = $values['square_url'];
		$this->squareToken       = $values['square_token'];
		$this->squareLocationId  = $values['square_location_id'];
		$this->squareVersion     = $values['square_version'];
	}

	public static function fromRoot( string $rootPath ): self {
		$rootPath   = rtrim( $rootPath, '\\/' );
		$sinkPath   = self::envPath( 'AIMS_SINK_PATH', $rootPath . DIRECTORY_SEPARATOR . 'sink', $rootPath );
		$vaultPath  = self::envPath( 'AIMS_VAULT_PATH', $rootPath . DIRECTORY_SEPARATOR . 'vault', $rootPath );
		$logsPath   = self::envPath( 'AIMS_LOG_PATH', $rootPath . DIRECTORY_SEPARATOR . 'logs', $rootPath );
		$sqlitePath = self::envPath( 'AIMS_SQLITE_PATH', $sinkPath . DIRECTORY_SEPARATOR . 'ames_ledger.db', $rootPath );

		return new self(
			array(
				'root_path'           => $rootPath,
				'sink_path'           => $sinkPath,
				'vault_path'          => $vaultPath,
				'logs_path'           => $logsPath,
				'sqlite_path'         => $sqlitePath,
				'shared_secret'       => trim( (string) getenv( 'AIMS_SHARED_SECRET' ) ),
				'archive_secret'      => trim( (string) ( getenv( 'AIMS_ARCHIVE_SECRET' ) ?: getenv( 'AIMS_SHARED_SECRET' ) ) ),
				'woo_url'             => rtrim( trim( (string) getenv( 'AIMS_WOO_URL' ) ), '/' ),
				'woo_consumer_key'    => trim( (string) getenv( 'AIMS_WOO_CONSUMER_KEY' ) ),
				'woo_consumer_secret' => trim( (string) getenv( 'AIMS_WOO_CONSUMER_SECRET' ) ),
				'square_url'          => rtrim( trim( (string) ( getenv( 'AIMS_SQUARE_URL' ) ?: 'https://connect.squareup.com' ) ), '/' ),
				'square_token'        => trim( (string) getenv( 'AIMS_SQUARE_TOKEN' ) ),
				'square_location_id'  => trim( (string) getenv( 'AIMS_SQUARE_LOCATION_ID' ) ),
				'square_version'      => trim( (string) ( getenv( 'AIMS_SQUARE_VERSION' ) ?: '2026-01-22' ) ),
			)
		);
	}

	public function ensureDirectories(): void {
		foreach ( array( $this->sinkPath, $this->vaultPath, $this->logsPath, dirname( $this->sqlitePath ) ) as $path ) {
			if ( is_dir( $path ) ) {
				continue;
			}

			if ( ! @mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
				throw new \RuntimeException( 'Unable to create directory: ' . $path );
			}
		}
	}

	public function sinkPath(): string {
		return $this->sinkPath;
	}

	public function vaultPath(): string {
		return $this->vaultPath;
	}

	public function logsPath(): string {
		return $this->logsPath;
	}

	public function sqlitePath(): string {
		return $this->sqlitePath;
	}

	public function sharedSecret(): string {
		return $this->sharedSecret;
	}

	public function archiveSecret(): string {
		return $this->archiveSecret;
	}

	public function wooUrl(): string {
		return $this->wooUrl;
	}

	public function wooConsumerKey(): string {
		return $this->wooConsumerKey;
	}

	public function wooConsumerSecret(): string {
		return $this->wooConsumerSecret;
	}

	public function squareUrl(): string {
		return $this->squareUrl;
	}

	public function squareToken(): string {
		return $this->squareToken;
	}

	public function squareLocationId(): string {
		return $this->squareLocationId;
	}

	public function squareVersion(): string {
		return $this->squareVersion;
	}

	public function hasWooCredentials(): bool {
		return '' !== $this->wooUrl && '' !== $this->wooConsumerKey && '' !== $this->wooConsumerSecret;
	}

	public function hasSquareCredentials(): bool {
		return '' !== $this->squareUrl && '' !== $this->squareToken;
	}

	private static function envPath( string $key, string $default, string $rootPath ): string {
		$value = trim( (string) getenv( $key ) );

		if ( '' === $value ) {
			return $default;
		}

		if ( preg_match( '/^[A-Za-z]:\\\\/', $value ) || str_starts_with( $value, '/' ) || str_starts_with( $value, '\\' ) ) {
			return $value;
		}

		return rtrim( $rootPath, '\\/' ) . DIRECTORY_SEPARATOR . ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $value ), '\\/' );
	}
}
