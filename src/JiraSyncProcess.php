<?php

namespace Uo\AtlassianJiraMigration;

use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;


class JiraSyncProcess {
    private $sourceJira;
    private $targetJira;
    private $customFields;
    private $issueLinkTypes;
    private $issueTypeMap = [];
    // Logger instance
    // Using Monolog for logging
    private  $log = null;
    

    public function __construct(AtlassianAPIEndpoints $sourceJira, AtlassianAPIEndpoints $targetJira, array $customFields, array $issueLinkTypes, array $customMap, Logger $logger = null) {
        $this->sourceJira = $sourceJira;
        $this->targetJira = $targetJira;
        $this->customFields = $customFields;
        $this->issueLinkTypes = $issueLinkTypes;
        $this->issueTypeMap = $this->mapIssueTypes($sourceJira->getIssueTypes(), $targetJira->getIssueTypes(), $customMap);
        // Initialize logger if provided
        $this->log = $logger;
        if (!$this->log) {
            $this->log = new Logger('jira_sync');
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/app.log', Logger::DEBUG));
        }
    }

    public function syncIssues($startAt = 0, $maxResults = 100) {
        $startTime = microtime(true);
        $batch = 1;
        do {
            echo "Starting batch $batch";
            // Fetch issues from the source Jira project
            $jql = "project = \"{$this->sourceJira->getProjectKey()}\"";
            //$jql = "project = \"$sourceProjectKey\" AND  issueLinkType IS NOT EMPTY";
            //$jql = "project = \"$sourceProjectKey\" AND  key IN (ME-10590,ME-2679)";
            // Add additional parameters if needed
            $params = [
                'startAt' => $startAt,
                'maxResults' => $maxResults,
                'fields' => 'parent,project,key,summary,description,issuetype,components,status,reporter,priority,fixVersions,labels,issuelinks'
            ];
            $data = $this->sourceJira->getIssuesByJQL($jql, $params);
                
            if ($data && !empty($data['issues'])) {
                foreach ($data['issues'] as $issue) {
                    try {
                        echo "\nProcessing issue: {$issue['key']}\n";
                        $this->syncIssueDetails($issue);
                    } catch (JiraApiException $e) {
                        echo "Error processing issue: {$issue['key']}. Error: " . $e->getMessage() . "\n";
                        self::$log->error("Error processing issue: {$issue['key']} {$e->getMessage()}", $e->toContextArray());
                        // Put the stack trace in the log
                        self::$log->error($e->getTraceAsString());
                        continue; // Skip to the next issue if there's an error
                    }
                    sleep(1); // To respect API rate limits
                }
            } 
            else { break;}
            $startAt += $maxResults;
            $batch++;
            echo "Time taken: " . (microtime(true) - $startTime) . " seconds\n";
            echo "Time taken: " . (microtime(true) - $startTime)/60 . " minutes\n";
        } while ($startAt < $data['total']);  
        echo "Finished syncing issues from {$this->sourceJira->getProjectKey()} to {$this->targetJira->getProjectKey()}\n";
        echo "Time taken: " . (microtime(true) - $startTime) . " seconds\n";
        echo "Time taken: " . (microtime(true) - $startTime)/60 . " minutes\n";
    }  
    
    /******************* Issue Creation and Update Methods *******************/

    /**
     * Create a new issue in the target project based on the source issue.
     *
     * @param array $issue The source issue data.
     * @param AtlassianAPIEndpoints $jira The Jira API client instance.
     * @return string|null The key of the created issue or null on failure.
     */

    function createIssue($issue, $jira) {
        global $log;
        $issueTypeName = $this->issueTypeMap[$issue['fields']['issuetype']['name']] ??  $issue['fields']['issuetype']['name'] ?? 'Task';
        
        $payload = [
            "fields" => [
                "project" => [
                    "key" => $jira->getProjectKey()
                ],
                "summary" => $issue['key'] . ": " . $issue['fields']['summary'],
                "issuetype" => [
                    "name" => $issueTypeName ?? 'Task' // Default to 'Task' if issue type is not set
                ],
                "customfield_" . $this->customFields['Consortium Jira ID'] => $issue['key'] // Set the custom field with the source issue key
            ]
        ];
        $payload = $this->syncParent($payload, $issue, $jira);
        
        $response = $jira->createIssue($payload);
        if (isset($response['key'])) {
            // Call updateIssue to set additional fields
            return $this->updateIssue($response['key'], $issue, $jira);       
        } else {
            $log->error("Failed to create issue", [
                'response' => $response,
                'payload' => $payload
            ]);
            return null;
        }

    }

    function updateIssue($issueKey, $sourceIssue, $jira) {
        global $log;
        $payload = [
            "fields" => [
                "summary" => $sourceIssue['key'] . ": " . $sourceIssue['fields']['summary'],
                "customfield_" . $this->customFields['Consortium Jira ID'] => $sourceIssue['key'] // Set the custom field with the source issue key
            ], 
            
        ];
        $payload = $this->addParamsToPayload($payload, $sourceIssue, $issueKey);
        $response = $jira->updateIssue($issueKey, $payload);
        if ($jira->getHttpCode() === 204) {
            return $issueKey; // Return the target issue key
        } else {
            $log->error("Failed to create $issueKey", [
                'response' => $response,
                'payload' => $payload
            ]);
            return null;
        }

    }

    /******************* Sync Methods *******************/

    function syncDescription($payload, $sourceIssue, $targetIssueKey) {
        $description = $sourceIssue['fields']['description'] ?? null;

        if (!empty($description) && is_array($description)) {
            $transformedADF = $this->uploadAttachmentsFromADF($description, $sourceIssue, $targetIssueKey);
            $payload["fields"]["description"] = $transformedADF;
        } 

        return $payload;
    }

    function syncParent($payload, $issue, $jira) {
        $targetParentKey = $this->getParentKey($issue, $jira);

        if ($targetParentKey) {
            $payload["fields"]["parent"] = [
                "key" => $targetParentKey
            ];
        }

        return $payload;
    }

    function syncLabels($payload, $sourceIssue) {
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
     * @param array $headers The HTTP headers to be used in the request.
     */

    function syncComponents($payload, $sourceIssue) {
        $components = $sourceIssue['fields']['components'] ?? [];
        $componentNames = array_map(function($component) {
            return $this->sanitizeLabel($component['name']);
        }, $components);

        if (!empty($componentNames)) {
            $payload["fields"]["customfield_" . $this->customFields['Components']] = $componentNames;
        } 

        return $payload;
    }

    function syncFixVersion($payload, $sourceIssue) {
        $fixVersions = $sourceIssue['fields']['fixVersions'] ?? [];
        if (!empty($fixVersions)) {
            $versionNames = array_map(function($version) {
                return $version['name'];
            }, $fixVersions);
            $payload["fields"]["customfield_" . $this->customFields['Fix Version']] = $versionNames;
        } 
        return $payload;
    }

    function syncPriority($payload, $sourceIssue) {
        $priority = $sourceIssue['fields']['priority']['name'] ?? null; 
        if (!empty($priority) && $priority !== 'Undetermined') {
            $payload["fields"]["priority"] = [
                "name" => $priority
            ];
        } 
        return $payload;
    }

    function syncReporter($payload, $sourceIssue) {
        $reporter = $sourceIssue['fields']['reporter']['displayName'] ?? null;
        if (!empty($reporter)) {
            $payload["fields"]["customfield_" . $this->customFields['Reporter Name']] = $reporter;
        } 
        return $payload;
    }

    function syncStatusTransition($sourceIssue, $targetIssueKey, $targetJira) {
        //Get the transitions available for the target issue
        $transitions = $targetJira->getIssueTransitions($targetIssueKey);
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
            $targetJira->transitionIssue($targetIssueKey, $transitionId);
        }
    }

    function syncComments($sourceIssue, $targetIssueKey, $sourceJira, $targetJira) {
        // If the target issue already has comments, skip syncing
        $targetComment = $targetJira->getIssueComments($targetIssueKey);
        if ($targetComment && !empty($targetComment['comments'])) {
            return;
        }
        
        $comments = $sourceJira->getIssueComments($sourceIssue['key']);
        if ($comments && !empty($comments['comments'])) {
            foreach ($comments['comments'] as $comment) {
                $cleanADF = $this->sanitizeADF($comment['body'], $sourceIssue, $targetIssueKey);
                $cleanADF['content'][] = $this->addOriginalAuthorToComment($comment);
            
                $targetJira->createIssueComment($targetIssueKey, $cleanADF);
            }        
        }
    }

    function syncAttachments($sourceIssue, $targetIssueKey, $sourceJira, $targetJira) {
        // Fetch attachments from the source issue
        $attachments = $sourceJira->getIssueAttachments($sourceIssue['key']);

        if ($attachments && !empty($attachments)) {
            foreach ($attachments as $attachment) {
                // Upload the attachment to the target issue
                try {
                    $this->uploadAttachment($targetIssueKey,$attachment['content'], $attachment['filename'], $sourceJira, $targetJira);
                } catch (JiraApiException $e) {
                    echo "Error uploading attachment for issue: {$sourceIssue['key']}. Error: " . $e->getMessage() . "\n";
                    self::$log->error("Error uploading attachment for issue: {$sourceIssue['key']} {$e->getMessage()}", $e->toContextArray());
                    continue; // Skip to the next attachment if there's an error
                } catch (Exception $e) {
                    echo "Unexpected error uploading attachment for issue: {$sourceIssue['key']}. Error: " . $e->getMessage() . "\n";
                    self::$log->error("Unexpected error uploading attachment for issue: {$sourceIssue['key']} {$e->getMessage()}");
                    continue; // Skip to the next attachment if there's an error
                }
                
            }
        } 
    }

    function syncIssueLinks($sourceIssue, $targetIssueKey) {
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
                    $targetLinkKey = $this->createIssue($linkedIssue, $this->targetJira);
                } 
                // Check if the link already exists in the target issue
                if (!$this->getIssueLinkByKey($targetLinkKey, $targetLinks)) {
                    $this->targetJira->linkIssueByKey($targetIssueKey, $targetLinkKey, $link['type']['name']);
                } 
            }
        } 
    }



    function getParentKey($sourceIssue, $targetJira) {
        // Check if the source issue has a parent
        if (isset($sourceIssue['fields']['parent'])) {
            $parentKey = $sourceIssue['fields']['parent']['key'];
            // Use JQL to find the parent issue in the target project
            $targetParentIssue = $targetJira->getIssueByCustomField($this->customFields['Consortium Jira ID'], $parentKey, ['fields' => 'key']);
        
            $targetParentIssueKey = $targetParentIssue['key'] ?? null;
            
            if (!$targetParentIssueKey) {
                // If the parent issue doesn't exist in the target project, create it
                $targetParentIssueKey = $this->createIssue($sourceIssue['fields']['parent'], $targetJira);
            } 

            return $targetParentIssueKey;
        }
        return null; // No parent to sync
    }

    function syncIssue($sourceIssue) {
        // Check if the issue already exists in the target project
        $sourceKey = $sourceIssue['key'];
        // Use JQL to find the issue in the target project based on the custom field
        // Assuming 'Consortium Jira ID' is a custom field that stores the source issue key
        $targetIssue = $this->targetJira->getIssueByCustomField($this->customFields['Consortium Jira ID'], $sourceKey, ['fields' => 'key']);
        $targetIssueKey = $targetIssue['key'] ?? null;
        
        if (!$targetIssueKey) {
            // If the issue doesn't exist in the target project, create it
            echo "Issue does not exist in target project, creating new issue based on: $sourceKey\n";
            $targetIssueKey = $this->createIssue($sourceIssue, $this->targetJira);
            echo "Created new issue: $targetIssueKey based on $sourceKey\n";
        } else {        
            // If issue creation failed, try to update it
            echo "Issue exists in target project, updating existing issue: $targetIssueKey based on $sourceKey\n";
            $targetIssueKey = $this->updateIssue($targetIssueKey, $sourceIssue, $this->targetJira);
        }
        
        return $targetIssueKey;
    }

    function syncIssueDetails($sourceIssue) {
        // Create or update the issue
        $targetIssueKey = $this->syncIssue($sourceIssue, $this->targetJira);

        //If the issue creation or update failed, skip further processing
        if (!$targetIssueKey) {
            echo "Failed to sync issue: {$sourceIssue['key']}\n";
            return;
        }
        
        $this->syncIssueLinks($sourceIssue, $targetIssueKey);
        // Sync status transition
        $this->syncStatusTransition($sourceIssue, $targetIssueKey, $this->targetJira);

        // Sync attachments
        $this->syncAttachments($sourceIssue, $targetIssueKey, $this->sourceJira, $this->targetJira);
        
        // Sync comments
        $this->syncComments($sourceIssue, $targetIssueKey, $this->sourceJira, $this->targetJira);
        echo "Finished syncing issue: {$sourceIssue['key']} to issue: $targetIssueKey\n";
    }

    /******************* Helper Methods *******************/
    

    function addParamsToPayload($payload, $issue, $targetIssueKey) {
        $payload = $this->syncDescription($payload, $issue, $targetIssueKey);
        $payload = $this->syncComponents($payload, $issue);
        $payload = $this->syncFixVersion($payload, $issue);
        $payload = $this->syncPriority($payload, $issue);
        $payload = $this->syncLabels($payload, $issue);
        $payload = $this->syncReporter($payload, $issue);
        
        return $payload;
    }

    

    function addOriginalAuthorToComment($sourceComment) {
        $authorName = $sourceComment['author']['displayName'] ?? null;
        $node = "";
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

    function getIssueLinkByKey($issueKey, $links) {
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

    function removeMediaFromADF($node) {
        if (!is_array($node)) return $node;

        // If the node is media or mediaSingle, remove it
        if (isset($node['type']) && in_array($node['type'], ['media', 'mediaSingle'])) {
            return null;
        }

        // If the node has children, recurse into them
        if (isset($node['content']) && is_array($node['content'])) {
            // Clean children
            $filtered = array_map('removeMediaFromADF', $node['content']);
            // Filter out nulls (removed nodes)
            $node['content'] = array_values(array_filter($filtered));
        }

        return $node;
    }

    function sanitizeIssueType($issueType, $issueMapping) {
        // If the issue type is not in the mapping, return null
        if (!isset($issueMapping[$issueType])) {
            echo "Issue type '$issueType' not found in mapping.\n";
            return null;
        }
        // Return the mapped issue type
        return $issueMapping[$issueType];
    }

    function sanitizeLabel($label) {
        // Remove special characters (except space)
        $label = preg_replace('/[^a-zA-Z0-9\s]/', '', $label);
        // Replace spaces with hyphens
        return str_replace(' ', '-', $label);
    }

    function sanitizeADF($node, $sourceIssue, $targetIssueKey) {
        $node = $this->uploadAttachmentsFromADF($node, $sourceIssue, $targetIssueKey);
        // Remove media nodes
        //$node = removeMediaFromADF($node);
        return $node;
    }

    function mapIssueTypes(array $targetTypes, array $sourceTypes, array $customMapping = []): array {
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

    function createTempFile($fileData, $targetDirectory = null, $fileName = null) {
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

    function uploadAttachment($targetIssueKey, $sourceFilePath, $sourceFileName) {
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



    function uploadAttachmentsFromADF($node, $sourceIssue, $targetIssueKey) {
        global $log;
        if (!is_array($node)) return $node;

        // Recurse through children if present
        if (isset($node['content']) && is_array($node['content'])) {
            $filtered = array_map(function ($child) use ($sourceIssue, $targetIssueKey) {
                return $this->uploadAttachmentsFromADF($child, $sourceIssue, $targetIssueKey);
            }, $node['content']);
            $node['content'] = array_values(array_filter($filtered));

            // If this node is a mediaSingle and its only content is now a paragraph, unwrap it
            if ($node['type'] === 'mediaSingle' && count($node['content']) === 1 && $node['content'][0]['type'] === 'paragraph') {
                return $node['content'][0]; // Replace mediaSingle with paragraph
            }
        }

        // Replace media node with link paragraph
        if (isset($node['type']) && $node['type'] === 'media') {
            $filename = $node['attrs']['alt'] ?? null;
            $attachment = $this->sourceJira->getIssueAttachmentByFileName($sourceIssue["key"], $filename);
            $attachmentUrl = $attachment['content'] ?? null;

            if (empty($attachmentUrl) || empty($filename)) {
                $log->error("Skipping media node for issue: {$sourceIssue['key']}. Missing URL or filename.", [
                    'issueKey' => $sourceIssue['key'],
                    'filename' => $filename,
                    'attachmentUrl' => $attachmentUrl,
                    'node' => $node
                ]);
                return null; // Remove node if failed
            }
            $href = $filename;
            try {
                $uploaded = $this->uploadAttachment($targetIssueKey, $attachmentUrl, $filename);
                $href = $uploaded['content'] ?? $filename; 
            } catch (JiraApiException $e) {
                $log->error("Failed to upload attachment: {$filename} for issue: {$sourceIssue['key']}. Error: " . $e->getMessage(), $e->toContextArray());
                //Will default to just the filename if upload fails
            } catch (Exception $e) {
                $log->error("Unexpected error uploading attachment: {$filename} for issue: {$sourceIssue['key']}. Error: " . $e->getMessage(), [
                    'issueKey' => $sourceIssue['key'],
                    'filename' => $filename,
                    'attachmentUrl' => $attachmentUrl,
                    'node' => $node
                ]);
            }
            

            if ($uploaded && isset($uploaded['content'])) {
                // Return new paragraph node with a hyperlink
                return [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $filename,
                            'marks' => [
                                [
                                    'type' => 'link',
                                    'attrs' => [
                                        'href' => $href
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            } else {
                return [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $filename,
                        ]
                    ]
                ];
            }
        }

        return $node;
    }
}