<?php

declare( strict_types=1 );

namespace AmesCore\Headless;

final class CoreConfig {
	private string $rootPath;
	private string $sinkPath;
	private string $vaultPath;
	private string $configPath;
	private string $logsPath;
	private string $sqlitePath;
	private string $secretStorePath;
	private string $sharedSecret;
	private string $archiveSecret;
	private string $encryptionKey;
	private string $wooUrl;
	private string $squareUrl;
	private string $squareLocationId;
	private string $squareVersion;
	private string $binaryStreamMode;
	private bool $binaryPrimaryApproved;
	private int $binaryFlushPacketLimit;
	private int $binaryFlushByteLimit;
	private int $hotRetentionDays;
	private int $vaultRetentionDays;

	private function __construct( array $values ) {
		$this->rootPath          = $values['root_path'];
		$this->sinkPath          = $values['sink_path'];
		$this->vaultPath         = $values['vault_path'];
		$this->configPath        = $values['config_path'];
		$this->logsPath          = $values['logs_path'];
		$this->sqlitePath        = $values['sqlite_path'];
		$this->secretStorePath   = $values['secret_store_path'];
		$this->sharedSecret      = $values['shared_secret'];
		$this->archiveSecret     = $values['archive_secret'];
		$this->encryptionKey     = $values['encryption_key'];
		$this->wooUrl            = $values['woo_url'];
		$this->squareUrl         = $values['square_url'];
		$this->squareLocationId      = $values['square_location_id'];
		$this->squareVersion         = $values['square_version'];
		$this->binaryStreamMode       = $values['binary_stream_mode'];
		$this->binaryPrimaryApproved  = (bool) ( $values['binary_primary_approved'] ?? false );
		$this->binaryFlushPacketLimit = (int) $values['binary_flush_packet_limit'];
		$this->binaryFlushByteLimit   = (int) $values['binary_flush_byte_limit'];
		$this->hotRetentionDays       = (int) $values['hot_retention_days'];
		$this->vaultRetentionDays     = (int) $values['vault_retention_days'];
	}

	public static function fromRoot( string $rootPath ): self {
		$rootPath   = rtrim( $rootPath, '\\/' );
		$sinkPath   = self::envPath( 'AIMS_SINK_PATH', $rootPath . DIRECTORY_SEPARATOR . 'sink', $rootPath );
		$vaultPath  = self::envPath( 'AIMS_VAULT_PATH', $rootPath . DIRECTORY_SEPARATOR . 'vault', $rootPath );
		$configPath = self::envPath( 'AIMS_CONFIG_PATH', $rootPath . DIRECTORY_SEPARATOR . 'config', $rootPath );
		$logsPath   = self::envPath( 'AIMS_LOG_PATH', $rootPath . DIRECTORY_SEPARATOR . 'logs', $rootPath );
		$sqlitePath = self::envPath( 'AIMS_SQLITE_PATH', $sinkPath . DIRECTORY_SEPARATOR . 'ames_ledger.db', $rootPath );

		$binaryMode = strtolower( trim( (string) ( getenv( 'AIMS_BINARY_STREAM_MODE' ) ?: 'shadow' ) ) );
		$binaryPrimaryApproved = in_array(
			strtolower( trim( (string) ( getenv( 'AIMS_BINARY_PRIMARY_APPROVED' ) ?: '' ) ) ),
			array( '1', 'true', 'yes', 'on' ),
			true
		);
		if ( ! in_array( $binaryMode, array( 'off', 'shadow', 'primary' ), true ) ) {
			$binaryMode = 'shadow';
		}

		if ( 'primary' === $binaryMode && ! $binaryPrimaryApproved ) {
			$binaryMode = 'shadow';
		}

		return new self(
			array(
				'root_path'                => $rootPath,
				'sink_path'                => $sinkPath,
				'vault_path'               => $vaultPath,
				'config_path'              => $configPath,
				'logs_path'                => $logsPath,
				'sqlite_path'              => $sqlitePath,
				'secret_store_path'        => $configPath . DIRECTORY_SEPARATOR . 'secrets.json',
				'shared_secret'            => trim( (string) getenv( 'AIMS_SHARED_SECRET' ) ),
				'archive_secret'           => trim( (string) ( getenv( 'AIMS_ARCHIVE_SECRET' ) ?: getenv( 'AIMS_SHARED_SECRET' ) ) ),
				'encryption_key'           => trim( (string) getenv( 'AIMS_ENCRYPTION_KEY' ) ),
				'woo_url'                  => rtrim( trim( (string) getenv( 'AIMS_WOO_URL' ) ), '/' ),
				'square_url'               => rtrim( trim( (string) ( getenv( 'AIMS_SQUARE_URL' ) ?: 'https://connect.squareup.com' ) ), '/' ),
				'square_location_id'       => trim( (string) getenv( 'AIMS_SQUARE_LOCATION_ID' ) ),
				'square_version'           => trim( (string) ( getenv( 'AIMS_SQUARE_VERSION' ) ?: '2026-01-22' ) ),
				'binary_stream_mode'       => $binaryMode,
				'binary_primary_approved'  => $binaryPrimaryApproved,
				'binary_flush_packet_limit'=> max( 1, (int) ( getenv( 'AIMS_BINARY_FLUSH_PACKET_LIMIT' ) ?: 1024 ) ),
				'binary_flush_byte_limit'  => max( 64, (int) ( getenv( 'AIMS_BINARY_FLUSH_BYTE_LIMIT' ) ?: 65536 ) ),
				'hot_retention_days'       => max( 1, (int) ( getenv( 'AIMS_HOT_RETENTION_DAYS' ) ?: 30 ) ),
				'vault_retention_days'     => max( 1, (int) ( getenv( 'AIMS_VAULT_RETENTION_DAYS' ) ?: 365 ) ),
			)
		);
	}

	public function ensureDirectories(): void {
		foreach ( array( $this->sinkPath, $this->vaultPath, $this->configPath, $this->logsPath, dirname( $this->sqlitePath ) ) as $path ) {
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

	public function configPath(): string {
		return $this->configPath;
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

	public function encryptionKey(): string {
		return $this->encryptionKey;
	}

	public function secretStorePath(): string {
		return $this->secretStorePath;
	}

	public function wooUrl(): string {
		return $this->wooUrl;
	}

	public function squareUrl(): string {
		return $this->squareUrl;
	}

	public function squareLocationId(): string {
		return $this->squareLocationId;
	}

	public function squareVersion(): string {
		return $this->squareVersion;
	}

	public function binaryStreamMode(): string {
		return $this->binaryStreamMode;
	}

	public function binaryPrimaryApproved(): bool {
		return $this->binaryPrimaryApproved;
	}

	public function binaryFlushPacketLimit(): int {
		return $this->binaryFlushPacketLimit;
	}

	public function binaryFlushByteLimit(): int {
		return $this->binaryFlushByteLimit;
	}

	public function hotRetentionDays(): int {
		return $this->hotRetentionDays;
	}

	public function vaultRetentionDays(): int {
		return $this->vaultRetentionDays;
	}

	public function hasWooBaseUrl(): bool {
		return '' !== $this->wooUrl;
	}

	public function hasSquareBaseUrl(): bool {
		return '' !== $this->squareUrl;
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
