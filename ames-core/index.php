<?php

declare( strict_types=1 );

use AmesCore\Archive\ArchiveService;
use AmesCore\Core\OAuth\OAuthService;
use AmesCore\Core\Security\Cryptographer;
use AmesCore\Core\Security\SecretStore;
use AmesCore\Headless\CoreConfig;
use AmesCore\Headless\Security\TokenAuthenticator;
use AmesCore\Headless\Storage\SqliteBucketFifoStore;
use AmesCore\Headless\Storage\FlowParquetArchiveWriter;
use AmesCore\Headless\Storage\FlowParquetHistoryReader;
use AmesCore\Headless\Storage\SqliteLedgerRepository;
use AmesCore\Headless\Support\EnvLoader;
use AmesCore\Headless\Support\FileLogger;
use AmesCore\Headless\Sync\RemoteTruthService;
use AmesCore\Inventory\BucketFifoService;

$root = __DIR__;

foreach ( array( $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', dirname( $root ) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' ) as $autoloadPath ) {
	if ( file_exists( $autoloadPath ) ) {
		require_once $autoloadPath;
		break;
	}
}

spl_autoload_register(
	static function ( string $className ) use ( $root ): void {
		$prefixMap = array(
			'AmesCore\\'   => $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
			'AIMS\\Core\\' => dirname( $root ) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
		);

		foreach ( $prefixMap as $prefix => $basePath ) {
			if ( 0 !== strpos( $className, $prefix ) ) {
				continue;
			}

			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $className, strlen( $prefix ) ) );
			$path     = $basePath . $relative . '.php';

			if ( file_exists( $path ) ) {
				require_once $path;
			}

			return;
		}
	}
);

( new EnvLoader() )->load( $root . DIRECTORY_SEPARATOR . '.env' );

$config = CoreConfig::fromRoot( $root );
$config->ensureDirectories();

$logger       = new FileLogger( $config->logsPath() );
$auth         = new TokenAuthenticator( $config->sharedSecret(), $config->archiveSecret() );
$ledger       = new SqliteLedgerRepository( $config->sqlitePath() );
$bucketStore  = new SqliteBucketFifoStore( $config->sqlitePath() );
$bucketFifo   = new BucketFifoService( $bucketStore );
$cryptographer = new Cryptographer( $config->encryptionKey() );
$secretStore  = new SecretStore( $config->secretStorePath(), $cryptographer );
$oauth        = new OAuthService( $secretStore );
$remote       = new RemoteTruthService( $config, $secretStore );
$archive = new ArchiveService( $ledger, new FlowParquetArchiveWriter(), $config->vaultPath() );
$history = new FlowParquetHistoryReader();

try {
	$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
	$path   = resolve_request_path();
	$query  = is_array( $_GET ) ? $_GET : array();

	if ( 'GET' === $method && ( '/' === $path || '/health' === $path ) ) {
		json_response(
			array(
				'service' => 'AIMS Core',
				'status'  => 'ok',
				'paths'   => array(
					'sink'  => $config->sinkPath(),
					'vault' => $config->vaultPath(),
					'config'=> $config->configPath(),
					'logs'  => $config->logsPath(),
				),
				'capabilities' => array(
					'pdo_sqlite'        => extension_loaded( 'pdo_sqlite' ),
					'flow_php_parquet'  => class_exists( '\Flow\Parquet\Writer' ) && class_exists( '\Flow\Parquet\Reader' ),
					'openssl'           => function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ),
				),
				'routes' => array( 'POST /move', 'GET /manifest', 'POST /manifest/push', 'GET /history', 'GET /internal/archive', 'GET /internal/square/pull', 'POST /internal/square/locations', 'POST /internal/square/team-members', 'GET /internal/square/holdings', 'POST /internal/secrets/{provider}', 'POST /oauth/{provider}/authorize', 'GET /oauth/{provider}/callback', 'GET /oauth/{provider}/status', 'GET /buckets', 'POST /buckets', 'POST /fifo/receive', 'POST /custody/move', 'GET /fifo/availability', 'POST /fifo/pick' ),
			)
		);
	}

	$ledger->initialize();
	$bucketFifo->initialize();

	if ( 'POST' === $method && '/move' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $ledger->recordMove( json_request_body() );
		$logger->info( 'movement.recorded', $result );
		json_response( array( 'ok' => true, 'move' => $result, 'message' => 'Movement recorded in the AIMS sink.' ), 201 );
	}

	if ( 'GET' === $method && '/buckets' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		json_response(
			array(
				'ok'      => true,
				'buckets' => $bucketFifo->buckets( $query ),
			)
		);
	}

	if ( 'POST' === $method && '/buckets' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $bucketFifo->registerBucket( json_request_body() );
		$logger->info( 'bucket.registered', $result );
		json_response( array( 'ok' => true, 'bucket' => $result ), 201 );
	}

	if ( 'POST' === $method && '/fifo/receive' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $bucketFifo->receive( json_request_body() );
		$logger->info( 'fifo.received', $result );
		json_response( array( 'ok' => true, 'receipt' => $result ), 201 );
	}

	if ( 'POST' === $method && '/custody/move' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $bucketFifo->moveCustody( json_request_body() );
		$logger->info( 'bucket.custody_moved', $result );
		json_response( array( 'ok' => true, 'movement' => $result ) );
	}

	if ( 'GET' === $method && '/fifo/availability' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		json_response(
			array(
				'ok'           => true,
				'availability' => $bucketFifo->availability( $query ),
			)
		);
	}

	if ( 'POST' === $method && '/fifo/pick' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $bucketFifo->pick( json_request_body() );
		$logger->info( 'fifo.picked', $result );
		json_response( array( 'ok' => true, 'pick' => $result ) );
	}

	if ( 'GET' === $method && '/manifest' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		json_response( $remote->buildManifest( $ledger, $query ) );
	}

	if ( 'POST' === $method && '/manifest/push' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$result = $remote->pushManifest( json_request_body() );
		$logger->info( 'manifest.pushed', $result );
		json_response( array( 'ok' => true, 'result' => $result, 'message' => 'Manifest push completed.' ) );
	}

	if ( route_match( $path, '#^/internal/secrets/([a-z0-9_\-]+)$#i', $matches ) && 'POST' === $method ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$provider = strtolower( (string) $matches[1] );
		$payload  = json_request_body();
		$secretStore->mergeProvider( $provider, $payload );
		$logger->info( 'secret_store.updated', array( 'provider' => $provider, 'keys' => array_keys( $payload ) ) );
		json_response(
			array(
				'ok'       => true,
				'provider' => $provider,
				'status'   => secret_status( $secretStore, $provider ),
				'message'  => 'Adapter secrets stored in encrypted SecretStore.',
			),
			201
		);
	}

	if ( route_match( $path, '#^/oauth/([a-z0-9_\-]+)/authorize$#i', $matches ) && 'POST' === $method ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$provider = strtolower( (string) $matches[1] );
		$result   = $oauth->beginAuthorization( $provider, json_request_body() );
		$logger->info( 'oauth.authorize.started', array( 'provider' => $provider ) );
		json_response( array( 'ok' => true, 'provider' => $provider, 'oauth' => $result ) );
	}

	if ( route_match( $path, '#^/oauth/([a-z0-9_\-]+)/callback$#i', $matches ) && 'GET' === $method ) {
		$provider = strtolower( (string) $matches[1] );
		$result   = $oauth->exchangeAuthorizationCode( $provider, $query );
		$logger->info( 'oauth.callback.completed', array( 'provider' => $provider ) );
		json_response(
			array(
				'ok'       => true,
				'provider' => $provider,
				'status'   => $result,
				'message'  => 'OAuth tokens stored in the encrypted SecretStore.',
			)
		);
	}

	if ( route_match( $path, '#^/oauth/([a-z0-9_\-]+)/status$#i', $matches ) && 'GET' === $method ) {
		$auth->assertAuthorized( $_SERVER, $query, false );
		$provider = strtolower( (string) $matches[1] );
		json_response(
			array(
				'ok'       => true,
				'provider' => $provider,
				'status'   => $oauth->status( $provider ),
			)
		);
	}

	if ( 'GET' === $method && '/internal/archive' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, true );

		$requestedShowId = trim( (string) ( $query['show_id'] ?? '' ) );
		$requestedYear   = isset( $query['year'] ) ? (int) $query['year'] : 0;
		$results         = array();

		foreach ( $ledger->archiveTargets( $requestedShowId ) as $target ) {
			$showId = (string) ( $target['show_id'] ?? '' );
			if ( '' === $showId ) {
				continue;
			}

			$results[] = $archive->archiveShow( $showId, $requestedYear > 0 ? $requestedYear : (int) ( $target['year'] ?? (int) gmdate( 'Y' ) ) );
		}

		$logger->info( 'archive.completed', array( 'count' => count( $results ) ) );
		json_response(
			array(
				'ok'       => true,
				'archived' => $results,
				'message'  => 0 === count( $results ) ? 'No hot rows were available for archival.' : 'Archive run completed.',
			)
		);
	}

	if ( 'GET' === $method && '/internal/square/pull' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, true );
		$result = $remote->pullSquareThinClientWindow( $query );
		$logger->info( 'square.thin_client_pull', array( 'pulled_count' => $result['pulled_count'] ?? 0 ) );
		json_response(
			array(
				'ok'     => true,
				'result' => $result,
			)
		);
	}

	if ( 'POST' === $method && '/internal/square/locations' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, true );
		$result = $remote->createSquareLocation( json_request_body() );
		$logger->info( 'square.location_create', array( 'success' => $result['success'] ?? false ) );
		json_response(
			array(
				'ok'      => ! empty( $result['success'] ),
				'result'  => $result,
				'location'=> $result['location'] ?? null,
			),
			empty( $result['success'] ) ? 502 : 201
		);
	}

	if ( 'POST' === $method && '/internal/square/team-members' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, true );
		$result = $remote->createSquareTeamMember( json_request_body() );
		$logger->info( 'square.team_member_create', array( 'success' => $result['success'] ?? false ) );
		json_response(
			array(
				'ok'          => ! empty( $result['success'] ),
				'result'      => $result,
				'team_member' => $result['team_member'] ?? null,
			),
			empty( $result['success'] ) ? 502 : 201
		);
	}

	if ( 'GET' === $method && '/internal/square/holdings' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, true );
		$result = $remote->fetchSquareLocationHoldings( $query );
		$logger->info( 'square.holdings_read', array( 'count' => count( (array) ( $result['counts'] ?? array() ) ) ) );
		json_response(
			array(
				'ok'     => ! empty( $result['success'] ),
				'result' => $result,
			)
		);
	}

	if ( 'GET' === $method && '/history' === $path ) {
		$auth->assertAuthorized( $_SERVER, $query, false );

		$showId   = trim( (string) ( $query['show_id'] ?? '' ) );
		$source   = strtolower( trim( (string) ( $query['source'] ?? 'all' ) ) );
		$limit    = max( 1, min( 2000, (int) ( $query['limit'] ?? 500 ) ) );
		$warnings = array();
		$rows     = array();

		if ( 'all' === $source || 'hot' === $source ) {
			$rows = array_merge( $rows, $ledger->movementHistory( $showId, $limit ) );
		}

		if ( 'all' === $source || 'vault' === $source ) {
			try {
				foreach ( $history->readVault( $config->vaultPath(), $showId ) as $row ) {
					$rows[] = $row;
					if ( count( $rows ) >= $limit ) {
						break;
					}
				}
			} catch ( Throwable $exception ) {
				$warnings[] = $exception->getMessage();
			}
		}

		stream_json_rows(
			array(
				'ok'       => true,
				'show_id'  => '' !== $showId ? $showId : null,
				'source'   => $source,
				'warnings' => $warnings,
			),
			$rows
		);
		exit;
	}

	json_response( array( 'ok' => false, 'message' => 'Route not found.', 'method' => $method, 'path' => $path ), 404 );
} catch ( InvalidArgumentException $exception ) {
	$logger->error( 'request.invalid', array( 'message' => $exception->getMessage() ) );
	json_response( array( 'ok' => false, 'message' => $exception->getMessage() ), 422 );
} catch ( RuntimeException $exception ) {
	$logger->error( 'request.runtime_error', array( 'message' => $exception->getMessage() ) );
	json_response( array( 'ok' => false, 'message' => $exception->getMessage() ), 500 );
} catch ( Throwable $exception ) {
	$logger->error( 'request.unhandled', array( 'message' => $exception->getMessage(), 'type' => $exception::class ) );
	json_response( array( 'ok' => false, 'message' => 'Unhandled AIMS core error.', 'detail' => $exception->getMessage() ), 500 );
}

function resolve_request_path(): string {
	$requestUri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
	$path       = (string) parse_url( $requestUri, PHP_URL_PATH );
	$scriptDir  = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ), '/' );

	if ( '' !== $scriptDir && '/' !== $scriptDir && str_starts_with( $path, $scriptDir ) ) {
		$path = substr( $path, strlen( $scriptDir ) );
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : rtrim( $path, '/' );
}

/**
 * @param array<int, string> $matches
 */
function route_match( string $path, string $pattern, ?array &$matches = null ): bool {
	$result = preg_match( $pattern, $path, $matches );
	return 1 === $result;
}

/**
 * @return array<string, mixed>
 */
function json_request_body(): array {
	$raw = file_get_contents( 'php://input' );

	if ( false === $raw || '' === trim( $raw ) ) {
		return array();
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		throw new InvalidArgumentException( 'Request body must be valid JSON.' );
	}

	return $decoded;
}

/**
 * @param array<string, mixed> $payload
 */
function json_response( array $payload, int $statusCode = 200 ): void {
	http_response_code( $statusCode );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}

/**
 * @param array<string, mixed> $meta
 * @param array<int, array<string, mixed>> $rows
 */
function stream_json_rows( array $meta, array $rows ): void {
	http_response_code( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );

	echo '{';
	echo '"meta":' . json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	echo ',"rows":[';

	$first = true;
	foreach ( $rows as $row ) {
		if ( ! $first ) {
			echo ',';
		}

		echo json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$first = false;

		if ( function_exists( 'flush' ) ) {
			flush();
		}
	}

	echo ']}';
}

/**
 * @return array<string, mixed>
 */
function secret_status( SecretStore $secretStore, string $provider ): array {
	$payload = $secretStore->getProvider( $provider );

	return array(
		'provider'          => $provider,
		'configured'        => ! empty( $payload ),
		'keys'              => array_values( array_keys( $payload ) ),
		'has_access_token'  => ! empty( $payload['access_token'] ?? null ),
		'has_refresh_token' => ! empty( $payload['refresh_token'] ?? null ),
	);
}
