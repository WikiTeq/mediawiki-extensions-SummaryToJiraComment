<?php

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use MultiHttpClient;

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksUnitTest extends \MediaWikiUnitTestCase {

	// Per README.md, $wgSummaryToJiraCommentInstance is documented as a bare
	// hostname (e.g. "<instance>.atlassian.net") — Hooks always prepends
	// "https://" itself. Using a bare hostname here (unlike the pre-existing
	// testSendToJira fixture, which uses a full URL and never asserts the
	// constructed request URL) so the new tests below can assert exact URLs
	// without tripping over a doubled "https://https://".
	private const CONFIG = [
		'jira.example.com',
		'token',
		'test@example.com'
	];

	/**
	 * @covers ::getHttpClient
	 */
	public function testGetHttpClientReusesInjectedMock() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->expects( $this->once() )->method( 'run' )
			->willReturn( [ 'code' => 201, 'body' => '{}' ] );

		Hooks::$httpClient = $httpClient;
		// If getHttpClient() overwrote the mock, this call would hit the
		// network instead of the mock and the expects(once) assertion above
		// would fail.
		Hooks::sendToJira( self::CONFIG, 'TEST-1', 'Test summary' );
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

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendToJira( self::CONFIG, $issueKey, $summary );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::sendToJira
	 */
	public function testSendToJiraReturnsFalseOnHttpError() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturn( [ 'code' => 500, 'body' => '' ] );

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendToJira( self::CONFIG, 'TEST-1', 'Test summary' );

		$this->assertFalse( $result );
	}

	/**
	 * @covers ::sendToJira
	 */
	public function testSendToJiraReturnsFalseOnThrownException() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willThrowException( new \RuntimeException( 'network error' ) );

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendToJira( self::CONFIG, 'TEST-1', 'Test summary' );

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

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendInternalCommentToJira( self::CONFIG, $issueKey, $summary );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::sendInternalCommentToJira
	 */
	public function testSendInternalCommentToJiraReturnsFalseOnHttpError() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturn( [ 'code' => 404, 'body' => '' ] );

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendInternalCommentToJira( self::CONFIG, 'SD-1', 'Test summary' );

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
