<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS_Label_Template_Repository;

final class LabelTemplateRepositoryTest extends \AIMS\Tests\TestCase {
	public function testDefaultTemplateResolvesToLegacyLayout(): void {
		$repo = new AIMS_Label_Template_Repository();

		$this->assertSame( AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY, $repo->get_active_template_key() );

		$template = $repo->get_active_template();

		$this->assertSame( 'Legacy Stitch Label', $template['template_name'] );
		$this->assertSame( 2.0, (float) $template['label_width_in'] );
		$this->assertSame( 0.75, (float) $template['label_height_in'] );
		$this->assertSame( 1, (int) $template['show_product_name'] );
		$this->assertSame( 1, (int) $template['show_sku_text'] );
	}

	public function testSaveTemplateSettingsPersistsOverridesAndActiveKey(): void {
		$repo = new AIMS_Label_Template_Repository();

		$result = $repo->save_template_settings(
			AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY,
			array(
				'template_name'        => 'Legacy Stitch Label',
				'description'          => 'Updated description',
				'label_width_in'       => '2.25',
				'label_height_in'      => '0.80',
				'padding_in'           => '0.05',
				'product_name_font_px' => '11',
				'sku_font_px'          => '9',
				'barcode_height_px'    => '28',
				'barcode_margin_top_px'=> '3',
				'show_product_name'    => 1,
				'show_sku_text'        => 1,
				'show_barcode_text'    => 0,
				'set_active'           => 1,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY, get_option( AIMS_Label_Template_Repository::OPTION_ACTIVE_TEMPLATE_KEY ) );

		$overrides = get_option( AIMS_Label_Template_Repository::OPTION_TEMPLATE_SETTINGS, array() );
		$this->assertArrayHasKey( AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY, $overrides );
		$this->assertSame( 2.25, (float) $overrides[ AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY ]['label_width_in'] );
		$this->assertSame( 0.80, (float) $overrides[ AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY ]['label_height_in'] );
		$this->assertSame( 0, (int) $overrides[ AIMS_Label_Template_Repository::DEFAULT_TEMPLATE_KEY ]['show_barcode_text'] );
	}

	public function testInvalidTemplateLookupReturnsNull(): void {
		$repo = new AIMS_Label_Template_Repository();

		$this->assertNull( $repo->get_template( 'missing-template' ) );
	}
}
