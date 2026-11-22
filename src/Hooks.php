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

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;
use MultiHttpClient;
use WikiPage;

class Hooks implements PageSaveCompleteHook {

	private const SD_STATUS_SD = 'sd';
	private const SD_STATUS_NOT_SD = 'not_sd';
	private const SD_STATUS_UNKNOWN = 'unknown';

	private HttpRequestFactory $httpRequestFactory;

	private ?MultiHttpClient $httpClient = null;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 */
	public function __construct( HttpRequestFactory $httpRequestFactory ) {
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return bool
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult ): bool {
		$diffLink = $this->getDiffLink( $wikiPage, $revisionRecord );
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

			$status = $this->getServiceDeskStatus( $config, $issueKey );
			if ( self::SD_STATUS_SD === $status ) {
				$this->sendInternalCommentToJira( $config, $issueKey, $summary );
			} elseif ( self::SD_STATUS_NOT_SD === $status ) {
				$this->sendToJira( $config, $issueKey, $summary );
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
	 * Lazily create the shared HTTP client through the injected factory and
	 * reuse it for every request made by this handler.
	 * @return MultiHttpClient
	 */
	private function getHttpClient(): MultiHttpClient {
		if ( $this->httpClient === null ) {
			$this->httpClient = $this->httpRequestFactory->createMultiClient( [ 'maxRetries' => 3 ] );
		}

		return $this->httpClient;
	}

	/**
	 * Build the HTTP Basic auth header value from the configured email/token.
	 * @param array $config
	 * @return string
	 */
	private function getAuthHash( $config ): string {
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
	private function postComment( string $url, string $hash, array $body ): bool {
		try {
			$response = $this->getHttpClient()->run( [
				'headers' => [
					'Authorization' => 'Basic ' . $hash,
					'Content-Type' => 'application/json',
				],
				'url' => $url,
				'method' => 'POST',
				'body' => json_encode( $body )
			] );
		} catch ( \Exception $e ) {
			wfDebugLog( 'SummaryToJiraComment', __METHOD__ .
				": exception posting comment to {$url}: {$e->getMessage()}" );
			return false;
		}

		// MultiHttpClient reports transport errors with a code of 0 and never
		// throws for HTTP-level failures, so the status must be checked here.
		$code = $response['code'] ?? 0;
		if ( $code < 200 || $code >= 300 ) {
			$error = $response['error'] ?? '';
			wfDebugLog( 'SummaryToJiraComment', __METHOD__ .
				": failed posting comment to {$url} (HTTP {$code}): {$error}" );
			return false;
		}

		return true;
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
	private function getServiceDeskStatus( $config, $issueKey ): string {
		[ $instance ] = $config;
		$hash = $this->getAuthHash( $config );

		try {
			$response = $this->getHttpClient()->run( [
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
	public function sendToJira( $config, $issueKey, $summary ): bool {
		[ $instance ] = $config;
		$hash = $this->getAuthHash( $config );
		$url = 'https://' . $instance . '/rest/api/2/issue/' . $issueKey . '/comment';

		return $this->postComment( $url, $hash, [ 'body' => $summary ] );
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
	public function sendInternalCommentToJira( $config, $issueKey, $summary ): bool {
		[ $instance ] = $config;
		$hash = $this->getAuthHash( $config );
		$url = 'https://' . $instance . '/rest/servicedeskapi/request/' . $issueKey . '/comment';

		return $this->postComment( $url, $hash, [
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
	private function getDiffLink( WikiPage $wikiPage, RevisionRecord $revisionRecord ): string {
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
