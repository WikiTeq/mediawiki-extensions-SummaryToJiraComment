<?php

namespace MediaWiki\Extension\SummaryToJiraComment\Tests;

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\SummaryToJiraComment\Hooks;
use WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use User;

/**
 * @coversDefaultClass \MediaWiki\Extension\SummaryToJiraComment\Hooks
 */
class HooksIntegrationTest extends \MediaWikiIntegrationTestCase {

	protected function setUp() : void {
		parent::setUp();
		$this->overrideConfigValue( 'SummaryToJiraCommentInstance', 'jira.example.com' );
		$this->overrideConfigValue( 'SummaryToJiraCommentToken', '0xCAFEBEEF' );
		$this->overrideConfigValue( 'SummaryToJiraCommentEmail', 'test@example.com' );
	}

	/**
	 * @covers ::onPageSaveComplete
	 */
	public function testOnPageSaveComplete() {
		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$wikiPage = $this->createConfiguredMock( WikiPage::class,
			// We need a real title for getting a diff link
			[ 'getTitle' => $titleFactory->newFromText('Test page') ]
		);
		$user = $this->createMock( User::class );
		$summary = 'Test summary';
		$flags = 0;

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$editResult = $this->createMock( EditResult::class );
		$result = Hooks::onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult );

		$this->assertTrue( $result );
	}
}
