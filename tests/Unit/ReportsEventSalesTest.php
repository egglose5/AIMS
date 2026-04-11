<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

final class ReportsEventSalesTest extends \AIMS\Tests\TestCase {
	public function testDataProviderAggregatesSummaryTotalsIncludingTotalShowProfit(): void {
		$provider = new class() extends \AIMS_Reports_Event_Sales_Data_Provider {
			public function get_rows( int $event_id = 0 ): array {
				return array(
					array(
						'event_id'           => 10,
						'sales_count'        => 4,
						'gross_total'        => 250.50,
						'net_total'          => 210.25,
						'discount_total'     => 15.00,
						'tip_total'          => 22.00,
						'attribution_count'  => 2,
						'commission_total'   => 18.50,
						'payout_total'       => 18.50,
						'expense_total'      => 40.00,
						'profit_total'       => 151.75,
					),
					array(
						'event_id'           => 11,
						'sales_count'        => 3,
						'gross_total'        => 180.00,
						'net_total'          => 160.00,
						'discount_total'     => 8.00,
						'tip_total'          => 12.00,
						'attribution_count'  => 1,
						'commission_total'   => 10.00,
						'payout_total'       => 10.00,
						'expense_total'      => 25.25,
						'profit_total'       => 124.75,
					),
				);
			}
		};

		$totals = $provider->get_summary_totals();

		$this->assertSame( 2, $totals['event_count'] );
		$this->assertSame( 7, $totals['sales_count'] );
		$this->assertSame( 430.5, $totals['gross_total'] );
		$this->assertSame( 370.25, $totals['net_total'] );
		$this->assertSame( 65.25, $totals['expense_total'] );
		$this->assertSame( 276.5, $totals['profit_total'] );
	}

	public function testGetRowsIncludesExpenseAndProfitFields(): void {
		$this->wpdb()->queue_results(
			array(
				array(
					'event_id'           => 21,
					'event_name'         => 'Spring Expo',
					'event_code'         => 'SE-21',
					'status'             => 'published',
					'sales_count'        => 5,
					'gross_total'        => '500.00',
					'net_total'          => '420.00',
					'discount_total'     => '30.00',
					'tip_total'          => '15.00',
					'attribution_count'  => 3,
					'commission_total'   => '25.00',
					'payout_total'       => '25.00',
					'expense_total'      => '100.00',
					'profit_total'       => '295.00',
				),
			)
		);

		$provider = new \AIMS_Reports_Event_Sales_Data_Provider();
		$rows     = $provider->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( '100.00', $rows[0]['expense_total'] );
		$this->assertSame( '295.00', $rows[0]['profit_total'] );
	}

	public function testPageRenderShowsTotalShowProfitSummaryAndTableColumn(): void {
		$provider = new class() extends \AIMS_Reports_Event_Sales_Data_Provider {
			public function get_rows( int $event_id = 0 ): array {
				return array(
					array(
						'event_id'           => 30,
						'event_name'         => 'Summer Show',
						'event_code'         => 'SUM-30',
						'status'             => 'published',
						'sales_count'        => 8,
						'gross_total'        => 640.00,
						'net_total'          => 550.00,
						'discount_total'     => 20.00,
						'tip_total'          => 35.00,
						'attribution_count'  => 2,
						'commission_total'   => 42.00,
						'payout_total'       => 42.00,
						'expense_total'      => 120.00,
						'profit_total'       => 388.00,
					),
				);
			}

			public function get_event_options(): array {
				return array(
					array(
						'id'   => 30,
						'name' => 'Summer Show',
					),
				);
			}
		};

		$page = new \AIMS_Reports_Event_Sales_Page( $provider );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Total Show Profit', $output );
		$this->assertStringContainsString( 'Expenses', $output );
		$this->assertStringContainsString( '$388.00', $output );
	}

	public function testReportsModuleExportIncludesTotalShowProfitColumns(): void {
		$auth = new class() extends \AIMS_Responsibility_Authorization_Service {
			public function __construct() {}
		};

		$module = new \AIMS_Reports_Module( $auth, new \AIMS_Reports_Event_Sales_Data_Provider() );

		$headers_method = new \ReflectionMethod( $module, 'get_event_sales_export_header_row' );
		$headers_method->setAccessible( true );
		$headers = $headers_method->invoke( $module );

		$this->assertContains( 'expense_total', $headers );
		$this->assertContains( 'total_show_profit', $headers );

		$row_method = new \ReflectionMethod( $module, 'build_event_sales_export_row' );
		$row_method->setAccessible( true );
		$row = $row_method->invoke(
			$module,
			array(
				'event_id'          => 77,
				'event_name'        => 'Profit Show',
				'event_code'        => 'PS-77',
				'status'            => 'published',
				'sales_count'       => 6,
				'gross_total'       => 500.00,
				'net_total'         => 420.00,
				'discount_total'    => 20.00,
				'tip_total'         => 15.00,
				'attribution_count' => 2,
				'commission_total'  => 30.00,
				'payout_total'      => 30.00,
				'expense_total'     => 90.00,
				'profit_total'      => 300.00,
			)
		);

		$this->assertSame( 90.0, $row[12] );
		$this->assertSame( 300.0, $row[13] );
	}
}
