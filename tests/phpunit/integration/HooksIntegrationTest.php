<?php

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use MultiHttpClient;
use User;
use WikiPage;

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksIntegrationTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'SummaryToJiraCommentInstance', 'jira.example.com' );
		$this->overrideConfigValue( 'SummaryToJiraCommentToken', '0xCAFEBEEF' );
		$this->overrideConfigValue( 'SummaryToJiraCommentEmail', 'test@example.com' );
	}

	protected function tearDown(): void {
		Hooks::$httpClient = null;
		parent::tearDown();
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveComplete() {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$wikiPage = $this->createConfiguredMock( WikiPage::class,
			// We need a real title for getting a diff link
			[ 'getTitle' => $titleFactory->newFromText( 'Test page' ) ]
		);
		$user = $this->createMock( User::class );
		$summary = 'Test summary';
		$flags = 0;

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );
		$result = Hooks::onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveCompleteSendsSameCommentBodyToEachIssue() {
		$commentBodies = [];
		$requestUrls = [];
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturnCallback(
			static function ( array $request ) use ( &$commentBodies, &$requestUrls ) {
				$payload = json_decode( $request['body'], true );
				$commentBodies[] = $payload['body'];
				$requestUrls[] = $request['url'];
				return [ 'code' => 201, 'body' => [] ];
			}
		);
		Hooks::$httpClient = $httpClient;

		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$title = $titleFactory->newFromText( 'Test page' );
		$wikiPage = $this->createConfiguredMock( WikiPage::class, [ 'getTitle' => $title ] );

		$user = $this->createMock( UserIdentity::class );
		$user->method( 'getName' )->willReturn( 'TestUser' );

		$revisionRecord = $this->createConfiguredMock( RevisionRecord::class, [
			'getId' => 2,
			'getParentId' => 1,
		] );
		$editResult = $this->createMock( EditResult::class );

		$result = Hooks::onPageSaveComplete(
			$wikiPage,
			$user,
			'Fix for TEST-1 and TEST-2',
			0,
			$revisionRecord,
			$editResult
		);

		$this->assertTrue( $result );
		$this->assertCount( 2, $commentBodies );
		$this->assertSame( $commentBodies[0], $commentBodies[1] );
		$this->assertSame(
			[
				'https://jira.example.com/rest/api/2/issue/TEST-1/comment',
				'https://jira.example.com/rest/api/2/issue/TEST-2/comment',
			],
			$requestUrls
		);
		$this->assertStringContainsString( 'Title: Test page', $commentBodies[0] );
		$this->assertStringContainsString( 'Author: TestUser', $commentBodies[0] );
	}
}
