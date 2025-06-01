<?php
namespace Uo\AtlassianJiraMigration\Utils;

/**
 * Atlassian API Endpoints
 * @link https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/
 * This file contains the API endpoints for interacting with Atlassian Jira services.
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

    public function __construct($baseUrl, $username, $apiToken, $projectKey) {
        parent::__construct($baseUrl, $username, $apiToken);
        $this->projectKey = $projectKey;
        $this->projectId = $this->getProjectIdByKey($projectKey);
        $this->issueTypes = $this->loadIssueTypes();
    }

    public function getProjectId() {
        return $this->projectId;
    }
    public function getProjectKey() {
        return $this->projectKey;
    }
    public function getIssueTypes() {
        return $this->issueTypes;
    }

    private function getProjectIdByKey($projectKey) {
        $endpoint = '/rest/api/3/project/' . $projectKey;
        $response = $this->get($endpoint);
        if (isset($response['id'])) {
            return $response['id'];
        } else {
            throw new \Exception("Project with key '$projectKey' not found.");
        }
    }

    private function loadIssueTypes() {
        $endpoint = '/rest/api/3/issuetype';
        if (!empty($this->projectKey)) {
            $endpoint .= '/project?projectId=' . urlencode($this->projectId);
        }
        
        return $this->get($endpoint);
    }

    private function addParamsToUrl($url, $params) {
        if (!empty($params)) {
            $queryString = http_build_query($params);
            return $url . (strpos($url, '?') === false ? '?' : '&') . $queryString;
        }
        return $url;
    }
    
    function getIssue($issueId) {
        $endpoint = sprintf(self::GET_ISSUE, $issueId);
        return $this->get($endpoint);
    }
    function createIssue($data) {
        return $this->post(self::CREATE_ISSUE, $data);
    }
    function updateIssue($issueId, $data) {
        $endpoint = sprintf(self::UPDATE_ISSUE, $issueId);
        return $this->put($endpoint, $data);
    }
    
    function getIssuesByJQL($jql, $params = []) {
        $url = self::GET_ISSUES_BY_JQL . "?jql=" . urlencode($jql);
        if (!empty($params)) {
            $url = $this->addParamsToUrl($url, $params);
        }
        return $this->get($url);
        
    }
    function getIssueComments($issueId) {
        $endpoint = sprintf(self::GET_ISSUE_COMMENTS, $issueId);
        return $this->get($endpoint);
    }
    function createIssueComment($issueKey, $comment) {
        $endpoint = sprintf(self::CREATE_ISSUE_COMMENT, $issueKey);
        $data = ['body' => $comment];
        return $this->post($endpoint, $data);
    }
    
    /**
     * Retrieves the available transitions for a given issue.
     *
     * @param string $issueId The ID of the issue to retrieve transitions for.
     * @return array An array of available transitions for the issue.
     *               Returns an empty array if no transitions are found.
     */

    function getIssueTransitions($issueId) {
        $endpoint = sprintf(self::TRANSITION_ISSUE, $issueId);
        $transitions = $this->get($endpoint);
        if (isset($transitions['transitions'])) {
            return $transitions['transitions'];
        } else {
            echo "No transitions found for issue: $issueId\n";
            return [];
        }
    }
    function transitionIssue($issueId, $transitionId, $comment = null) {
        $endpoint = sprintf(self::TRANSITION_ISSUE, $issueId);
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

    function linkIssueByKey($inwardIssueKey, $outwardIssueKey, $linkType = 'relates') {
        $data = [
            'type' => ['name' => $linkType],
            'inwardIssue' => ['key' => $inwardIssueKey],
            'outwardIssue' => ['key' => $outwardIssueKey]
        ];
        return $this->post(self::LINK_ISSUE, $data);
    }
    function getIssueAttachments($issueKey) {
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
    function getIssueLinks($issueKey) {
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
    function getIssueByCustomField($customFieldId, $customFieldValue, $params = ['fields' => '*all']) {
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
    function uploadAttachment($issueId, $filePath) {
        $endpoint = sprintf(self::UPLOAD_ATTACHMENT, $issueId);
        return $this->postFile($endpoint, $filePath);
    }
    function downloadAttachment($attachmentUrl) {
        echo "Downloading attachment from $attachmentUrl\n";
        $ch = curl_init($attachmentUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        $fileData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
        if(curl_errno($ch)){
            echo 'Curl error: ' . curl_error($ch);
            return null;
        }
        curl_close($ch);
        //Verify that the download was successful
        if ($httpCode != 200 || empty($fileData)) {
            echo "Failed to download attachment from $attachmentUrl\n";
            return null;
        }
        return $fileData;
    }
    function getIssueAttachmentByFileName($issueId, $fileName) {    
        $attachments = $this->getIssueAttachments($issueId, $this->projectKey);
        foreach ($attachments as $attachment) {
            if (isset($attachment['filename']) && $attachment['filename'] === $fileName) {
                return $attachment;
            }
        }
        return null;
    }
    
    function linkIssue($inwardIssue, $outwardIssue, $linkType = 'relates') {
        $data = [
            'type' => ['name' => $linkType],
            'inwardIssue' => ['key' => $inwardIssue],
            'outwardIssue' => ['key' => $outwardIssue]
        ];
        return $this->post('/rest/api/3/issueLink', $data);
    }
    function getIssueLinkTypes() {
        return $this->get('/rest/api/3/issueLinkType');
    }
}