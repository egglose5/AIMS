<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AmesCore\Headless\Storage\BinarySaleStreamWriter;

final class AmesCoreBinarySaleStreamWriterTest extends \AIMS\Tests\TestCase {
	public function testAppendPacketWritesFixedWidthBinaryRecordAndPointerIndex(): void {
		$root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-binary-' . uniqid( '', true );
		$writer = new BinarySaleStreamWriter( $root );

		$result = $writer->appendPacket(
			array(
				'sku'           => 'PATCH-RED',
				'price_cents'   => 1599,
				'tax_cents'     => 123,
				'timestamp'     => 1712839200,
				'event_id'      => 42,
				'reference_type'=> 'square_sale',
				'reference_id'  => 'sale-1001',
			)
		);

		$this->assertSame( 'written', $result['status'] );
		$this->assertSame( 64, $result['packet_bytes'] );
		$this->assertSame( 0, $result['byte_offset'] );
		$this->assertFileExists( $result['segment_path'] );
		$this->assertSame( 64, filesize( $result['segment_path'] ) );

		$pointers = $writer->findPointers( 'square_sale', 'sale-1001' );
		$this->assertCount( 1, $pointers );
		$this->assertSame( 42, $pointers[0]['event_id'] );
		$this->assertSame( 1599, $pointers[0]['price_cents'] );
	}

	public function testAppendPacketReusesDictionaryPointerForRepeatedReference(): void {
		$root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-binary-' . uniqid( '', true );
		$writer = new BinarySaleStreamWriter( $root );

		$first = $writer->appendPacket(
			array(
				'sku'           => 'PATCH-RED',
				'price_cents'   => 1599,
				'tax_cents'     => 123,
				'timestamp'     => 1712839200,
				'event_id'      => 42,
				'reference_type'=> 'square_sale',
				'reference_id'  => 'sale-1002',
			)
		);

		$second = $writer->appendPacket(
			array(
				'sku'           => 'PATCH-BLK',
				'price_cents'   => 1899,
				'tax_cents'     => 146,
				'timestamp'     => 1712839260,
				'event_id'      => 42,
				'reference_type'=> 'square_sale',
				'reference_id'  => 'sale-1002',
			)
		);

		$this->assertSame( $first['reference_pointer_id'], $second['reference_pointer_id'] );

		$summary = $writer->summary();
		$this->assertSame( 1, $summary['dictionary_count'] );
		$this->assertSame( 2, $summary['pointer_count'] );
	}

	public function testAppendPacketRoutesInvalidSkuToExceptionLane(): void {
		$root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-binary-' . uniqid( '', true );
		$writer = new BinarySaleStreamWriter( $root );

		$result = $writer->appendPacket(
			array(
				'sku'           => str_repeat( 'X', 33 ),
				'price_cents'   => 1599,
				'tax_cents'     => 123,
				'timestamp'     => 1712839200,
				'event_id'      => 42,
				'reference_type'=> 'square_sale',
				'reference_id'  => 'sale-bad-sku',
			)
		);

		$this->assertSame( 'rejected', $result['status'] );
		$this->assertSame( 'sku_exceeds_32_bytes', $result['reason'] );
		$this->assertSame( 1, $writer->summary()['exception_count'] );
		$this->assertCount( 0, $writer->findPointers( 'square_sale', 'sale-bad-sku' ) );
	}

	public function testAppendPacketCanBufferUntilConfiguredFlushThreshold(): void {
		$root   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aims-binary-' . uniqid( '', true );
		$writer = new BinarySaleStreamWriter(
			$root,
			array(
				'flush_packet_limit' => 2,
			)
		);

		$first = $writer->appendPacket(
			array(
				'sku'            => 'PATCH-RED',
				'price_cents'    => 1599,
				'tax_cents'      => 123,
				'timestamp'      => 1712839200,
				'event_id'       => 42,
				'reference_type' => 'square_sale',
				'reference_id'   => 'sale-buffer-1',
			)
		);

		$this->assertSame( 'buffered', $first['status'] );
		$this->assertSame( 1, $writer->summary()['pending_packet_count'] );

		$second = $writer->appendPacket(
			array(
				'sku'            => 'PATCH-BLK',
				'price_cents'    => 1899,
				'tax_cents'      => 146,
				'timestamp'      => 1712839260,
				'event_id'       => 42,
				'reference_type' => 'square_sale',
				'reference_id'   => 'sale-buffer-2',
			)
		);

		$this->assertSame( 'written', $second['status'] );
		$this->assertFileExists( $second['segment_path'] );
		$this->assertSame( 128, filesize( $second['segment_path'] ) );
		$this->assertSame( 0, $writer->summary()['pending_packet_count'] );
		$this->assertSame( 2, $writer->summary()['pointer_count'] );
	}
}
