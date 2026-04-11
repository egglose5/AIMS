<?php

declare( strict_types=1 );

namespace AmesCore\Headless\Storage;

final class BinarySaleStreamReader {
	/**
	 * @return array<string, mixed>
	 */
	public function readPacket( string $segmentPath, int $byteOffset ): array {
		$segmentPath = trim( $segmentPath );
		if ( '' === $segmentPath || ! file_exists( $segmentPath ) ) {
			throw new \RuntimeException( 'Binary sale stream segment does not exist: ' . $segmentPath );
		}

		if ( $byteOffset < 0 ) {
			throw new \InvalidArgumentException( 'Binary sale stream byte offset must be zero or greater.' );
		}

		$handle = fopen( $segmentPath, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Unable to open binary sale stream segment for reading: ' . $segmentPath );
		}

		try {
			if ( 0 !== fseek( $handle, $byteOffset ) ) {
				throw new \RuntimeException( 'Unable to seek to requested binary packet offset.' );
			}

			$packet = fread( $handle, BinarySaleStreamWriter::PACKET_BYTES );
		} finally {
			fclose( $handle );
		}

		if ( ! is_string( $packet ) || strlen( $packet ) !== BinarySaleStreamWriter::PACKET_BYTES ) {
			throw new \RuntimeException( 'Binary sale stream packet is incomplete or unreadable at offset ' . $byteOffset . '.' );
		}

		$decoded = $this->decodePacket( $packet );
		$decoded['segment_path'] = $segmentPath;
		$decoded['byte_offset']  = $byteOffset;
		$decoded['byte_length']  = BinarySaleStreamWriter::PACKET_BYTES;

		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodePacket( string $packet ): array {
		if ( strlen( $packet ) !== BinarySaleStreamWriter::PACKET_BYTES ) {
			throw new \InvalidArgumentException( 'Binary sale stream packets must be exactly 64 bytes.' );
		}

		$skuHeader = substr( $packet, 0, 32 );
		$pricePart = substr( $packet, 32, 8 );
		$taxPart   = substr( $packet, 40, 8 );
		$timePart  = substr( $packet, 48, 8 );
		$eventPart = substr( $packet, 56, 8 );

		return array(
			'sku'         => rtrim( (string) $skuHeader, "\0" ),
			'price_cents' => $this->unpackUint64Be( (string) $pricePart ),
			'tax_cents'   => $this->unpackUint64Be( (string) $taxPart ),
			'timestamp'   => $this->unpackUint64Be( (string) $timePart ),
			'event_id'    => $this->unpackUint64Be( (string) $eventPart ),
		);
	}

	private function unpackUint64Be( string $bytes ): int {
		if ( 8 !== strlen( $bytes ) ) {
			throw new \InvalidArgumentException( 'Unsigned 64-bit values must be exactly 8 bytes.' );
		}

		$parts = unpack( 'Nhigh/Nlow', $bytes );
		$high  = (int) ( $parts['high'] ?? 0 );
		$low   = (int) ( $parts['low'] ?? 0 );

		return (int) ( ( $high * 4294967296 ) + $low );
	}
}
