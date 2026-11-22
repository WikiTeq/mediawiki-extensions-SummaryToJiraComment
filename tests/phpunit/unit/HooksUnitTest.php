<?php

namespace MediaWiki\Extension\SummaryToJiraComment;

/**
 * wfDebugLog() is a MediaWiki core global that is not loaded under
 * MediaWikiUnitTestCase. $this->newHooks( $httpClient )->sendToJira() calls it unqualified, so PHP
 * resolves it against the Hooks class's own namespace (falling back to
 * global only if no namespaced function exists) — the stub must live here,
 * not in the Tests sub-namespace, or it will never be found and the
 * failure-path tests below will fatal instead of asserting false.
 * @param string $logGroup
 * @param string $text
 * @param string|bool $dest
 * @param array $context
 */
function wfDebugLog( $logGroup, $text, $dest = 'all', array $context = [] ) {
}

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use MediaWiki\Http\HttpRequestFactory;
use MultiHttpClient;

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksUnitTest extends \MediaWikiUnitTestCase {

	private const CONFIG = [
		'jira.example.com',
		'token',
		'test@example.com'
	];

	/**
	 * Build a handler whose HttpRequestFactory supplies the given client.
	 * @param MultiHttpClient $httpClient
	 * @return Hooks
	 */
	private function newHooks( MultiHttpClient $httpClient ): Hooks {
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'createMultiClient' )->willReturn( $httpClient );
		return new Hooks( $factory );
	}

	/**
	 * @covers ::__construct
	 * @covers ::getHttpClient
	 */
	public function testGetHttpClientCreatesClientThroughFactory() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->expects( $this->once() )->method( 'run' )
			->willReturn( [ 'code' => 201, 'body' => '{}' ] );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->once() )->method( 'createMultiClient' )
			->with( [ 'maxRetries' => 3 ] )
			->willReturn( $httpClient );

		$result = ( new Hooks( $factory ) )->sendToJira( self::CONFIG, 'TEST-1', 'Test summary' );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::getHttpClient
	 */
	public function testGetHttpClientIsReusedAcrossRequests() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->expects( $this->exactly( 2 ) )->method( 'run' )
			->willReturn( [ 'code' => 201, 'body' => '{}' ] );

		// The factory must be asked for a client only once, even though the
		// handler sends two requests.
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->expects( $this->once() )->method( 'createMultiClient' )
			->willReturn( $httpClient );

		$hooks = new Hooks( $factory );
		$this->assertTrue( $hooks->sendToJira( self::CONFIG, 'TEST-1', 'Test summary' ) );
		$this->assertTrue( $hooks->sendInternalCommentToJira( self::CONFIG, 'SD-1', 'Test summary' ) );
	}

	/**
	 * @covers ::sendToJira
	 */
	public function testSendToJira() {
		$issueKey = 'TEST-1';
		$summary = 'Test summary';

		$httpClient = $this->createMock( MultiHttpClient::class );
		// body should be any non-empty array
		$response = [ 'code' => 201, 'body' => [ 'foo' => 'bar' ] ];
		$httpClient->method( 'run' )->willReturn( $response );

		$result = $this->newHooks( $httpClient )->sendToJira( self::CONFIG, $issueKey, $summary );

		$this->assertTrue( $result );
	}

	/**
	 * @dataProvider provideFailedResponses
	 * @covers ::sendToJira
	 */
	public function testSendToJiraFailure( array $response ) {
		$config = [
			'https://jira.example.com',
			'token',
			'test@example.com'
		];

		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturn( $response );

		$result = $this->newHooks( $httpClient )->sendToJira( $config, 'TEST-1', 'Test summary' );

		$this->assertFalse( $result );
	}

	public static function provideFailedResponses(): array {
		return [
			'unauthorized' => [ [ 'code' => 401, 'error' => 'Unauthorized' ] ],
			'server error' => [ [ 'code' => 500, 'error' => 'Internal Server Error' ] ],
			// MultiHttpClient uses code 0 for transport-level failures (DNS,
			// timeouts, ...) and never throws for HTTP errors.
			'transport failure' => [ [ 'code' => 0, 'error' => '(curl error: 6)' ] ],
			'missing code' => [ [ 'error' => '(curl error: no status set)' ] ],
		];
	}

	/**
	 * @covers ::sendToJira
	 */
	public function testSendToJiraReturnsFalseOnThrownException() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willThrowException( new \RuntimeException( 'network error' ) );

		$result = $this->newHooks( $httpClient )->sendToJira( self::CONFIG, 'TEST-1', 'Test summary' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::sendInternalCommentToJira
	 */
	public function testSendInternalCommentToJira() {
		$issueKey = 'SD-1';
		$summary = 'Test summary';

		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->expects( $this->once() )->method( 'run' )
			->with( $this->callback( static function ( $req ) use ( $issueKey ) {
				$body = json_decode( $req['body'], true );
				return $req['method'] === 'POST'
					&& $req['url'] === 'https://jira.example.com/rest/servicedeskapi/request/' . $issueKey . '/comment'
					&& $body['public'] === false;
			} ) )
			->willReturn( [ 'code' => 201, 'body' => '{}' ] );

		$result = $this->newHooks( $httpClient )->sendInternalCommentToJira( self::CONFIG, $issueKey, $summary );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::sendInternalCommentToJira
	 */
	public function testSendInternalCommentToJiraReturnsFalseOnHttpError() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturn( [ 'code' => 404, 'body' => '' ] );

		$result = $this->newHooks( $httpClient )->sendInternalCommentToJira( self::CONFIG, 'SD-1', 'Test summary' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::getJiraIssueKeys
	 */
	public function testGetJiraIssueKeys() {
		$summary = 'TEST-1 Test summary TEST1-1 11-22 test-11 R2-D2 C-3PO BB-8 THX 1138 TEST1T2-3  ' .
		 ' #TEST1-7 A0-3 WW-II EMDASH—1 ENDASH–2 DOUBLEDASH--3 [BRACKET-1] (PAREN-2)';
		$issueKeys = Hooks::getJiraIssueKeys( $summary );

		$this->assertSame(
			[ 'TEST-1', 'TEST1-1', 'BB-8', 'TEST1T2-3', 'TEST1-7', 'A0-3', 'BRACKET-1', 'PAREN-2' ],
			$issueKeys
		);
	}
}
