<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Label_Template_Repository;
use AIMS_Label_Template_Service;

final class LabelTemplateServiceTest extends \AIMS\Tests\TestCase {
	public function testBuildSettingsViewModelExposesActiveTemplateAndChoices(): void {
		$service = new AIMS_Label_Template_Service();
		$model   = $service->build_settings_view_model();

		$this->assertSame( AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY, $model['active_template_key'] );
		$this->assertArrayHasKey( AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY, $model['template_choices'] );
		$this->assertSame( 'Legacy Stitch Label', $model['active_template']['template_name'] );
	}

	public function testSaveTemplateSettingsNormalizesValues(): void {
		$service = new AIMS_Label_Template_Service();
		$result   = $service->save_template_settings(
			AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY,
			array(
				'template_name'        => 'Legacy Stitch Label',
				'description'          => 'Normalized',
				'label_width_in'       => '2.5',
				'label_height_in'      => '0.8',
				'padding_in'           => '0.04',
				'product_name_font_px' => '12',
				'sku_font_px'          => '9',
				'barcode_height_px'    => '30',
				'barcode_margin_top_px'=> '2',
				'show_product_name'    => 1,
				'show_sku_text'        => 0,
				'show_barcode_text'    => 1,
				'set_active'           => 1,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2.5, (float) $result['template']['label_width_in'] );
		$this->assertSame( 0.8, (float) $result['template']['label_height_in'] );
		$this->assertSame( 1, (int) $result['template']['show_product_name'] );
		$this->assertSame( 0, (int) $result['template']['show_sku_text'] );
	}
}
