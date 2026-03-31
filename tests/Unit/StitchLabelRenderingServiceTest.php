<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Label_Template_Repository;
use AIMS_Label_Template_Service;
use AIMS_Stitch_Label_Rendering_Service;

final class StitchLabelRenderingServiceTest extends \AIMS\Tests\TestCase {
	public function testBuildPrintModelExpandsOneLabelPerQuantity(): void {
		$service = new AIMS_Stitch_Label_Rendering_Service( new AIMS_Label_Template_Service() );
		$model   = $service->build_print_model(
			array(
				array(
					'product_name' => 'Cut Panel',
					'product_sku'  => 'CUT-001',
					'quantity'     => 2,
				),
				array(
					'product_name' => 'Binding Set',
					'product_sku'  => 'BIN-010',
					'quantity'     => 1,
				),
			),
			AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY
		);

		$this->assertSame( 3, (int) $model['label_count'] );
		$this->assertCount( 3, $model['labels'] );
		$this->assertSame( 2, (int) $model['labels'][0]['copy_total'] );
		$this->assertSame( 2, (int) $model['labels'][1]['copy_number'] );
		$this->assertSame( 'BIN-010', $model['labels'][2]['product_sku'] );
	}

	public function testRenderPrintDocumentIncludesTemplateAndBarcodeMarkup(): void {
		$service = new AIMS_Stitch_Label_Rendering_Service( new AIMS_Label_Template_Service() );
		$output  = $service->render_print_document(
			array(
				array(
					'product_name' => 'Legacy Stitch Panel',
					'product_sku'  => 'STITCH-123',
					'quantity'     => 2,
				),
			)
		);

		$this->assertStringContainsString( '@page', $output );
		$this->assertStringContainsString( 'Legacy Stitch Panel', $output );
		$this->assertStringContainsString( 'STITCH-123', $output );
		$this->assertStringContainsString( '<svg', $output );
		$this->assertSame( 2, substr_count( $output, 'Legacy Stitch Panel' ) );
	}

	public function testRenderBarcodeSvgContainsSvgMarkup(): void {
		$service = new AIMS_Stitch_Label_Rendering_Service( new AIMS_Label_Template_Service() );
		$svg     = $service->render_barcode_svg(
			'STITCH-123',
			array(
				'barcode_height_px'     => 26,
				'barcode_margin_top_px' => 2,
			)
		);

		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'aria-label="STITCH-123"', $svg );
		$this->assertStringContainsString( '<rect', $svg );
	}
}
