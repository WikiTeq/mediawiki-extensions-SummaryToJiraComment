<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\SummaryToJiraComment;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use MultiHttpClient;
use WikiPage;

class Hooks {

	/**
	 * @var MultiHttpClient
	 */
	public static MultiHttpClient $httpClient;

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return bool
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $user,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult ): bool {
		$diffLink = self::getDiffLink( $wikiPage, $revisionRecord );
		$config = [
			MediaWikiServices::getInstance()->getMainConfig()->get( 'SummaryToJiraCommentInstance' ),
			MediaWikiServices::getInstance()->getMainConfig()->get( 'SummaryToJiraCommentToken' ),
			MediaWikiServices::getInstance()->getMainConfig()->get( 'SummaryToJiraCommentEmail' )
		];
		if ( !self::isConfigured( $config ) ) {
			return true;
		}
		$title = $wikiPage->getTitle();
		$issueKeys = self::getJiraIssueKeys( $summary );
		$author = $user->getName();

		$summary = '';
		foreach ( $issueKeys as $issueKey ) {
			$summary .= "\nTitle: " . $title->getFullText();
			$summary .= "\nDiff: " . $diffLink;
			$summary .= "\nAuthor: " . $author;
			self::sendToJira( $config, $issueKey, $summary );
		}

		return true;
	}

	/**
	 * strip out the issue keys from the summary
	 * @param string $summary
	 * @return array
	 */
	public static function getJiraIssueKeys( $summary ): array {
		$issueKeys = [];
		$issueKeyRegex = '/\b([A-Z][A-Z0-9]*-[0-9]+)\b/';
		$matches = [];
		preg_match_all( $issueKeyRegex, $summary, $matches );
		if ( isset( $matches[1] ) ) {
			$issueKeys = $matches[1];
		}

		return $issueKeys;
	}

	/**
	 * Check whether real Jira credentials have been configured.
	 *
	 * The shipped defaults in extension.json are placeholders; without this
	 * guard, every edit mentioning a Jira key sends wiki metadata to
	 * https://jira.atlassian.com with empty credentials.
	 *
	 * @param array $config [ instance, token, email ]
	 * @return bool
	 */
	public static function isConfigured( array $config ): bool {
		[ $instance, $token, $email ] = $config;

		if ( $token === '' || $token === null || !is_string( $instance ) ||
			$instance === '' || $instance === 'jira.atlassian.com' ||
			$email === '' || $email === null || $email === 'example@atlassian.com' ) {
			wfDebugLog( 'SummaryToJiraComment', __METHOD__ .
				': extension is not configured, skipping Jira comment' );
			return false;
		}

		return true;
	}

	/**
	 * Send the comment to Jira using the Jira API
	 * @param array $config
	 * @param string $issueKey
	 * @param string $summary
	 * @return bool
	 */
	public static function sendToJira( $config, $issueKey, $summary ): bool {
		[ $instance, $token, $email ] = $config;
		$hash = base64_encode( $email . ':' . $token );

		self::$httpClient = new MultiHttpClient( [ 'maxRetries' => 3 ] );

		try {
			self::$httpClient->run( [
				'headers' => [
					'Authorization' => 'Basic ' . $hash,
					'Content-Type' => 'application/json',
				],
				'url' => 'https://' . $instance . '/rest/api/2/issue/' . $issueKey . '/comment',
				'method' => 'POST',
				'body' => json_encode( [
					'body' => $summary
				] )
			] );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the diff link for the revision
	 * @param WikiPage $wikiPage
	 * @param RevisionRecord $revisionRecord
	 * @return string
	 */
	private static function getDiffLink( WikiPage $wikiPage, RevisionRecord $revisionRecord ): string {
		$diffLink = $wikiPage->getTitle()->getFullURL();
		$currentRevision = $revisionRecord->getId();
		$oldRevision = $revisionRecord->getParentId();

		$params = [
			'diff' => $currentRevision,
			'oldid' => $oldRevision
		];

		return wfAppendQuery( $diffLink, $params );
	}

}
