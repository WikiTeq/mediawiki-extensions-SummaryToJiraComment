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
