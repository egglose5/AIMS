<?php

declare( strict_types=1 );

namespace AIMS\Tests\Unit;

use AIMS\Core\Clients\SquareClient;
use AIMS\Tests\Support\FakeHttpTransport;

final class SquareClientProvisioningTest extends \AIMS\Tests\TestCase {
	public function testCreateLocationAndTeamMemberSendProvisioningRequests(): void {
		$transport = new FakeHttpTransport(
			array(
				'/v2/locations' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						'location' => array(
							'id'   => 'LOC-901',
							'name' => 'Vendor South',
						),
					),
				),
				'/v2/team-members' => array(
					'success' => true,
					'status'  => 200,
					'json'    => array(
						'team_member' => array(
							'id'           => 'TM-901',
							'display_name' => 'Vendor South',
							'status'       => 'ACTIVE',
						),
					),
				),
			)
		);

		$client        = new SquareClient( 'https://square.example.test', 'access-token', $transport );
		$location      = $client->createLocation( array( 'name' => 'Vendor South' ) );
		$team_member   = $client->createTeamMember(
			array(
				'display_name' => 'Vendor South',
				'reference_id' => 'VEN-901',
			)
		);

		$this->assertSame( 'LOC-901', $location['id'] );
		$this->assertSame( 'Vendor South', $location['name'] );
		$this->assertSame( 'TM-901', $team_member['id'] );
		$this->assertCount( 2, $transport->requests );
		$this->assertSame( 'POST', $transport->requests[0]['method'] );
		$this->assertSame( 'POST', $transport->requests[1]['method'] );
	}
}
