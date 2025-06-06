<?php

namespace Uo\AtlassianJiraMigration;

use Elentra\Atlassian\Util\AtlassianAPI;
use Exception;
use Monolog\Logger;

use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Utils\LoggerFactory;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;

/**
 * JiraSyncProcess
 * 
 * This class handles the synchronization of issues between two Jira projects.
 * It provides methods to create, update, and sync issues, comments, attachments, and issue links.
 * It also includes methods for handling custom fields and issue types.
 */
class JiraSyncProcess {
    private AtlassianAPIEndpoints $sourceJira;
    private AtlassianAPIEndpoints $targetJira;
    private array $customFields;
    private array $issueLinkTypes;
    private array $issueTypeMap = [];
    private bool $skipExistingIssues = false; // Flag to skip existing issues in the target project
    // Logger instance
    // Using Monolog for logging
    private  ?Logger $log = null;

    private int $errorCount = 0;
    private int $issueCount = 0;
    private int $updateCount = 0;
    private int $createCount = 0;

    private const SUMMARY_MAX_LENGTH = 255;
    

    /**
     * Constructor for JiraSyncProcess.
     *
     * Initializes the Jira synchronization process with the provided source and target Jira API endpoints,
     * custom fields, issue link types, and a custom map for issue types. Optionally, a logger can be provided
     * for logging purposes; if not, a default logger will be created.
     *
     * @param AtlassianAPIEndpoints $sourceJira The source Jira API endpoint.
     * @param AtlassianAPIEndpoints $targetJira The target Jira API endpoint.
     * @param array $customFields An associative array of custom field mappings.
     * @param array $issueLinkTypes An array of issue link types to be processed.
     * @param array $customMap A map of custom issue types for synchronization.
     * @param Logger|null $logger An optional logger instance for logging activities.
     */
    public function __construct(AtlassianAPIEndpoints $sourceJira, AtlassianAPIEndpoints $targetJira, array $customFields, array $issueLinkTypes, array $customMap, ?Logger $logger = null) {
        $this->sourceJira = $sourceJira;
        $this->targetJira = $targetJira;
        $this->customFields = $customFields;
        $this->issueLinkTypes = $issueLinkTypes;
        $this->issueTypeMap = $this->mapIssueTypes($targetJira->getIssueTypes(), $sourceJira->getIssueTypes(), $customMap);
        // Initialize logger if provided
        $this->log = $logger ?? LoggerFactory::create();
    }

    /**
     * Sets the flag to skip existing issues during synchronization.
     *
     * @param bool $skip If true, existing issues in the target project will be skipped.
     */

    public function setSkipExistingIssues(bool $skip): void {
        $this->skipExistingIssues = $skip;
    }

    /**
     * Syncs issues from the source Jira project to the target Jira project.
     *
     * This method will fetch issues from the source Jira project using the provided JQL,
     * and then sync each issue to the target Jira project, creating new issues or updating
     * existing ones as necessary. If the --skip-existing flag is provided, existing issues
     * will be skipped.
     *
     * The method takes several optional parameters:
     *
     * - startAt: The index of the first issue to return (default 0).
     * - end: The index of the last issue to return (default null, meaning all issues will be processed).
     * - batches: The number of batches to process (default null, meaning all issues will be processed in one batch).
     * - batchSize: The number of issues to process per batch (default 100).
     * - sourceIssueKeys: An array of issue keys to be processed (default null, meaning all issues will be processed).
     */
    public function syncIssues(int $startAt = 0, ?int $end = null, ?int $batches = null, int $batchSize = 100, ?array $sourceIssueKeys = null): void {
        $startTime = microtime(true);
        $batch = 1;
        $maxResults = $batchSize;
        $totalIssuesInRun = null; // Total issues to process in this run
        $fields = 'parent,project,key,summary,description,issuetype,components,status,reporter,priority,fixVersions,labels,issuelinks';
        $originalStart = $startAt;
        
        // Fetch issues from the source Jira project
        if (!is_null($sourceIssueKeys)) {
            $jql = "project = \"{$this->sourceJira->getProjectKey()}\" AND key IN (" . implode(',', array_map(fn($key) => "\"$key\"", $sourceIssueKeys)) . ")";
        } else {
            $jql = "project = \"{$this->sourceJira->getProjectKey()}\"";
        }
            
        do {
            // Add additional parameters if needed
            $params = [
                'startAt' => $startAt,
                'maxResults' => $maxResults,
                'fields' => $fields
            ];
            try {
                // Fetch issues using JQL
                $data = $this->sourceJira->getIssuesByJQL($jql, $params);
            } catch (Exception $e) {
                echo "Error fetching issues: " . $e->getMessage() . "\n";
                $this->log->error("Error fetching issues: {$e->getMessage()}", ['jql' => $jql, 'params' => $params]);
                return;
            }
                
            if ($data && !empty($data['issues'])) {
                $totalIssuesInProject = $data['total'];
                
                //If keys specified, total issue run will be the number of keys
                if (!is_null($sourceIssueKeys)) {
                    $totalIssuesInRun = count($sourceIssueKeys);    
                } 
                else if (!is_null($end)) {
                    // If the end is specified, we will use the total issues in the project
                    $totalIssuesInRun = $end - $originalStart;
                    $batches = ceil($totalIssuesInRun / $batchSize);
                } else if (!is_null($batches)) {
                    // If number batches are specified, we will calculate total issues in run by multiplying batches by batch size
                    $totalIssuesInRun = $batches * $batchSize;
                } else {
                    $totalIssuesInRun = $totalIssuesInProject - $originalStart;
                    $batches = ceil($totalIssuesInRun / $batchSize);
                }
                if (empty($sourceIssueKeys)) {
                    // If no specific keys are provided, we will fetch issues based on JQL 
                    echo "Starting batch $batch out of $batches with batch size of $batchSize\n";
                } else {
                    // If specific keys are provided, we will fetch issues based on the keys
                    echo "Starting process for keys: " . implode(',', $sourceIssueKeys) . "\n";
                }

                echo "Total issues to process: $totalIssuesInRun " . (empty($sourceIssueKeys) ? "Number of batches: $batches" : "") . "\n";
                
                foreach ($data['issues'] as $issue) {
                    $this->issueCount++;
                        
                    try {
                        echo "\nRun Status: " . $this->issueCount . " of {$totalIssuesInRun} ";
                        // We don't need to worry about batches if we are just running a list of keys
                        if (empty($sourceIssueKeys)) {
                            // If we are no listing keys, give total issues in run
                             echo "- Project Status: " . $originalStart + $this->issueCount . " of  $totalIssuesInProject\n";
                        } 
                        
                        $this->syncIssue($issue);
                    } catch (JiraApiException $e) {
                        echo "Error processing issue: {$issue['key']}. Error: " . $e->getMessage() . "\n";
                        $this->log->error("Error processing issue: {$issue['key']} {$e->getMessage()}", $e->toContextArray());
                        $this->errorCount++;
                        continue; // Skip to the next issue if there's an error
                    } catch (Exception $e) {
                        echo "Unexpected error processing issue: {$issue['key']}. Error: " . $e->getMessage() . "\n";
                        $this->log->error("Unexpected error processing issue: {$issue['key']} {$e->getMessage()}");
                        $this->errorCount++;
                        continue; // Skip to the next issue if there's an error
                    } finally {
                        sleep(1); // To respect API rate limits
                    }
                }
            } 
            else { break;}
            $startAt += $batchSize;
            $batch++;
            echo ("\n");
            if (is_null($sourceIssueKeys)) {
                echo "Batch $batch completed. Processed $this->issueCount issues with $this->errorCount errors.\n";
            
            } else {
                echo "Processed issues from keys: " . implode(', ', $sourceIssueKeys) . "\n";
            }
            echo 'Updated issues: ' . $this->updateCount . ', Created issues: ' . $this->createCount . "\n";
            echo "Time taken: " . (microtime(true) - $startTime) . " seconds OR " . (microtime(true) - $startTime)/60 . " minutes\n";
            echo "\n\n##########################################\n\n";
            
        } while ($startAt < $data['total'] && $batch <= $batches && (is_null($end) || $startAt < $end)); 
        // If we reach here, we have processed all issues
        echo "Total issues processed: $this->issueCount with $this->errorCount errors out of Total Project Issues: {$data['total']}\n";
        echo 'Updated issues: ' . $this->updateCount . ', Created issues: ' . $this->createCount . "\n";
        echo "Time taken: " . (microtime(true) - $startTime) . " seconds OR " . (microtime(true) - $startTime)/60 . " minutes\n";
            
    }  
    
    /******************* Issue Creation and Update Methods *******************/

    /**
     * Create a new issue in the target project based on the source issue.
     *
     * @param array $issue The source issue data.
     * @param AtlassianAPIEndpoints $jira The Jira API client instance.
     * @return array|null The created issue data.
     * @throws JiraApiException If the issue creation fails.
     */
     private function createIssue(array $issue, AtlassianAPIEndpoints $jira): ?array {
        $issueTypeName = $issue['fields']['issuetype']['name'] ?? 'Task';
        $isSubtask = strtolower($issueTypeName) === 'sub-task' || strtolower($issueTypeName) === 'subtask';

        $parentKey = null;
        $parentType = null;

        // Only fetch parent info if a parent exists
        if (isset($issue['fields']['parent'])) {
            $parentKey = $issue['fields']['parent']['key'];
            $parentType = $issue['fields']['parent']['fields']['issuetype']['name'];
            
            $targetParentIssue = $jira->getIssueByCustomField($this->customFields['Consortium Jira ID'], $parentKey, ['fields' => 'issuetype,key']);

            if (!$targetParentIssue) {
                // Create parent if not found in target
                $parentIssue = $this->createIssue($issue['fields']['parent'], $jira);
                $parentKey = $parentIssue['key'];
            } else {
                $parentKey = $targetParentIssue['key'];
                $parentType = $targetParentIssue['fields']['issuetype']['name'];
            }

            // Check if parent is an Epic and this issue is a sub-task — convert to Task
            if ($isSubtask && strtolower($parentType) === 'epic') {
                $issueTypeName = 'Task';
            }
        } 

        // Translate type via map if defined
        $mappedType = $this->issueTypeMap[$issueTypeName] ?? $issueTypeName;
        

        $payload = [
            "fields" => [
                "project" => [ "key" => $jira->getProjectKey() ],
                "summary" => $this->sanitizeSummary($issue),
                "issuetype" => [ "name" => $mappedType ],
                "customfield_" . $this->customFields['Consortium Jira ID'] => $issue['key']
            ]
        ];

        // Pass in resolved parent key directly to avoid re-fetching
        $payload = $this->syncParent($payload, $parentKey, $mappedType);

        $response = $jira->createIssue($payload);
        if (isset($response['key'])) {
            $this->updateIssue($response['key'], $issue, $jira);
        } 
        if (isset($response['key'])) {
            return $response;
        }
        throw new Exception("Failed to create issue in target project: {$jira->getProjectKey()}");
    }

    /**
     * Updates an existing issue in the target Jira project based on the source issue data.
     *
     * @param string $issueKey The key of the issue to be updated in the target Jira project.
     * @param array $sourceIssue The source issue data used to update the target issue.
     * @param AtlassianAPIEndpoints $jira The Jira API client instance.
     * @return array|null The updated issue data or null on failure.
     * @throws JiraApiException If the issue update fails.
     */
    private function updateIssue(string $issueKey, array $sourceIssue, AtlassianAPIEndpoints $jira): ?string {
        $payload = [
            "fields" => [
                "summary" => $this->sanitizeSummary($sourceIssue),
                "customfield_" . $this->customFields['Consortium Jira ID'] => $sourceIssue['key'] // Set the custom field with the source issue key
            ], 
            
        ];
        $payload = $this->addParamsToPayload($payload, $sourceIssue, $issueKey);
        $jira->updateIssue($issueKey, $payload);

        // Response should be empty and HTTP code should be 204
        // This is normal for this endpoint
        return $issueKey;
    }

    /******************* Sync Methods *******************/
    

    /**
     * Updates the description of an issue in the target Jira project based on the source issue data.
     *
     * @param array $payload The payload data used to update the target issue.
     * @param array $sourceIssue The source issue data used to update the target issue.
     * @param string $targetIssueKey The key of the target issue.
     * @return array The updated payload data.
     */
    private function syncDescription(array $payload, array $sourceIssue, string $targetIssueKey): array {
        $description = $sourceIssue['fields']['description'] ?? null;
    
        if (!empty($description) && is_array($description)) {
            $sanitized = $this->sanitizeADF($description, $sourceIssue, $targetIssueKey);
            $finalAdf = $this->validateFinalADF($sanitized);
            if (!empty($finalAdf['content'])) {
                $payload['fields']['description'] = $finalAdf;
            } else {
                $this->log->info("Skipping empty ADF description for {$sourceIssue['key']}");
            }
        }
    
        return $payload;
    }
      

    
    /**
     * Updates the parent of an issue in the target Jira project based on the source issue data.
     *
     * @param array $payload The payload data used to update the target issue.
     * @param string|null $parentKey The key of the parent issue or null if no parent exists.
     * @param string $issueType The type of the issue.
     * @return array The updated payload data.
     */
    private function syncParent(array $payload, ?string $parentKey, string $issueType): array {
        if ($parentKey) {
            $payload["fields"]["parent"] = [ "key" => $parentKey ];
        } 
    
        return $payload;
    }

    /**
     * Synchronizes the labels from the source issue to the target issue payload.
     *
     * This function retrieves the labels from the source issue, sanitizes them
     * using the 'sanitizeLabel' method, and then updates the target issue payload
     * with the sanitized labels. If no labels are present, the payload remains unchanged.
     *
     * @param array $payload The payload data for the target issue.
     * @param array $sourceIssue The source issue containing the labels to be synced.
     * @return array The updated payload data with the synchronized and sanitized labels.
     */
    private function syncLabels(array $payload, array $sourceIssue): array {
        $labels = $sourceIssue['fields']['labels'] ?? [];
        if (!empty($labels)) {
            // Sanitize all labels
            $sanitizedLabels = array_map([$this, 'sanitizeLabel'], $labels);
            $payload["fields"]["labels"] = $sanitizedLabels;
        } 
        return $payload;
    }

    /**
     * Sync components from the source issue to the target project.
     * Our source project doesn't support components, so we will sync them as a custom field.
     *
     * @param array $sourceIssue The source issue containing components.
     * @param string $targetDomain The domain of the target Jira instance.
     * @return array The updated payload data with the synchronized and sanitized labels.
     */
    private function syncComponents(array $payload, array $sourceIssue): array {
        $components = $sourceIssue['fields']['components'] ?? [];
        $componentNames = array_map(function($component) {
            return $this->sanitizeLabel($component['name']);
        }, $components);

        if (!empty($componentNames)) {
            $payload["fields"]["customfield_" . $this->customFields['Components']] = $componentNames;
        } 

        return $payload;
    }
    

    /**
     * Sync fix versions from the source issue to the target project.
     * Our source project doesn't support fix versions, so we will sync them as a custom field.
     *
     * @param array $payload The payload data for the target issue.
     * @param array $sourceIssue The source issue containing the fix versions to be synced.
     * @return array The updated payload data with the synchronized and sanitized fix versions.
     */
    private function syncFixVersion(array $payload, array $sourceIssue): array {
        $fixVersions = $sourceIssue['fields']['fixVersions'] ?? [];
        if (!empty($fixVersions)) {
            $versionNames = array_map(function($version) {
                return $version['name'];
            }, $fixVersions);
            $payload["fields"]["customfield_" . $this->customFields['Fix Version']] = $versionNames;
        } 
        return $payload;
    }

    /**
     * Syncs the priority from the source issue to the target payload.
     *
     * Updates the target issue's payload with the priority from the source issue,
     * unless the priority is empty or set to 'Undetermined'.
     *
     * @param array $payload The payload data for the target issue.
     * @param array $sourceIssue The source issue containing the priority information.
     * @return array The updated payload data with the synchronized priority.
     */
    private function syncPriority(array $payload, array $sourceIssue): array {
        $priority = $sourceIssue['fields']['priority']['name'] ?? null; 
        if (!empty($priority) && $priority !== 'Undetermined') {
            $payload["fields"]["priority"] = [
                "name" => $priority
            ];
        } 
        return $payload;
    }

    /**
     * Syncs the reporter name from the source issue to the target payload.
     *
     * Updates the target issue's payload with the reporter's name from the source issue,
     * unless the reporter name is empty.
     *
     * @param array $payload The payload data for the target issue.
     * @param array $sourceIssue The source issue containing the reporter name information.
     * @return array The updated payload data with the synchronized reporter name.
     */
    private function syncReporter(array $payload, array $sourceIssue): array {
        $reporter = $sourceIssue['fields']['reporter']['displayName'] ?? null;
        if (!empty($reporter)) {
            $payload["fields"]["customfield_" . $this->customFields['Reporter Name']] = $reporter;
        } 
        return $payload;
    }

    /**
     * Syncs the status from the source issue to the target issue.
     *
     * Retrieves the transitions available for the target issue, finds the transition ID
     * for the source status by matching the status names, and transitions the target
     * issue to the matching status.
     *
     * @param array $sourceIssue The source issue containing the status information.
     * @param string $targetIssueKey The key of the target issue to sync the status with.
     */
    private function syncStatusTransition(array $sourceIssue, string $targetIssueKey): void {
        //Get the transitions available for the target issue
        $transitions = $this->targetJira->getIssueTransitions($targetIssueKey);
        if (empty($transitions)) {
            echo "No transitions available for issue: {$targetIssueKey}\n";
            return;
        }

        // Find the transition ID for the source status
        $currentStatus = $sourceIssue['fields']['status']['name'];
        $transitionId = null;
        foreach ($transitions as $transition) {
            if ($transition['name'] === $currentStatus) {
                $transitionId = $transition['id'];
                break;
            }
        }
        if ($transitionId !== null) {
            // Transition the issue in the target project
            $this->targetJira->transitionIssue($targetIssueKey, $transitionId);
        }
    }

    /**
     * Syncs comments from the source issue to the target issue.
     *
     * Comments are only synced if the target issue does not already have comments.
     * The comments are sanitized and any ADF content is converted to plain text
     * before being created in the target issue.
     *
     * @param array $sourceIssue The source issue containing comments.
     * @param string $targetIssueKey The key of the target issue to sync comments with.
     */
    private function syncComments(array $sourceIssue, string $targetIssueKey): void {
        // If the target issue already has comments, skip syncing
        $targetComments = $this->targetJira->getIssueComments($targetIssueKey);
        if (!empty($targetComments)) {
            return;
        }

        $comments = $this->sourceJira->getIssueComments($sourceIssue['key']) ?? [];
        
        foreach ($comments as $comment) {
            $body = $comment['body'] ?? null;
    
            if (!empty($body) && is_array($body)) {
                $sanitized = $this->sanitizeADF($body, $sourceIssue, $targetIssueKey);
                $finalAdf = $this->validateFinalADF($sanitized);
                // Only create a comment if the sanitized content is not empty
                if (!empty($sanitized['content'])) {
                    
                    $finalAdf['content'][] = $this->addOriginalAuthorToComment($comment);
            
                    try {
                        $this->targetJira->createIssueComment($targetIssueKey, ['body' => $finalAdf]);
                    } catch (JiraApiException $e) {
                        $this->log->warning("Falling back to text for comment: {$targetIssueKey} - {$e->getMessage()}", $e->toContextArray());
                        $body['content'][] = $this->addOriginalAuthorToComment($comment);
                        $fallback = $this->adfToPlainText($body); // original body
                        $this->targetJira->createIssueComment($targetIssueKey, [
                            'body' => [
                                'type' => 'doc',
                                'version' => 1,
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [[
                                        'type' => 'text',
                                        'text' => $fallback,
                                    ]],
                                ]],
                            ]
                        ]);
                    }
                }
            }
        }
    }
    
    
    /**
     * Syncs attachments from the source issue to the target issue.
     *
     * @param array $sourceIssue The source issue data.
     * @param string $targetIssueKey The key of the target issue.
     *
     * @return void
     */
    private function syncAttachments(array $sourceIssue, string $targetIssueKey): void {
        // Fetch attachments from the source issue
        $attachments = $this->sourceJira->getIssueAttachments($sourceIssue['key']);

        if ($attachments && !empty($attachments)) {
            foreach ($attachments as $attachment) {
                // Upload the attachment to the target issue
                try {
                    $this->uploadAttachment($targetIssueKey,$attachment['content'], $attachment['filename']);
                } catch (JiraApiException $e) {
                    echo "Error uploading attachment for issue: {$sourceIssue['key']}. Error: " . $e->getMessage() . "\n";
                    $this->log->error("Error uploading attachment for issue: {$sourceIssue['key']} {$e->getMessage()}", $e->toContextArray());
                    continue; // Skip to the next attachment if there's an error
                } catch (Exception $e) {
                    echo "Unexpected error uploading attachment for issue: {$sourceIssue['key']}. Error: " . $e->getMessage() . "\n";
                    $this->log->error("Unexpected error uploading attachment for issue: {$sourceIssue['key']} {$e->getMessage()}");
                    continue; // Skip to the next attachment if there's an error
                }
                
            }
        } 
    }

    /**
     * Syncs issue links from the source issue to the target issue.
     *
     * Links are only synced if the target issue does not already have links.
     * The links are synced using the 'relates to' and 'blocks' link types.
     * If a linked issue does not exist in the target project, it is created.
     * If the link already exists in the target issue, it is skipped.
     *
     * @param array $sourceIssue The source issue containing the links.
     * @param string $targetIssueKey The key of the target issue to sync links with.
     */
    private function syncIssueLinks(array $sourceIssue, string $targetIssueKey): void {
        //Check if the target already has links
        $targetLinks = $this->targetJira->getIssueLinks($targetIssueKey);
        // Check if the source issue has any links
        if (isset($sourceIssue['fields']['issuelinks']) && !empty($sourceIssue['fields']['issuelinks'])) {
            foreach ($sourceIssue['fields']['issuelinks'] as $link) {
                $linkType = $link['type']['name'] ?? null;
                //Check that link type is defined and is either 'relates to' or 'blocks'
                if (!$linkType || !in_array($linkType, $this->issueLinkTypes)) {
                    echo "Link type is missing for issue: {$sourceIssue['key']}\n";
                    continue; // Skip this link if the type is not defined
                }
                // Get the linked issue key
                // Check if the link is outward or inward
                // Jira API returns links in two formats: outwardIssue and inwardIssue
                // We will use the first one that is available
                // If both are available, we will use outwardIssue
                // If neither is available, we will skip this link
                $linkedIssue = $link['outwardIssue'] ?? $link['inwardIssue'] ?? null;
                if (!$linkedIssue) {
                    echo "No linked issue found for link type: $linkType in issue: {$sourceIssue['key']}\n";
                    continue; // Skip this link if no linked issue is found
                }
                $linkedIssueKey = $linkedIssue['key'] ?? null;

                if (!$linkType || !$linkedIssueKey) {
                    echo "Skipping link for issue: {$sourceIssue['key']} due to missing type or linked issue key.\n";
                    continue;
                }
                // Check if the linked issue exists in the target project
                $targetLink = $this->targetJira->getIssueByCustomField($this->customFields['Consortium Jira ID'], $linkedIssueKey, ['fields' => 'key']);
                $targetLinkKey = $targetLink['key'] ?? null;
                // If the linked issue doesn't exist, create it
                if (!$targetLinkKey) {
                    $targetLink = $this->createIssue($linkedIssue, $this->targetJira);
                    $targetLinkKey = $targetLink['key'] ?? null;
                } 
                // Check if the link already exists in the target issue
                if (!$this->getIssueLinkByKey($targetLinkKey, $targetLinks)) {
                    $this->targetJira->linkIssueByKey($targetIssueKey, $targetLinkKey, $link['type']['name']);
                } 
            }
        } 
    }

    /**
     * Syncs a single issue from the source Jira project to the target Jira project.
     * 
     * If the issue doesn't exist in the target project, it creates a new issue based on the source issue.
     * If the issue exists in the target project and skipExistingIssues is set to false, it updates the existing issue.
     * If the issue exists in the target project and skipExistingIssues is set to true, it just returns the target issue key.
     * 
     * @param array $sourceIssue The source issue data
     * @return string The key of the target issue
     */
    private function syncIssue(array $sourceIssue): string {
        // Check if the issue already exists in the target project
        $sourceKey = $sourceIssue['key'];
        // Use JQL to find the issue in the target project based on the custom field
        // Assuming 'Consortium Jira ID' is a custom field that stores the source issue key
        $targetIssue = $this->targetJira->getIssueByCustomField($this->customFields['Consortium Jira ID'], $sourceKey, ['fields' => 'key']);
        $targetIssueKey = $targetIssue['key'] ?? null;
        
        if (!$targetIssueKey) {
            // If the issue doesn't exist in the target project, create it
            echo "Issue does not exist in target project, creating new issue based on: $sourceKey\n";
            $targetIssue = $this->createIssue($sourceIssue, $this->targetJira);
            $targetIssueKey = $targetIssue['key'];
            $this->syncIssueDetails($sourceIssue, $targetIssueKey);
            echo "Created new issue: $targetIssueKey based on $sourceKey\n";
            $this->createCount++;
        } else if (!$this->skipExistingIssues) {        
            // If issue exists, update it
            echo "Issue exists in target project, updating existing issue: $targetIssueKey based on $sourceKey\n";
            $this->updateIssue($targetIssueKey, $sourceIssue, $this->targetJira);
            $this->syncIssueDetails($sourceIssue, $targetIssueKey);
            $this->updateCount++;
        } else {
            // If the issue exists and we are skipping existing issues, just return the target issue key
            echo "Skipping existing issue: $targetIssueKey based on $sourceKey\n";
        }

        return $targetIssueKey;
    }

    /**
     * Syncs the issue details from the source issue to the target issue.
     * 
     * This function takes in the source issue and target issue key as input and
     * syncs the issue links, status transition, attachments, and comments from the
     * source issue to the target issue.
     * 
     * @param array $sourceIssue The source issue data.
     * @param string $targetIssueKey The key of the target issue.
     */
    private function syncIssueDetails(array $sourceIssue, string $targetIssueKey): void {
        //If the issue creation or update failed, skip further processing
        if (!$targetIssueKey) {
            echo "Failed to sync issue: {$sourceIssue['key']}\n";
            return;
        }
        
        $this->syncIssueLinks($sourceIssue, $targetIssueKey);
        // Sync status transition
        $this->syncStatusTransition($sourceIssue, $targetIssueKey);

        // Sync attachments
        $this->syncAttachments($sourceIssue, $targetIssueKey);
        
        // Sync comments
        $this->syncComments($sourceIssue, $targetIssueKey);
        echo "Finished syncing issue: {$sourceIssue['key']} to issue: $targetIssueKey\n";
    }

    /******************* Helper Methods *******************/
    

    /**
     * Updates the payload with all the necessary issue parameters.
     *
     * This function takes in the payload, source issue and target issue key as input and
     * updates the payload with the description, components, fix version, priority, labels,
     * and reporter information from the source issue.
     *
     * @param array $payload The payload data for the target issue.
     * @param array $issue The source issue data used to update the target issue.
     * @param string $targetIssueKey The key of the target issue.
     * @return array The updated payload data with all the synchronized issue parameters.
     */
    private function addParamsToPayload(array $payload, array $issue, string $targetIssueKey): array {
        $payload = $this->syncDescription($payload, $issue, $targetIssueKey);
        $payload = $this->syncComponents($payload, $issue);
        $payload = $this->syncFixVersion($payload, $issue);
        $payload = $this->syncPriority($payload, $issue);
        $payload = $this->syncLabels($payload, $issue);
        $payload = $this->syncReporter($payload, $issue);
        
        return $payload;
    }

    /**
     * Adds the original author's name to a comment.
     *
     * This function takes a source comment as input and checks for the author's display name.
     * If an author is available, it constructs an array representing a paragraph node
     * containing a text node with the author's name, formatted as "(Originally commented by {authorName})".
     *
     * @param array $sourceComment The source comment data, including the author's information.
     * @return array An array representing the paragraph node with the author's name, or an empty string if no author is found.
     */
    private function addOriginalAuthorToComment(array $sourceComment): ?array {
        $authorName = $sourceComment['author']['displayName'] ?? null;
        $node = null;
        if (!empty($authorName)) {
            // Add the original author as a comment
            $node = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "(Originally commented by $authorName)"
                    ]
                ]
            ];
        } 

        return $node;
    }

    /**
     * Does the $issueKey exist in the links array?
     *
     * @param string $issueKey The key of the issue we want to add.
     * @param array $links A list of issues already linked to the target issue.
     * @return array An array of issue links if found, or an empty array if no links are present.
     */
    private function getIssueLinkByKey(string $issueKey, array $links): array {
        // Check if the links array is empty
        if (empty($links)) {
            return [];
        }
        // Iterate through the links to find the issue key
        foreach ($links as $link) {
            // Check if the link has an outwardIssue or inwardIssue
            $outwardIssue = $link['outwardIssue']['key'] ?? null;
            $inwardIssue = $link['inwardIssue']['key'] ?? null;

            // If either matches the issueKey, return the link
            if ($outwardIssue === $issueKey || $inwardIssue === $issueKey) {
                return $link;
            }
        }
        // If no matching link is found, return an empty array
        return [];
    }

    /******************* Sanitization Helpers *******************/
    /**
     * Sanitizes an ADF (Atlassian Document Format) node for Jira issues.
     *
     * This function processes an ADF node by performing the following steps:
     * 1. Uploads and transforms any media attachments found within the node,
     *    associating them with the target Jira issue.
     * 2. Flattens the node structure to prevent issues caused by excessive nesting depth.
     *
     * @param array $node The ADF node to be sanitized.
     * @param array $sourceIssue The source Jira issue containing the node.
     * @param string $targetIssueKey The key of the target Jira issue.
     * @return array The sanitized ADF node.
     */
     private function sanitizeADF(array $node, array $sourceIssue, string $targetIssueKey): array {
        // Step 1: Upload and transform attachments (presumably handles image nodes)
        $withUploads = $this->uploadAttachmentsFromADF($node, $sourceIssue, $targetIssueKey);
    
        // Step 2: Sanitize and flatten
        return $this->flattenADF($withUploads);
    }

    /**
     * Sanitizes the summary of a Jira issue by appending the original issue key,
     * and truncating the summary if it is too long.
     *
     * @param array $sourceIssue The source Jira issue containing the summary.
     * @return string The sanitized summary.
     */
    private function sanitizeSummary(array $sourceIssue): string {
        //Append the original issue key to the summary
        $summary = $sourceIssue['key'] . ": " . $sourceIssue['fields']['summary'];
        //truncate if too long
        if (strlen($summary) > self::SUMMARY_MAX_LENGTH) {
            $summary = substr($summary, 0, self::SUMMARY_MAX_LENGTH - 3) . '...';
        }
        return $summary;
    }
    
    /**
     * Flatten an ADF node into a valid Jira document.
     *
     * This function processes an ADF node by performing the following steps:
     * 1. Wraps the node in a valid ADF document structure.
     * 2. Flattens the node structure to prevent issues caused by excessive nesting depth.
     *
     * @param array $adf The ADF node to be flattened.
     * @param int $depth The current depth of the node structure.
     * @return array The flattened ADF node.
     */
    private function flattenADF(array $adf, int $depth = 0): array {
        if (!isset($adf['type']) || $adf['type'] !== 'doc') {
            $adf = [
                'type' => 'doc',
                'version' => 1,
                'content' => [$adf],
            ];
        }
    
        $content = $this->flattenADFContent($adf['content'] ?? [], $depth);
        if (empty($content)) {
            $content = [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => '[No valid content preserved]',
                ]]
            ]];
        }
    
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }
    
    
    /**
     * Flattens an ADF node's content array to prevent issues caused by excessive nesting depth.
     *
     * This function processes an ADF node's content array by performing the following steps:
     * 1. Truncates the content if it exceeds a maximum nesting depth of 8.
     * 2. Skips empty or invalid paragraphs.
     * 3. Removes unsupported nodes.
     * 4. Fixes mentions by dropping them if they are missing required attributes.
     * 5. Sanitizes each remaining node using the `sanitizeADFNode` method.
     *
     * @param array $nodes The ADF node's content array to be flattened.
     * @param int $depth The current depth of the node structure.
     * @return array The flattened ADF node's content array.
     */
    private function flattenADFContent(array $nodes, int $depth): array {
        if ($depth > 8) {
            return [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => '[Content truncated due to excessive nesting]',
                ]],
            ]];
        }
    
        $flattened = [];
        foreach ($nodes as $node) {
            // Skip empty or content arrays
            if (in_array($node['type'], ['paragraph', 'listItem', 'bulletList', 'orderedList', 'panel', 'mediaGroup'])
                && isset($node['content']) && empty($node['content'])) {
                continue;
            }
    
            // Remove unsupported nodes
            if (in_array($node['type'], ['inlineCard', 'emoji'])) {
                continue;
            }
    
            // Fix mentions: drop if missing required attrs
            if ($node['type'] === 'mention') {
                $attrs = $node['attrs'] ?? [];
                if (!isset($attrs['userType']) || !isset($attrs['accessLevel'])) {
                    continue;
                }
            }
    
            if (!is_array($node) || !isset($node['type'])) {
                continue;
            }
            
            try {
                $sanitized = $this->sanitizeADFNode($node, $depth);
                if (isset($sanitized['type'])) {
                    $flattened[] = $sanitized;
                }
            } catch (Exception $e) {
                $this->log->warning("Skipping malformed ADF node during flatten", ['node' => $node, 'error' => $e->getMessage()]);
            }
        }
    
        return $flattened;
    }

    /**
     * Validates a final ADF node, ensuring it is ready to be sent to Jira.
     *
     * This function processes a final ADF node by performing the following steps:
     * 1. Skips empty or invalid nodes.
     * 2. Ensures 'content' is an array, or removes it if not.
     * 3. Recursively validates content.
     * 4. Skips malformed 'marks'.
     * 5. Removes empty 'attrs' unless required.
     *
     * @param array $node The ADF node to be validated.
     * @return array|null The validated ADF node, or null if it is not valid.
     */
    private function validateFinalADF(array $node): ?array {
        if (!is_array($node) || !isset($node['type'])) {
            return null;
        }
    
        // Force 'content' to be an array if present, or remove if invalid
        if (isset($node['content']) && !is_array($node['content'])) {
            unset($node['content']);
        }
    
        // Validate content recursively
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_values(array_filter(array_map(
                fn($child) => $this->validateFinalADF($child),
                $node['content']
            )));
        }
    
        // Strip marks if malformed
        if (isset($node['marks']) && !is_array($node['marks'])) {
            unset($node['marks']);
        }
    
        // Strip empty attrs unless it's required
        if (isset($node['attrs']) && !is_array($node['attrs'])) {
            unset($node['attrs']);
        }
    
        return $node;
    }
    
    
    /**
     * Sanitizes an ADF node, ensuring it has a valid format.
     *
     * - Ensures 'attrs' is an object, not an empty array (for tables, table rows, table cells, and table headers).
     * - Removes empty 'attrs' property.
     * - Keeps only valid 'marks'.
     * - Sanitizes nested content:
     *   - For table cells, flattens content to a single paragraph.
     *   - For other nodes, flattens content recursively.
     * - Downgrades invalid nodes inside a table to a paragraph.
     * - Only retains allowed ADF keys.
     *
     * @param array $node The ADF node.
     * @param int $depth The current depth of the node structure.
     * @return array The sanitized ADF node.
     */
    private function sanitizeADFNode(array $node, int $depth): array {
        // Fix: Ensure attrs is an object, not an empty array
        if (in_array($node['type'], ['table', 'tableRow', 'tableCell', 'tableHeader'])) {
            if (is_array($node['attrs'] ?? null) && empty($node['attrs'])) {
                unset($node['attrs']); // remove empty attrs
            }
        }

        // Sanitize nested content
        if (isset($node['content']) && is_array($node['content'])) {
            if (in_array($node['type'], ['tableHeader', 'tableCell'])) {
                $node['content'] = $this->sanitizeTableCellContent($node['content'], $depth + 1);
            } else {
                $node['content'] = $this->flattenADFContent($node['content'], $depth + 1);
            }
        }
    
        // Sanitize Paragraphs
        if ($node['type'] === 'paragraph' && isset($node['content']) && is_array($node['content'])) {
            $node['content'] = $this->sanitizeParagraphContent($node['content']);
        }
    
        // Keep only valid marks
        if (isset($node['marks']) && is_array($node['marks'])) {
            $node['marks'] = array_values(array_filter($node['marks'], function ($mark) {
                return is_array($mark) && isset($mark['type']) && is_string($mark['type']);
            }));
        }
    
        // Downgrade invalid nodes inside table
        if ($depth >= 2 && in_array($node['type'], ['heading']) && $this->isParentTableCellContext($depth)) {
            $node['type'] = 'paragraph';
            unset($node['attrs']);
        }
    
        // Only retain allowed ADF keys
        $allowedKeys = ['type', 'content', 'text', 'marks', 'attrs'];
        return array_intersect_key($node, array_flip($allowedKeys));
    }
    

    /**
     * Sanitizes a paragraph node by flattening any nested paragraphs.
     *
     * Recursively traverses the content of a paragraph node and flattens any
     * nested paragraph nodes. This prevents nested paragraphs from being
     * inserted into the Jira issue.
     *
     * @param array $node The paragraph node to be sanitized.
     * @return array The sanitized paragraph node.
     */
    private function sanitizeParagraphContent(array $content): array {
        $flattened = [];
    
        foreach ($content as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'paragraph') {
                $innerContent = $child['content'] ?? [];
                // Recursively sanitize nested content
                $flattened = array_merge($flattened, $this->sanitizeParagraphContent($innerContent));
            } else {
                $flattened[] = $child;
            }
        }
    
        return $flattened;
    }
    
    /**
     * Sanitizes the content of a table cell.
     * 
     * Sanitizes the content of a table cell by flattening the content and downgrading any headings to paragraphs.
     * 
     * @param array $content The content of the table cell.
     * @param int $depth The current depth of the node structure.
     * @return array The sanitized content of the table cell.
     */
    private function sanitizeTableCellContent(array $content, int $depth): array {
        $sanitized = [];
    
        foreach ($content as $child) {
            if (isset($child['type']) && strpos($child['type'], 'heading') === 0) {
                $sanitized[] = [
                    'type' => 'paragraph',
                    'content' => isset($child['content']) ? $this->flattenADFContent($child['content'], $depth + 1) : [],
                ];
            } else {
                $sanitized[] = $this->sanitizeADFNode($child, $depth);
            }
        }
    
        return $sanitized;
    }
    
    /**
     * Determines if the context is a table cell by checking if the current depth is >= 2.
     * 
     * @param int $depth The current depth of the node structure.
     * @return bool True if the context is a table cell; false otherwise.
     */
    private function isParentTableCellContext(int $depth): bool {
        return $depth >= 2;
    }

    /**
     * Extracts the plain text from an ADF node.
     *
     * This method recursively traverses the ADF node structure and concatenates the text of each node into a single string.
     *
     * @param array $adf The ADF node to extract the plain text from.
     * @return string The plain text from the ADF node.
     */
    private function adfToPlainText(array $adf): string {
        $text = '';
    
        $extractText = function ($nodes) use (&$extractText, &$text) {
            foreach ($nodes as $node) {
                if (isset($node['text'])) {
                    $text .= $node['text'];
                }
                if (isset($node['content'])) {
                    $extractText($node['content']);
                    $text .= "\n";
                }
            }
        };
    
        $extractText($adf['content'] ?? []);
        return trim($text);
    }       

    /**
     * Sanitizes a label string by removing special characters and replacing spaces with hyphens.
     * 
     * @param string $label The label string to be sanitized.
     * @return string The sanitized label string.
     */
    private function sanitizeLabel(string $label): string {
        // Remove special characters (except space)
        $label = preg_replace('/[^a-zA-Z0-9\s]/', '', $label);
        // Replace spaces with hyphens
        return str_replace(' ', '-', $label);
    }

    /**
     * Maps source issue types to target issue types.
     *
     * @param array $targetTypes An array of issue types available in the target Jira.
     * @param array $sourceTypes An array of issue types available in the source Jira.
     * @param array $customMapping An optional associative array of custom issue type mappings.
     * @return array An associative array of source issue type => target issue type.
     *
     * This function takes an array of target issue types and an array of source issue types,
     * and creates a mapping between them. If a custom mapping is provided, it is used first.
     * Otherwise, the function will attempt to find a direct match between the two arrays.
     * If no direct match is found, the function will fall back to mapping unknown issue types
     * to either 'Task' or 'Subtask', depending on the name of the source issue type.
     */
    private function mapIssueTypes(array $targetTypes, array $sourceTypes, array $customMapping = []): array {
        // Normalize target types to name => subtask flag
        $targetMap = [];
        foreach ($targetTypes as $type) {
            $normalized = strtolower(str_replace('-', '', $type['name']));
            $targetMap[$normalized] = $type['name'];
        }
    
        $resultMap = [];
    
        foreach ($sourceTypes as $sourceType) {
            $sourceKey = strtolower(str_replace('-', '', $sourceType['name']));
    
            // Custom override
            if (isset($customMapping[$sourceType['name']])) {
                $resultMap[$sourceType] = $customMapping[$sourceType['name']];
                continue;
            }
    
            // Direct match
            if (isset($targetMap[$sourceKey])) {
                $resultMap[$sourceType['name']] = $targetMap[$sourceKey];
            } else {
                // Fallback logic for unknown types
                switch ($sourceKey) {
                    case 'support':
                        $resultMap[$sourceType['name']] = $targetMap['task'] ?? 'Task';
                        break;
                    case 'subtask':
                    case 'subtask': // handle dash or no dash
                        $subtask = array_filter($targetTypes, fn($t) => $t['subtask'] === true);
                        $resultMap[$sourceType['name']] = reset($subtask)['name'] ?? 'Subtask';
                        break;
                    default:
                        $resultMap[$sourceType['name']] = $targetMap['task'] ?? 'Task'; // catch-all
                }
            }
        }
    
        return $resultMap;
    }
    

    /******************* Attachment Helpers *******************/

    /**
     * Creates a temporary file on the filesystem, given file data and optional target directory and filename.
     * If no directory is specified, the system temporary directory is used.
     * If no filename is specified, a unique filename is generated.
     * The file is written to the specified path and the path is returned.
     * If any errors occur during the operation, null is returned and an error message is output to the console.
     *
     * @param string $fileData The contents of the file to write
     * @param string|null $targetDirectory The directory to write the file to. If null, the system temporary directory is used.
     * @param string|null $fileName The name of the file to write. If empty, a unique filename is generated.
     * @return string|null The path of the written file, or null if an error occurred.
     */
    private function createTempFile(string $fileData, ?string $targetDirectory = null, ?string $fileName = null): ?string {
        // If no directory  use the temporary directory
        if ($targetDirectory === null) {
            $targetDirectory = sys_get_temp_dir();
        }
        // Ensure the target directory exists
        if (!is_dir($targetDirectory)) {
            if (!mkdir($targetDirectory, 0777, true)) {
                echo "Failed to create directory: $targetDirectory\n";
                return null;
            }
        }

        //If no file name is provided, generate a unique name
        if (empty($fileName)) {
            $safeFileName = uniqid('attachment_', true) . '.tmp';
        } else {
            // Sanitize the filename to prevent directory traversal vulnerabilities
            $safeFileName = basename($fileName);
        }

        $filePath = rtrim($targetDirectory, '/') . '/' . $safeFileName;
        

        // Write the file data to the specified path
        $bytesWritten = file_put_contents($filePath, $fileData);
        if ($bytesWritten === false) {
            echo "Failed to write to file: $filePath\n";
            return null;
        }

        return $filePath;
    }

    /**
     * Uploads an attachment to a target issue if it doesn't already exist.
     * Downloads the attachment content from the source Jira instance,
     * saves it to a temporary file, uploads it to the target Jira instance,
     * and returns the URL of the new attachment.
     * If the attachment already exists in the target issue, returns the existing attachment.
     * @param string $targetIssueKey The key of the target issue to which the attachment should be uploaded.
     * @param string $sourceFilePath The URL of the attachment in the source Jira instance.
     * @param string $sourceFileName The name of the attachment file.
     * @return array|null The response from the API, or null if the upload failed.
     * @throws Exception If the attachment could not be downloaded, or if the temporary file could not be created.
     */
    private function uploadAttachment(string $targetIssueKey, string $sourceFilePath, string $sourceFileName): ?array {
        $targetAttachment = $this->targetJira->getIssueAttachmentByFileName($targetIssueKey, $sourceFileName);
        // If the attachment already exists in the target issue, skip it
        if ($targetAttachment) {
            return $targetAttachment;
        }
        
        // Download the attachment content
        $fileData = $this->sourceJira->downloadAttachment($sourceFilePath);
        if ($fileData === null) {
            throw new Exception("Failed to download attachment: $sourceFileName");
        }
        
        $tempFilePath = $this->createTempFile($fileData, "./attachments/", $sourceFileName);
        if ($tempFilePath === null) {
            throw new Exception("Failed to create temporary file for attachment: $sourceFileName");
        }

        // Upload the attachment to the target issue
        $response = $this->targetJira->uploadAttachment($targetIssueKey, $tempFilePath);
        
        // Clean up the temporary file
        unlink($tempFilePath);
        return $response ?? null; // Return the new attachment URL
    }

    /**
     * Recursively traverse an ADF node and upload any attachments to the target Jira instance.
     * Replaces media nodes with a link paragraph.
     *
     * @param array $node The ADF node to traverse
     * @param array $sourceIssue The source issue data
     * @param string $targetIssueKey The target issue key
     * @return array The modified ADF node
     */
    private function uploadAttachmentsFromADF(array $node, array $sourceIssue, string $targetIssueKey): array {
        global $log;
    
        if (!is_array($node)) return $node;
    
        // Flatten nested 'doc' nodes
        if ($node['type'] === 'doc' && isset($node['content']) && is_array($node['content'])) {
            $flattenedContent = [];
            foreach ($node['content'] as $child) {
                if ($child['type'] === 'doc' && isset($child['content'])) {
                    // Merge the child 'doc' content into the parent
                    $flattenedContent = array_merge($flattenedContent, $child['content']);
                } else {
                    $flattenedContent[] = $child;
                }
            }
            $node['content'] = $flattenedContent;
        }
    
        // Existing mediaGroup handling
        if ($node['type'] === 'mediaGroup' && isset($node['content']) && is_array($node['content'])) {
            $validMedia = [];
    
            foreach ($node['content'] as $child) {
                if ($child['type'] === 'media') {
                    $converted = $this->uploadAttachmentsFromADF($child, $sourceIssue, $targetIssueKey);
                    if ($converted) {
                        $validMedia[] = $converted;
                    }
                } else {
                    $log->warning("Removing invalid child from mediaGroup", [
                        'childType' => $child['type'],
                        'node' => $child
                    ]);
                }
            }
    
            // Replace mediaGroup with multiple paragraphs (one per attachment)
            return count($validMedia) === 1
                ? $validMedia[0]
                : ['type' => 'doc', 'content' => $validMedia];
        }
    
        // Recurse through children
        if (isset($node['content']) && is_array($node['content'])) {
            $filtered = array_map(function ($child) use ($sourceIssue, $targetIssueKey) {
                return $this->uploadAttachmentsFromADF($child, $sourceIssue, $targetIssueKey);
            }, $node['content']);
            $node['content'] = array_values(array_filter($filtered));
    
            // Unwrap invalid mediaSingle
            if ($node['type'] === 'mediaSingle' && count($node['content']) === 1 && $node['content'][0]['type'] === 'paragraph') {
                return $node['content'][0];
            }
        }
    
        // Replace media node with paragraph containing a link
        if ($node['type'] === 'media') {
            $filename = $node['attrs']['alt'] ?? null;
            $mediaId = $node['attrs']['id'] ?? null;
    
            $attachment = !empty($filename)
                ? $this->sourceJira->getIssueAttachmentByFileName($sourceIssue["key"], $filename)
                : null;
    
            $attachmentUrl = $attachment['content'] ?? null;
    
            if (empty($attachmentUrl) || empty($filename)) {
                $log->warning("Skipping media node: missing URL or filename.", [
                    'issueKey' => $sourceIssue['key'],
                    'filename' => $filename,
                    'attachmentUrl' => $attachmentUrl,
                    'mediaId' => $mediaId,
                    'node' => $node
                ]);
                return [
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => $filename ?? $mediaId ?? 'Attachment missing',
                    ]]
                ];
            }
    
            $href = $filename;
            try {
                $uploaded = $this->uploadAttachment($targetIssueKey, $attachmentUrl, $filename);
                $href = $uploaded['content'] ?? $filename;
            } catch (JiraApiException $e) {
                $log->error("Failed to upload attachment: {$filename} for issue {$sourceIssue['key']}: " . $e->getMessage(), $e->toContextArray());
            } catch (Exception $e) {
                $log->error("Unexpected error uploading attachment: {$filename} for issue {$sourceIssue['key']}: " . $e->getMessage(), [
                    'issueKey' => $sourceIssue['key'],
                    'filename' => $filename,
                    'attachmentUrl' => $attachmentUrl,
                    'node' => $node
                ]);
            }
    
            return [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $filename,
                    'marks' => [[
                        'type' => 'link',
                        'attrs' => ['href' => $href]
                    ]]
                ]]
            ];
        }
    
        return $node;
    }    
}