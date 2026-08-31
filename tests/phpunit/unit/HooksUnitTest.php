<?php

namespace MediaWiki\Extension\SummaryToJiraComment;

/**
 * wfDebugLog() is a MediaWiki core global that is not loaded under
 * MediaWikiUnitTestCase. Hooks::sendToJira() calls it unqualified, so PHP
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
use MultiHttpClient;

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksUnitTest extends \MediaWikiUnitTestCase {

	protected function tearDown(): void {
		Hooks::$httpClient = null;
		parent::tearDown();
	}

	/**
	 * @covers ::sendToJira
	 */
	public function testSendToJira() {
		$config = [
			'https://jira.example.com',
			'token',
			'test@example.com'
		];
		$issueKey = 'TEST-1';
		$summary = 'Test summary';

		$httpClient = $this->createMock( MultiHttpClient::class );
		// body should be any non-empty array
		$response = [ 'code' => 201, 'body' => [ 'foo' => 'bar' ] ];
		$httpClient->method( 'run' )->willReturn( $response );

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendToJira( $config, $issueKey, $summary );

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

		Hooks::$httpClient = $httpClient;
		$result = Hooks::sendToJira( $config, 'TEST-1', 'Test summary' );

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
