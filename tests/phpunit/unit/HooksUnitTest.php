<?php

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use MultiHttpClient;

/**
 * wfDebugLog() is a MediaWiki core global that is not loaded under
 * MediaWikiUnitTestCase; provide a namespace-local stub so production code
 * calling it remains testable.
 */
function wfDebugLog( $logGroup, $text, $dest = 'all', array $context = [] ) {
}

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksUnitTest extends \MediaWikiUnitTestCase {

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
	 * @dataProvider provideIsConfigured
	 * @covers ::isConfigured
	 */
	public function testIsConfigured( array $config, bool $expected ) {
		$this->assertSame( $expected, Hooks::isConfigured( $config ) );
	}

	public static function provideIsConfigured(): array {
		return [
			'shipped placeholder defaults' => [
				[ 'jira.atlassian.com', '', 'example@atlassian.com' ],
				false
			],
			'default instance with real token and email' => [
				[ 'jira.atlassian.com', 'token', 'user@example.com' ],
				false
			],
			'default email' => [
				[ 'jira.example.com', 'token', 'example@atlassian.com' ],
				false
			],
			'empty instance' => [ [ '', 'token', 'user@example.com' ], false ],
			'empty token' => [ [ 'jira.example.com', '', 'user@example.com' ], false ],
			'real configuration' => [
				[ 'jira.example.com', 'token', 'user@example.com' ],
				true
			],
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
