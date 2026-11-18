<p align="center">
  <img
    src="assets/banner.jpg"
    alt="SummaryToJiraComment — a scholarly Cuban tree frog clerk at a desk with a MediaWiki folio, diff-link parchment, and Jira comment press, framed with WikiTeq, MediaWiki, and Jira logos and a SummaryToJiraComment title plaque"
    width="100%"
  >
</p>

# SummaryToJiraComment

This extension comments on Jira tasks when they are mentioned in an edit
summary, adding a link to the relevant edit on your wiki.

## Set up

Prerequisite:
- MediaWiki running locally
- Jira Account

1. Get the Jira API Access Token https://support.atlassian.com/atlassian-account/docs/manage-api-tokens-for-your-atlassian-account/

2. Create a project and issues.

3. Install the extension

	```bash
	cd extensions
	git clone https://github.com/WikiTeq/mediawiki-extensions-SummaryToJiraComment SummaryToJiraComment
	```

4. Add the following to your LocalSettings.php

	```php
	wfLoadExtension('SummaryToJiraComment');
	$wgSummaryToJiraCommentInstance = '<your instance>'; // e.g. <your instance>.atlassian.net
	$wgSummaryToJiraCommentToken = '<your token>';
	$wgSummaryToJiraCommentEmail = '<your email>';
	```
## Service Desk vs. regular Jira issues

Before posting a comment, the extension looks up the target issue's project
type:

- **Service Desk (JSM) issues** — the comment is posted as an **internal-only**
  comment via the Jira Service Management request API (`public: false`). It is
  not visible to the customer on the Service Desk portal.
- **Regular (non-Service-Desk) issues** — the comment is posted as a normal
  public comment via the standard Jira REST API, same as before.
- **Undetermined project type** (API error, permissions issue, unexpected
  response shape) — no comment is posted at all. The extension fails closed
  toward privacy: it will never post a public comment when it can't confirm
  the issue isn't a Service Desk request.

No extra configuration is required — detection uses the same
`$wgSummaryToJiraCommentInstance` / `$wgSummaryToJiraCommentToken` /
`$wgSummaryToJiraCommentEmail` credentials as the rest of the extension. The
configured Jira user must have **Add Comments** permission on the target
project, and for Service Desk projects specifically, must be a JSM **agent**
on that project — customers/portal-only users cannot create internal
comments via this API.

---

## Trademark disclaimer

- **MediaWiki** is a trademark of the Wikimedia Foundation.
- **Jira** is a registered trademark of Atlassian.

This project is an independent open-source tool and is not affiliated with, sponsored by, or endorsed by Atlassian or the Wikimedia Foundation.
