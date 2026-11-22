<?php

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
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

	private function makeWikiPage(): WikiPage {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		return $this->createConfiguredMock( WikiPage::class,
			// We need a real title for getting a diff link
			[ 'getTitle' => $titleFactory->newFromText( 'Test page' ) ]
		);
	}

	/**
	 * Register a mock HttpRequestFactory service that supplies the given
	 * client, and build the handler from the container-provided service.
	 * @param MultiHttpClient $httpClient
	 * @return Hooks
	 */
	private function newHooks( MultiHttpClient $httpClient ): Hooks {
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'createMultiClient' )->willReturn( $httpClient );
		$this->setService( 'HttpRequestFactory', $factory );

		return new Hooks( $this->getServiceContainer()->getHttpRequestFactory() );
	}

	/**
	 * @coversNothing
	 */
	public function testHookHandlerIsConstructedWithDeclaredServices() {
		$manifest = json_decode( file_get_contents( __DIR__ . '/../../../extension.json' ), true );
		$spec = $manifest['HookHandlers']['SummaryToJiraCommentHooks'] ?? null;
		$this->assertNotNull( $spec, 'HookHandlers entry is registered' );
		$this->assertSame( [ 'HttpRequestFactory' ], $spec['services'] );

		$handler = $this->getServiceContainer()->getObjectFactory()->createObject( $spec );
		$this->assertInstanceOf( Hooks::class, $handler );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveComplete() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturn( [ 'code' => 200, 'body' => '{}' ] );
		$hooks = $this->newHooks( $httpClient );

		$wikiPage = $this->makeWikiPage();
		$user = $this->createMock( User::class );
		$summary = 'Test summary';
		$flags = 0;

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );
		$result = $hooks->onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveCompletePostsInternalCommentForServiceDeskIssue() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturnCallback( function ( $req ) {
			if ( str_contains( $req['url'], '/rest/api/2/issue/' ) && $req['method'] === 'GET' ) {
				return [
					'code' => 200,
					'body' => json_encode( [ 'fields' => [ 'project' => [ 'projectTypeKey' => 'service_desk' ] ] ] ),
				];
			}
			if ( str_contains( $req['url'], '/rest/servicedeskapi/request/' ) ) {
				return [ 'code' => 201, 'body' => '{}' ];
			}
			$this->fail( 'Unexpected request to ' . $req['url'] . ' — SD issue must not receive a public comment.' );
		} );
		$hooks = $this->newHooks( $httpClient );

		$wikiPage = $this->makeWikiPage();
		$user = $this->createMock( User::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );

		$result = $hooks->onPageSaveComplete(
			$wikiPage, $user, 'SD-1 test summary', 0, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveCompletePostsPublicCommentForNonServiceDeskIssue() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->method( 'run' )->willReturnCallback( function ( $req ) {
			if ( str_contains( $req['url'], '/rest/api/2/issue/' ) && $req['method'] === 'GET' ) {
				return [
					'code' => 200,
					'body' => json_encode( [ 'fields' => [ 'project' => [ 'projectTypeKey' => 'software' ] ] ] ),
				];
			}
			if ( str_contains( $req['url'], '/rest/api/2/issue/' ) && $req['method'] === 'POST' ) {
				return [ 'code' => 201, 'body' => '{}' ];
			}
			$this->fail( 'Unexpected request to ' . $req['url'] . ' — non-SD issue must not use servicedeskapi.' );
		} );
		$hooks = $this->newHooks( $httpClient );

		$wikiPage = $this->makeWikiPage();
		$user = $this->createMock( User::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );

		$result = $hooks->onPageSaveComplete(
			$wikiPage, $user, 'PROJ-1 test summary', 0, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveCompleteSkipsCommentWhenServiceDeskStatusUnknown() {
		$httpClient = $this->createMock( MultiHttpClient::class );
		$httpClient->expects( $this->once() )->method( 'run' )
			->willReturn( [ 'code' => 500, 'body' => '' ] );
		$hooks = $this->newHooks( $httpClient );

		$wikiPage = $this->makeWikiPage();
		$user = $this->createMock( User::class );
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );

		// expects( $this->once() ) above asserts only the detection GET
		// happens — no comment POST follows when status is unknown.
		$result = $hooks->onPageSaveComplete(
			$wikiPage, $user, 'PROJ-1 test summary', 0, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}
}
