<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Headless\Storage\BinarySaleStreamReader;
use AmesCore\Headless\Storage\BinarySaleStreamWriter;

final class AmesCoreBinarySaleStreamReaderTest extends \AIMS\Tests\TestCase {
	public function testReadPacketDecodesStoredBinaryFields(): void {
		$root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-binary-reader-' . uniqid( '', true );
		$writer = new BinarySaleStreamWriter( $root );

		$result = $writer->appendPacket(
			array(
				'sku'            => 'PATCH-RED',
				'price_cents'    => 1599,
				'tax_cents'      => 123,
				'timestamp'      => 1712839200,
				'event_id'       => 42,
				'reference_type' => 'square_sale',
				'reference_id'   => 'sale-2001',
			)
		);

		$reader = new BinarySaleStreamReader();
		$packet = $reader->readPacket( (string) $result['segment_path'], (int) $result['byte_offset'] );

		$this->assertSame( 'PATCH-RED', $packet['sku'] );
		$this->assertSame( 1599, $packet['price_cents'] );
		$this->assertSame( 123, $packet['tax_cents'] );
		$this->assertSame( 1712839200, $packet['timestamp'] );
		$this->assertSame( 42, $packet['event_id'] );
		$this->assertSame( 64, $packet['byte_length'] );
	}
}
