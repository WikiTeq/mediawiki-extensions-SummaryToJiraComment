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

	private const SD_STATUS_SD = 'sd';
	private const SD_STATUS_NOT_SD = 'not_sd';
	private const SD_STATUS_UNKNOWN = 'unknown';

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
		$title = $wikiPage->getTitle();
		$issueKeys = self::getJiraIssueKeys( $summary );
		$author = $user->getName();

		$summary = '';
		foreach ( $issueKeys as $issueKey ) {
			$summary .= "\nTitle: " . $title->getFullText();
			$summary .= "\nDiff: " . $diffLink;
			$summary .= "\nAuthor: " . $author;

			$status = self::getServiceDeskStatus( $config, $issueKey );
			if ( self::SD_STATUS_SD === $status ) {
				self::sendInternalCommentToJira( $config, $issueKey, $summary );
			} elseif ( self::SD_STATUS_NOT_SD === $status ) {
				self::sendToJira( $config, $issueKey, $summary );
			}
			// SD_STATUS_UNKNOWN: skip posting entirely, never fall back to a
			// public comment when we can't confirm the issue isn't a Service
			// Desk request.
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
	 * Lazily construct the shared HTTP client, reusing any client already
	 * assigned (e.g. a mock injected by tests) instead of overwriting it.
	 * Phan doesn't model the "uninitialized typed static property" state, so
	 * it considers the isset() check below always-true; it's correct PHP
	 * regardless, and is what lets tests inject a mock via
	 * Hooks::$httpClient before the first real call.
	 * @return MultiHttpClient
	 */
	private static function getHttpClient(): MultiHttpClient {
		// @phan-suppress-next-line PhanRedundantCondition
		if ( !isset( self::$httpClient ) ) {
			self::$httpClient = new MultiHttpClient( [ 'maxRetries' => 3 ] );
		}

		return self::$httpClient;
	}

	/**
	 * Build the HTTP Basic auth header value from the configured email/token.
	 * @param array $config
	 * @return string
	 */
	private static function getAuthHash( $config ): string {
		[ , $token, $email ] = $config;
		return base64_encode( $email . ':' . $token );
	}

	/**
	 * POST a JSON-encoded comment body to a Jira REST endpoint.
	 * @param string $url
	 * @param string $hash
	 * @param array $body
	 * @return bool
	 */
	private static function postComment( string $url, string $hash, array $body ): bool {
		try {
			$response = self::getHttpClient()->run( [
				'headers' => [
					'Authorization' => 'Basic ' . $hash,
					'Content-Type' => 'application/json',
				],
				'url' => $url,
				'method' => 'POST',
				'body' => json_encode( $body )
			] );
		} catch ( \Exception $e ) {
			return false;
		}

		return ( $response['code'] ?? 0 ) >= 200 && ( $response['code'] ?? 0 ) < 300;
	}

	/**
	 * Determine whether a Jira issue belongs to a Service Desk project.
	 * Returns SD_STATUS_UNKNOWN (rather than SD_STATUS_NOT_SD) on any
	 * failure to detect the project type, since defaulting to "not SD" would
	 * risk posting a public comment on what may actually be a Service Desk
	 * request.
	 * @param array $config
	 * @param string $issueKey
	 * @return string one of SD_STATUS_SD, SD_STATUS_NOT_SD, SD_STATUS_UNKNOWN
	 */
	private static function getServiceDeskStatus( $config, $issueKey ): string {
		[ $instance ] = $config;
		$hash = self::getAuthHash( $config );

		try {
			$response = self::getHttpClient()->run( [
				'headers' => [
					'Authorization' => 'Basic ' . $hash,
				],
				'url' => 'https://' . $instance . '/rest/api/2/issue/' . $issueKey . '?fields=project',
				'method' => 'GET',
			] );
		} catch ( \Exception $e ) {
			return self::SD_STATUS_UNKNOWN;
		}

		if ( ( $response['code'] ?? 0 ) < 200 || ( $response['code'] ?? 0 ) >= 300 ) {
			return self::SD_STATUS_UNKNOWN;
		}

		$body = json_decode( $response['body'] ?? '', true );
		$projectTypeKey = $body['fields']['project']['projectTypeKey'] ?? null;
		if ( $projectTypeKey === null ) {
			return self::SD_STATUS_UNKNOWN;
		}

		return $projectTypeKey === 'service_desk' ? self::SD_STATUS_SD : self::SD_STATUS_NOT_SD;
	}

	/**
	 * Send the comment to Jira using the standard Jira API (public comment)
	 * @param array $config
	 * @param string $issueKey
	 * @param string $summary
	 * @return bool
	 */
	public static function sendToJira( $config, $issueKey, $summary ): bool {
		[ $instance ] = $config;
		$hash = self::getAuthHash( $config );
		$url = 'https://' . $instance . '/rest/api/2/issue/' . $issueKey . '/comment';

		return self::postComment( $url, $hash, [ 'body' => $summary ] );
	}

	/**
	 * Send an internal-only comment to a Service Desk issue via the Jira
	 * Service Management (JSM) request API. Never falls back to a public
	 * comment on failure.
	 * @param array $config
	 * @param string $issueKey
	 * @param string $summary
	 * @return bool
	 */
	public static function sendInternalCommentToJira( $config, $issueKey, $summary ): bool {
		[ $instance ] = $config;
		$hash = self::getAuthHash( $config );
		$url = 'https://' . $instance . '/rest/servicedeskapi/request/' . $issueKey . '/comment';

		return self::postComment( $url, $hash, [
			'body' => $summary,
			'public' => false,
		] );
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
