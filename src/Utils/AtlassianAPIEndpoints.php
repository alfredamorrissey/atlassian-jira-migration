<?php
namespace Uo\AtlassianJiraMigration\Utils;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;

/**
 * AtlassianAPIEndpoints
 * 
 * This class provides methods to interact with the Atlassian API, including GET, POST, PUT, and DELETE requests.
 * It handles authentication and error handling for API requests.
 * @link https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/
 */
class AtlassianAPIEndpoints extends AtlassianAPI{
    public const GET_ISSUES_BY_JQL = '/rest/api/3/search';
    
    public const UPLOAD_ATTACHMENT = '/rest/api/3/issue/%s/attachments';

    public const TRANSITION_ISSUE = '/rest/api/3/issue/%s/transitions';

    public const GET_ISSUE = '/rest/api/3/issue/%s';
    public const CREATE_ISSUE = '/rest/api/3/issue';
    public const UPDATE_ISSUE = '/rest/api/3/issue/%s';

    public const GET_ISSUE_COMMENTS = '/rest/api/3/issue/%s/comment';
    public const CREATE_ISSUE_COMMENT = '/rest/api/3/issue/%s/comment';

    public const LINK_ISSUE = '/rest/api/3/issueLink';

    private string $projectKey;
    private string $projectId;
    private array $issueTypes;

    /**
     * Constructor for AtlassianAPIEndpoints.
     *
     * Initializes the Atlassian API client with the base URL, authentication credentials,
     * and project key. It also loads the issue types associated with the project.
     *
     * @param string $baseUrl The base URL of the Atlassian instance (e.g., 'https://your-domain.atlassian.net').
     * @param string $username The username for authentication.
     * @param string $apiToken The API token for authentication.
     * @param string $projectKey The project key for the project to interact with.
     */
    public function __construct(string $baseUrl, string $username, string $apiToken, string $projectKey) {
        parent::__construct($baseUrl, $username, $apiToken);
        $this->projectKey = $projectKey;
        $this->projectId = $this->getProjectIdByKey($projectKey);
        $this->issueTypes = $this->loadIssueTypes();
    }

    /**
     * Retrieves the project ID that is associated with the instance of this class.
     * 
     * The project ID is set when the instance is created and is used to make API
     * requests to the Atlassian API.
     * 
     * @return string The ID of the project.
     */
    public function getProjectId(): string {
        return $this->projectId;
    }

    /**
     * Retrieves the project key that is associated with the instance of this class.
     * 
     * The project key is set when the instance is created and is used to make API
     * requests to the Atlassian API.
     * 
     * @return string The key of the project.
     */
    public function getProjectKey(): string {
        return $this->projectKey;
    }

    /**
     * Retrieves the issue types for the project that is associated with this instance.
     * 
     * The issue types are set when the instance is created and are used to make API
     * requests to the Atlassian API.
     * 
     * @return array The issue types for the project.
     */
    public function getIssueTypes(): array {
        return $this->issueTypes;
    }

    /**
     * Get the project ID given a project key.
     * @param string $projectKey The project key.
     * @return string The project ID.
     * @throws JiraApiException If the project ID is not found.
     */
    private function getProjectIdByKey(string $projectKey): string {
        $endpoint = '/rest/api/3/project/' . $projectKey;
        $response = $this->get($endpoint);
        if (isset($response['id'])) {
            return $response['id'];
        } else {
            throw new JiraApiException(
                "Project ID not found for key: $projectKey",
                'GET',
                $endpoint,
                null,
                $this->getHttpCode(),
                $response
            );
        }
    }

    /**
     * Retrieves all issue types for the configured project.
     *
     * @return array An array of issue types in the format of
     *               Jira's REST API response.
     */
    private function loadIssueTypes(): array {
        $endpoint = '/rest/api/3/issuetype';
        if (!empty($this->projectKey)) {
            $endpoint .= '/project?projectId=' . urlencode($this->projectId);
        }
        
        return $this->get($endpoint);
    }

    /**
     * Takes a URL and a set of parameters, and returns the URL with the
     * parameters added as a query string.
     *
     * If the URL already has a query string, the new parameters are
     * appended with an ampersand (&). If not, a question mark (?) is
     * used.
     *
     * @param string $url The base URL to which the parameters should be added.
     * @param array $params The parameters to add to the URL.
     * @return string The URL with the parameters added.
     */
    private function addParamsToUrl(string $url, array $params): string {
        if (!empty($params)) {
            $queryString = http_build_query($params);
            return $url . (strpos($url, '?') === false ? '?' : '&') . $queryString;
        }
        return $url;
    }
    
    /**
     * Retrieves a specific issue by its key.
     *
     * @param string $issueKey The key of the issue to retrieve.
     * @return array The response from the API.
     */
    function getIssue(string $issueKey): array {
        $endpoint = sprintf(self::GET_ISSUE, $issueKey);
        return $this->get($endpoint);
    }

    /**
     * Creates a new issue in the target project based on the provided data.
     * 
     * @param array $data The data to create the issue with.
     * @return array The response from the API.
     */
    function createIssue(array $data): array {
        return $this->post(self::CREATE_ISSUE, $data);
    }

    /**
     * Updates an existing issue.
     *
     * @param string $issueKey The key of the issue to update.
     * @param array $data The data to update the issue with.
     * @return array The response from the API in this case probably null
     */
    function updateIssue(string $issueKey, array $data): mixed {
        $endpoint = sprintf(self::UPDATE_ISSUE, $issueKey);
        return $this->put($endpoint, $data);
    }
    
    /**
     * Fetches issues from the API based on a JQL query.
     * 
     * @param string $jql The JQL query to execute.
     * @param array $params An array of parameters to pass to the API.
     *   The following parameters are supported:
     *   - startAt: The index of the first issue to return (default 0).
     *   - maxResults: The maximum number of issues to return per page (default 50).
     *   - fields: An array of fields to include in the response (default: '*all').
     * 
     * @return array An array of issues matching the JQL query.
     */
    function getIssuesByJQL(string $jql, array $params = []): array {
        $url = self::GET_ISSUES_BY_JQL . "?jql=" . urlencode($jql);
        if (!empty($params)) {
            $url = $this->addParamsToUrl($url, $params);
        }
        return $this->get($url);
        
    }

    /**
     * Retrieves the comments for the given issue.
     *
     * @param string $issueKey The key of the issue to retrieve comments for.
     * @return array An array of comments for the given issue.
     *               Returns an empty array if no comments are found.
     */
    function getIssueComments(string $issueKey): array {
        $endpoint = sprintf(self::GET_ISSUE_COMMENTS, $issueKey);
        $comments = $this->get($endpoint);
        return $comments['comments'] ?? [];
    }

    /**
     * Creates a new comment for the given issue.
     *
     * @param string $issueKey The key of the issue to comment on.
     * @param array $comment The comment data to create.
     *                        The comment data should contain a 'body' key with a string value.
     *                        Example: ['body' => 'This is a comment.']
     * @return array The response from the API.
     */
    function createIssueComment(string $issueKey, array $comment): array {
        $endpoint = sprintf(self::CREATE_ISSUE_COMMENT, $issueKey);
        return $this->post($endpoint, $comment);
    }
    
    /**
     * Retrieves the available transitions for a given issue.
     *
     * @param string $issueId The ID of the issue to retrieve transitions for.
     * @return array An array of available transitions for the issue.
     *               Returns an empty array if no transitions are found.
     */

    function getIssueTransitions(string $issueKey): array {
        $endpoint = sprintf(self::TRANSITION_ISSUE, $issueKey);
        $transitions = $this->get($endpoint);
        
        return $transitions['transitions'] ?? [];
    }

    /**
     * Transitions an issue to a new status.
     * 
     * Transitions an issue to a new status, optionally adding a comment to the issue.
     * @param string $issueKey The key of the issue to transition.
     * @param string $transitionId The ID of the transition to apply.
     * @param string $comment Optional comment to add to the issue.
     * @return mixed The response from the API.
     */
    function transitionIssue(string $issueKey, string $transitionId, ?string $comment = null): mixed {
        $endpoint = sprintf(self::TRANSITION_ISSUE, $issueKey);
        $data = [
            'transition' => ['id' => $transitionId]
        ];
        if ($comment) {
            $data['update'] = [
                'comment' => [['add' => ['body' => $comment]]]
            ];
        }
        return $this->post($endpoint, $data);
    }

    /**
     * Links two issues together with a specified link type.
     *
     * @param string $inwardIssueKey The key of the inward issue.
     * @param string $outwardIssueKey The key of the outward issue.
     * @param string $linkType The type of link to create (default is 'relates').
     * @return mixed The response from the API.
     */
    function linkIssueByKey(string $inwardIssueKey, string $outwardIssueKey, string $linkType = 'relates'): mixed {
        $data = [
            'type' => ['name' => $linkType],
            'inwardIssue' => ['key' => $inwardIssueKey],
            'outwardIssue' => ['key' => $outwardIssueKey]
        ];
        return $this->post(self::LINK_ISSUE, $data);
    }

    /**
     * Retrieves the attachments for a given issue.
     *
     * @param string $issueKey The key of the issue to retrieve attachments for.
     * @return array An array of attachments for the given issue.
     *               Returns an empty array if no attachments are found.
     */
    function getIssueAttachments(string $issueKey): array {
        $jql = "project = \"{$this->projectKey}\" AND key = \"$issueKey\"";
        $params = ['fields' => 'attachment'];
        $attachments = $this->getIssuesByJQL($jql, $params);
        if (isset($attachments['issues']) && count($attachments['issues']) > 0) {
            $issue = $attachments['issues'][0];
            if (isset($issue['fields']['attachment'])) {
                return $issue['fields']['attachment'];
            }
        }
        return [];
    }

    /**
     * Retrieves the issue links for a given issue.
     *
     * @param string $issueKey The key of the issue to retrieve issue links for.
     * @return array An array of issue links for the given issue.
     *               Returns an empty array if no issue links are found.
     */
    function getIssueLinks(string $issueKey): array {
        $jql = "project = \"{$this->projectKey}\" AND key = \"$issueKey\"";
        $params = ['fields' => 'issuelinks'];
        $attachments = $this->getIssuesByJQL($jql, $params);
        if (isset($attachments['issues']) && count($attachments['issues']) > 0) {
            $issue = $attachments['issues'][0];
            if (isset($issue['fields']['issuelinks'])) {
                return $issue['fields']['issuelinks'];
            }
        }
        return [];
    }

    /**
     * Retrieves an issue by its custom field value.
     *
     * @param string $customFieldId The ID of the custom field to search by.
     * @param string $customFieldValue The value of the custom field to search for.
     * @param array $params Optional parameters for the JQL query.
     * @return array|null The issue that matches the custom field value, or null if no issue is found.
     */
    function getIssueByCustomField(string $customFieldId, string $customFieldValue, array $params = ['fields' => '*all']) {
        $jql = "project = \"{$this->projectKey}\" AND cf[$customFieldId] ~ \"$customFieldValue\"";
        $issues = $this->getIssuesByJQL($jql, $params);
        if (isset($issues['issues']) && count($issues['issues']) > 0) {
            return $issues['issues'][0];
        }
        return null;
    }
    /**
     * Uploads an attachment to a specific issue.
     *
     * @param string $issueId The ID of the issue to upload the attachment to.
     * @param string $filePath The path to the file to be uploaded.
     * @return array|null The response from the API, or null if the upload failed.
     */
    function uploadAttachment(string $issueKey, string $filePath): ?array {
        $endpoint = sprintf(self::UPLOAD_ATTACHMENT, $issueKey);
        return $this->postFile($endpoint, $filePath);
    }

    /**
     * Downloads an attachment from the specified URL.
     *
     * @param string $attachmentUrl The URL of the attachment to download.
     * @return string The contents of the attachment.
     * @throws JiraApiException If the download fails.
     */
    function downloadAttachment(string $attachmentUrl): string {
        $ch = curl_init($attachmentUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        $fileData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
        $curlError = curl_error($ch);
        if ($curlError) {
            throw new JiraApiException(
                "cURL error GET $: $curlError",
                'GET',
                $attachmentUrl,
                null,
                $httpCode,
                $fileData
            );
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new JiraApiException(
                "Download Attachment failed: HTTP code $httpCode",
                'GET',
                $attachmentUrl,
                null,
                $httpCode,
                $fileData
            );
        }
        return $fileData;
    }

    /**
     * Retrieves a specific attachment by its filename from a given issue.
     *
     * @param string $issueKey The key of the issue to search for the attachment.
     * @param string $fileName The name of the attachment file to retrieve.
     * @return array|null The attachment data if found, or null if not found.
     */
    function getIssueAttachmentByFileName(string $issueKey, string $fileName): ?array {    
        $attachments = $this->getIssueAttachments($issueKey, $this->projectKey);
        foreach ($attachments as $attachment) {
            if (isset($attachment['filename']) && $attachment['filename'] === $fileName) {
                return $attachment;
            }
        }
        return null;
    }

    /**
     * Links two issues together with a specified link type.
     *
     * @param string $inwardIssue The key of the inward issue.
     * @param string $outwardIssue The key of the outward issue.
     * @param string $linkType The type of link to create (default is 'relates').
     * @return array The response from the API.
     */
    function linkIssue(string $inwardIssue, string $outwardIssue, string $linkType = 'relates'): array {
        $data = [
            'type' => ['name' => $linkType],
            'inwardIssue' => ['key' => $inwardIssue],
            'outwardIssue' => ['key' => $outwardIssue]
        ];
        return $this->post('/rest/api/3/issueLink', $data);
    }

    /**
     * Retrieves all issue link types from the Jira instance.
     *
     * @return array The response from the API.
     */
    function getIssueLinkTypes(): array {
        return $this->get('/rest/api/3/issueLinkType');
    }
}