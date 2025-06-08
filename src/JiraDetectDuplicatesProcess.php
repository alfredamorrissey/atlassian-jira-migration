<?php


namespace Uo\AtlassianJiraMigration;

use Monolog\Logger;
use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Utils\LoggerFactory;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;


class JiraDetectDuplicatesProcess
{
    private AtlassianAPIEndpoints $jira;
    private  ?Logger $log = null;

    private int $errorCount = 0;
    private int $issueCount = 0;
    private int $duplicateCount = 0;
    private array $customFields;


    public function __construct(AtlassianAPIEndpoints $jira, array $customFields, ?Logger $logger = null) {
        $this->jira = $jira;
        $this->customFields = $customFields;
        $this->log = $logger ?? LoggerFactory::create();
    }

    public function processIssues(int $startAt = 0, ?int $end = null, ?int $batches = null, int $batchSize = 100): void {
        $startTime = microtime(true);
        $batch = 1;
        $maxResults = $batchSize;
        $totalIssuesInRun = null; // Total issues to process in this run
        $fields = 'key,customfield_' . $this->customFields['Consortium Jira ID'];
        $originalStart = $startAt;

        // Fetch issues from the source Jira project
        $jql = "project = \"{$this->jira->getProjectKey()}\"";

        do {
            // Add additional parameters if needed
            $params = [
                'startAt' => $startAt,
                'maxResults' => $maxResults,
                'fields' => $fields
            ];
            try {
                // Fetch issues using JQL
                $data = $this->jira->getIssuesByJQL($jql, $params);
            } catch (Exception $e) {
                echo "Error fetching issues: " . $e->getMessage() . "\n";
                $this->log->error("Error fetching issues: {$e->getMessage()}", ['jql' => $jql, 'params' => $params]);
                return;
            }

            if ($data && !empty($data['issues'])) {
                $totalIssuesInProject = $data['total'];

                if (!is_null($end)) {
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
                echo "Starting batch $batch out of $batches with batch size of $batchSize\n";
                echo "Total issues to process: $totalIssuesInRun " . (empty($sourceIssueKeys) ? "Number of batches: $batches" : "") . "\n";
                echo "Duplicates: [$this->duplicateCount]\n";

                foreach ($data['issues'] as $issue) {
                    $this->issueCount++;
                    if (!is_null($end) && $this->issueCount > $end) {
                        break;
                    }

                    try {
                        echo "\nRun Status: " . $this->issueCount . " of {$totalIssuesInRun} ";
                        echo "- Project Status: " . $originalStart + $this->issueCount . " of  $totalIssuesInProject\n";

                        try {
                            $customFieldKeyJQL = "cf[{$this->customFields['Consortium Jira ID']}]";
                            $customFieldKeyFields = "customfield_" . $this->customFields['Consortium Jira ID'];
                            // Fetch other issues that are based on the same Consortium issue
                            // project = "ETCD" AND key != "ETCD-10863"  AND  cf[12385] ~ "ME-2679"
                            $jqlForDuplicates = "project = \"{$this->jira->getProjectKey()}\" AND $customFieldKeyJQL ~ \"{$issue['fields'][$customFieldKeyFields]}\" AND key != \"{$issue['key']}\"";
                            $results = $this->jira->getIssuesByJQL($jqlForDuplicates, $params);
                            $duplicates = $results['issues'] ?? [];
                            // If there are duplicates log them and this issue number for deletion
                            if (count($duplicates)) {
                                $this->duplicateCount++;
                                $this->log->info(count($duplicates) . " duplicates of " . $issue['key'], $duplicates);
                            }

                        } catch (Exception $e) {
                            echo "Error fetching issues: " . $e->getMessage() . "\n";
                            $this->log->error("Error fetching issues: {$e->getMessage()}", ['jql' => $jqlForDuplicates, 'params' => $params]);
                            return;
                        }
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

            echo ("\n");
            echo "Batch $batch completed. Processed $this->issueCount issues with $this->errorCount errors.\n";
            echo "Duplicates: [$this->duplicateCount]\n";
            echo "Duration: " . $this->formatDuration(microtime(true) - $startTime) . "\n";
            echo "\n\n##########################################\n\n";
            $startAt += $batchSize;
            $batch++;

        } while ($startAt < $data['total'] && $batch <= $batches && (is_null($end) || $startAt < $end));
        // If we reach here, we have processed all issues
        echo "Total issues processed: $this->issueCount with $this->errorCount errors out of Total Project Issues: {$data['total']}\n";
        echo "Duplicates: [$this->duplicateCount]\n";
        echo "Time taken: " . (microtime(true) - $startTime) . " seconds OR " . (microtime(true) - $startTime)/60 . " minutes\n";

    }

    function formatDuration(int|float $seconds): string {
        $seconds = (int) $seconds;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $seconds = $seconds % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($seconds > 0 || empty($parts)) {
            $parts[] = "{$seconds}s";
        }
        return implode(' ', $parts);
    }

}
