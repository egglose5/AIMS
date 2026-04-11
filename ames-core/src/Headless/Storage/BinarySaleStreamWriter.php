<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

final class BinarySaleStreamWriter {
	public const PACKET_BYTES = 64;

	private const SEGMENT_PREFIX = 'sales-shadow-';
	private const SEGMENT_SUFFIX = '.bin';
	private const DICTIONARY_FILE = 'reference-dictionary.json';
	private const POINTER_INDEX_FILE = 'pointer-index.jsonl';
	private const EXCEPTION_FILE = 'exception-lane.jsonl';

	private string $rootPath;
	private int $flushPacketLimit;
	private int $flushByteLimit;
	/** @var array<int, array<string, mixed>> */
	private array $pendingPackets = array();
	private int $pendingBytes = 0;

	/**
	 * @param array<string, mixed> $options
	 */
	public function __construct( string $rootPath, array $options = array() ) {
		$this->rootPath         = rtrim( $rootPath, "\\/" );
		$this->flushPacketLimit = max( 1, (int) ( $options['flush_packet_limit'] ?? 1 ) );
		$this->flushByteLimit   = max( self::PACKET_BYTES, (int) ( $options['flush_byte_limit'] ?? 65536 ) );
	}

	public function __destruct() {
		if ( array() !== $this->pendingPackets ) {
			$this->flushPending();
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function appendPacket( array $payload ): array {
		$validationError = $this->validatePayload( $payload );
		if ( null !== $validationError ) {
			return $this->recordException( $payload, $validationError );
		}

		$this->ensureRootPath();

		$sku            = $this->stringValue( $payload['sku'] ?? '' );
		$priceCents     = $this->resolveInt( $payload, array( 'price_cents', 'amount_paid_cents', 'paid_amount_cents' ) );
		$taxCents       = $this->resolveInt( $payload, array( 'tax_cents', 'tax_amount_cents' ) );
		$timestamp      = $this->normalizeTimestamp( $payload['timestamp'] ?? time() );
		$eventId        = $this->resolveInt( $payload, array( 'event_id' ) );
		$referenceType  = $this->stringValue( $payload['reference_type'] ?? 'sale' );
		$referenceId    = $this->stringValue( $payload['reference_id'] ?? '' );
		$showId         = $this->stringValue( $payload['show_id'] ?? '' );
		$referenceEntry = $this->rememberReference( $referenceType, $referenceId );
		$segmentPath    = $this->segmentPath();
		$byteOffset     = $this->nextByteOffsetForSegment( $segmentPath );
		$packet         = $this->buildPacket( $sku, $priceCents, $taxCents, $timestamp, $eventId );

		$pointerRow = array(
			'reference_pointer_id' => $referenceEntry['id'],
			'reference_type'       => $referenceType,
			'reference_id'         => $referenceId,
			'show_id'              => $showId,
			'segment_name'         => basename( $segmentPath ),
			'segment_path'         => $segmentPath,
			'byte_offset'          => $byteOffset,
			'byte_length'          => self::PACKET_BYTES,
			'packet_count'         => 1,
			'sku'                  => $sku,
			'price_cents'          => $priceCents,
			'tax_cents'            => $taxCents,
			'timestamp'            => $timestamp,
			'event_id'             => $eventId,
			'created_at'           => gmdate( 'c' ),
		);

		$this->pendingPackets[] = array(
			'segment_path' => $segmentPath,
			'packet'       => $packet,
			'pointer_row'  => $pointerRow,
		);
		$this->pendingBytes += self::PACKET_BYTES;

		if ( $this->shouldFlushPending() ) {
			$flushResult = $this->flushPending();

			return array(
				'status'               => 'written',
				'packet_bytes'         => self::PACKET_BYTES,
				'byte_offset'          => $byteOffset,
				'segment_path'         => $segmentPath,
				'reference_pointer_id' => $referenceEntry['id'],
				'pointer_hash'         => $referenceEntry['pointer_hash'],
				'flush_packet_count'   => (int) ( $flushResult['flushed_packet_count'] ?? 0 ),
				'pending_packet_count' => (int) ( $flushResult['pending_packet_count'] ?? 0 ),
			);
		}

		return array(
			'status'               => 'buffered',
			'packet_bytes'         => self::PACKET_BYTES,
			'byte_offset'          => $byteOffset,
			'segment_path'         => $segmentPath,
			'reference_pointer_id' => $referenceEntry['id'],
			'pointer_hash'         => $referenceEntry['pointer_hash'],
			'pending_packet_count' => count( $this->pendingPackets ),
			'pending_bytes'        => $this->pendingBytes,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findPointers( string $referenceType, string $referenceId ): array {
		$referenceType = $this->stringValue( $referenceType );
		$referenceId   = $this->stringValue( $referenceId );
		if ( '' === $referenceType || '' === $referenceId || ! file_exists( $this->pointerIndexPath() ) ) {
			return array();
		}

		$dictionary = $this->loadDictionary();
		$pointerKey = $this->dictionaryKey( $referenceType, $referenceId );
		if ( ! isset( $dictionary['entries'][ $pointerKey ]['id'] ) ) {
			return array();
		}

		$pointerId = (int) $dictionary['entries'][ $pointerKey ]['id'];
		$matches   = array();

		foreach ( $this->readJsonLines( $this->pointerIndexPath() ) as $row ) {
			if ( $pointerId !== (int) ( $row['reference_pointer_id'] ?? 0 ) ) {
				continue;
			}

			$matches[] = $this->normalizePointerRow( $row );
		}

		usort(
			$matches,
			static fn( array $left, array $right ): int => ( $left['byte_offset'] <=> $right['byte_offset'] )
		);

		return $matches;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listPointers( string $showId = '' ): array {
		$showId  = $this->stringValue( $showId );
		$matches = array();

		foreach ( $this->readJsonLines( $this->pointerIndexPath() ) as $row ) {
			$normalized = $this->normalizePointerRow( $row );
			if ( '' !== $showId && $showId !== $normalized['show_id'] ) {
				continue;
			}

			$matches[] = $normalized;
		}

		usort(
			$matches,
			static function ( array $left, array $right ): int {
				$pathCompare = strcmp( (string) $left['segment_name'], (string) $right['segment_name'] );
				if ( 0 !== $pathCompare ) {
					return $pathCompare;
				}

				return $left['byte_offset'] <=> $right['byte_offset'];
			}
		);

		return $matches;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$dictionary = $this->loadDictionary();

		return array(
			'dictionary_count'    => count( $dictionary['entries'] ?? array() ),
			'pointer_count'       => $this->countJsonLines( $this->pointerIndexPath() ),
			'exception_count'     => $this->countJsonLines( $this->exceptionPath() ),
			'segment_path'        => $this->segmentPath(),
			'segment_bytes'       => file_exists( $this->segmentPath() ) ? (int) filesize( $this->segmentPath() ) : 0,
			'pending_packet_count'=> count( $this->pendingPackets ),
			'pending_bytes'       => $this->pendingBytes,
			'flush_packet_limit'  => $this->flushPacketLimit,
			'flush_byte_limit'    => $this->flushByteLimit,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function flushPending(): array {
		if ( array() === $this->pendingPackets ) {
			return array(
				'status'               => 'idle',
				'flushed_packet_count' => 0,
				'flushed_bytes'        => 0,
				'pending_packet_count' => 0,
				'pending_bytes'        => 0,
			);
		}

		$this->ensureRootPath();

		$packetPayloads = array();
		$pointerRows    = array();

		foreach ( $this->pendingPackets as $pending ) {
			$segmentPath = (string) ( $pending['segment_path'] ?? '' );
			$packet      = (string) ( $pending['packet'] ?? '' );
			$pointerRow  = (array) ( $pending['pointer_row'] ?? array() );

			if ( '' === $segmentPath || '' === $packet ) {
				continue;
			}

			if ( ! isset( $packetPayloads[ $segmentPath ] ) ) {
				$packetPayloads[ $segmentPath ] = '';
			}

			$packetPayloads[ $segmentPath ] .= $packet;
			$pointerRows[] = $pointerRow;
		}

		foreach ( $packetPayloads as $segmentPath => $payload ) {
			file_put_contents( $segmentPath, $payload, FILE_APPEND | LOCK_EX );
		}

		foreach ( $pointerRows as $pointerRow ) {
			$this->appendJsonLine( $this->pointerIndexPath(), $pointerRow );
		}

		$flushedPacketCount = count( $this->pendingPackets );
		$flushedBytes       = $this->pendingBytes;
		$this->pendingPackets = array();
		$this->pendingBytes   = 0;

		return array(
			'status'               => 'written',
			'flushed_packet_count' => $flushedPacketCount,
			'flushed_bytes'        => $flushedBytes,
			'pending_packet_count' => 0,
			'pending_bytes'        => 0,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function validatePayload( array $payload ): ?string {
		$sku         = $this->stringValue( $payload['sku'] ?? '' );
		$referenceId = $this->stringValue( $payload['reference_id'] ?? '' );
		$eventId     = $this->resolveInt( $payload, array( 'event_id' ) );
		$priceCents  = $this->resolveNullableInt( $payload, array( 'price_cents', 'amount_paid_cents', 'paid_amount_cents' ) );
		$taxCents    = $this->resolveNullableInt( $payload, array( 'tax_cents', 'tax_amount_cents' ) );
		$timestamp   = $payload['timestamp'] ?? null;

		if ( '' === $sku ) {
			return 'missing_sku';
		}

		if ( strlen( $sku ) > 32 ) {
			return 'sku_exceeds_32_bytes';
		}

		if ( 1 !== preg_match( '//u', $sku ) ) {
			return 'invalid_utf8_sku';
		}

		if ( '' === $referenceId ) {
			return 'missing_reference_id';
		}

		if ( $eventId <= 0 ) {
			return 'missing_event_id';
		}

		if ( null === $priceCents || $priceCents < 0 ) {
			return 'invalid_price_cents';
		}

		if ( null === $taxCents || $taxCents < 0 ) {
			return 'invalid_tax_cents';
		}

		if ( null === $timestamp || false === $this->coerceTimestamp( $timestamp ) ) {
			return 'invalid_timestamp';
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function recordException( array $payload, string $reason ): array {
		$this->ensureRootPath();

		$entry = array(
			'reason'         => $reason,
			'sku'            => $this->stringValue( $payload['sku'] ?? '' ),
			'reference_type' => $this->stringValue( $payload['reference_type'] ?? '' ),
			'reference_id'   => $this->stringValue( $payload['reference_id'] ?? '' ),
			'event_id'       => $this->resolveInt( $payload, array( 'event_id' ) ),
			'created_at'     => gmdate( 'c' ),
		);

		$this->appendJsonLine( $this->exceptionPath(), $entry );

		return array(
			'status' => 'rejected',
			'reason' => $reason,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rememberReference( string $referenceType, string $referenceId ): array {
		$dictionary = $this->loadDictionary();
		$key        = $this->dictionaryKey( $referenceType, $referenceId );
		$now        = gmdate( 'c' );

		if ( isset( $dictionary['entries'][ $key ] ) ) {
			$dictionary['entries'][ $key ]['last_seen_at'] = $now;
			$dictionary['entries'][ $key ]['hit_count']    = (int) ( $dictionary['entries'][ $key ]['hit_count'] ?? 0 ) + 1;
			$this->saveDictionary( $dictionary );

			return $dictionary['entries'][ $key ];
		}

		$id = (int) ( $dictionary['next_id'] ?? 1 );

		$dictionary['entries'][ $key ] = array(
			'id'             => $id,
			'reference_type' => $referenceType,
			'reference_id'   => $referenceId,
			'pointer_hash'   => substr( hash( 'sha256', $referenceType . '|' . $referenceId ), 0, 16 ),
			'full_hash'      => hash( 'sha256', $referenceType . '|' . $referenceId ),
			'hit_count'      => 1,
			'created_at'     => $now,
			'last_seen_at'   => $now,
		);
		$dictionary['next_id'] = $id + 1;
		$this->saveDictionary( $dictionary );

		return $dictionary['entries'][ $key ];
	}

	private function buildPacket( string $sku, int $priceCents, int $taxCents, int $timestamp, int $eventId ): string {
		$skuHeader = str_pad( $sku, 32, "\0", STR_PAD_RIGHT );

		return $skuHeader
			. $this->packUint64Be( $priceCents )
			. $this->packUint64Be( $taxCents )
			. $this->packUint64Be( $timestamp )
			. $this->packUint64Be( $eventId );
	}

	private function packUint64Be( int $value ): string {
		if ( $value < 0 ) {
			throw new \InvalidArgumentException( 'Cannot pack a negative unsigned 64-bit integer.' );
		}

		$high = intdiv( $value, 4294967296 );
		$low  = $value % 4294967296;

		return pack( 'N2', $high, $low );
	}

	private function ensureRootPath(): void {
		if ( '' === $this->rootPath ) {
			throw new \RuntimeException( 'Binary sale stream root path is required.' );
		}

		if ( ! is_dir( $this->rootPath ) && ! mkdir( $concurrentDirectory = $this->rootPath, 0777, true ) && ! is_dir( $concurrentDirectory ) ) {
			throw new \RuntimeException( 'Unable to create binary sale stream directory: ' . $this->rootPath );
		}
	}

	private function segmentPath(): string {
		return $this->rootPath . DIRECTORY_SEPARATOR . self::SEGMENT_PREFIX . gmdate( 'Ymd' ) . self::SEGMENT_SUFFIX;
	}

	private function pointerIndexPath(): string {
		return $this->rootPath . DIRECTORY_SEPARATOR . self::POINTER_INDEX_FILE;
	}

	private function exceptionPath(): string {
		return $this->rootPath . DIRECTORY_SEPARATOR . self::EXCEPTION_FILE;
	}

	private function dictionaryPath(): string {
		return $this->rootPath . DIRECTORY_SEPARATOR . self::DICTIONARY_FILE;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadDictionary(): array {
		if ( ! file_exists( $this->dictionaryPath() ) ) {
			return array(
				'next_id' => 1,
				'entries' => array(),
			);
		}

		$decoded = json_decode( (string) file_get_contents( $this->dictionaryPath() ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'next_id' => 1,
				'entries' => array(),
			);
		}

		if ( ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			$decoded['entries'] = array();
		}

		if ( ! isset( $decoded['next_id'] ) || ! is_numeric( $decoded['next_id'] ) ) {
			$decoded['next_id'] = count( $decoded['entries'] ) + 1;
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $dictionary
	 */
	private function saveDictionary( array $dictionary ): void {
		$this->ensureRootPath();
		file_put_contents( $this->dictionaryPath(), (string) json_encode( $dictionary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function appendJsonLine( string $path, array $row ): void {
		$this->ensureRootPath();
		file_put_contents( $path, (string) json_encode( $row, JSON_UNESCAPED_SLASHES ) . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function readJsonLines( string $path ): array {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return array();
		}

		$rows = array();
		foreach ( $lines as $line ) {
			$decoded = json_decode( (string) $line, true );
			if ( is_array( $decoded ) ) {
				$rows[] = $decoded;
			}
		}

		return $rows;
	}

	private function countJsonLines( string $path ): int {
		return count( $this->readJsonLines( $path ) );
	}

	private function nextByteOffsetForSegment( string $segmentPath ): int {
		$offset = file_exists( $segmentPath ) ? (int) filesize( $segmentPath ) : 0;

		foreach ( $this->pendingPackets as $pending ) {
			if ( $segmentPath !== (string) ( $pending['segment_path'] ?? '' ) ) {
				continue;
			}

			$offset += strlen( (string) ( $pending['packet'] ?? '' ) );
		}

		return $offset;
	}

	private function shouldFlushPending(): bool {
		return count( $this->pendingPackets ) >= $this->flushPacketLimit || $this->pendingBytes >= $this->flushByteLimit;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizePointerRow( array $row ): array {
		return array(
			'reference_pointer_id' => (int) ( $row['reference_pointer_id'] ?? 0 ),
			'reference_type'       => (string) ( $row['reference_type'] ?? '' ),
			'reference_id'         => (string) ( $row['reference_id'] ?? '' ),
			'show_id'              => (string) ( $row['show_id'] ?? '' ),
			'segment_name'         => (string) ( $row['segment_name'] ?? '' ),
			'segment_path'         => (string) ( $row['segment_path'] ?? '' ),
			'byte_offset'          => (int) ( $row['byte_offset'] ?? 0 ),
			'byte_length'          => (int) ( $row['byte_length'] ?? 0 ),
			'packet_count'         => (int) ( $row['packet_count'] ?? 0 ),
			'sku'                  => (string) ( $row['sku'] ?? '' ),
			'price_cents'          => (int) ( $row['price_cents'] ?? 0 ),
			'tax_cents'            => (int) ( $row['tax_cents'] ?? 0 ),
			'timestamp'            => (int) ( $row['timestamp'] ?? 0 ),
			'event_id'             => (int) ( $row['event_id'] ?? 0 ),
			'created_at'           => (string) ( $row['created_at'] ?? '' ),
		);
	}

	private function dictionaryKey( string $referenceType, string $referenceId ): string {
		return $referenceType . '|' . substr( hash( 'sha256', $referenceType . '|' . $referenceId ), 0, 16 );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string> $keys
	 */
	private function resolveInt( array $payload, array $keys ): int {
		$value = $this->resolveNullableInt( $payload, $keys );
		return null === $value ? 0 : $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string> $keys
	 */
	private function resolveNullableInt( array $payload, array $keys ): ?int {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$value = $payload[ $key ];
			if ( is_numeric( $value ) ) {
				return (int) round( (float) $value );
			}
		}

		return null;
	}

	private function normalizeTimestamp( mixed $value ): int {
		$timestamp = $this->coerceTimestamp( $value );
		if ( false === $timestamp ) {
			throw new \InvalidArgumentException( 'Invalid timestamp for binary sale stream packet.' );
		}

		return $timestamp;
	}

	private function coerceTimestamp( mixed $value ): int|false {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return $timestamp;
			}
		}

		return false;
	}

	private function stringValue( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}
}
