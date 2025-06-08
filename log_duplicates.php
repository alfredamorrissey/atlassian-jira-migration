<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;

use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Utils\LoggerFactory;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;
use Uo\AtlassianJiraMigration\JiraDetectDuplicatesProcess;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Setup logger
$log = LoggerFactory::create('find_duplicates');

// Use environment variables
$username = $_ENV['JIRA_USERNAME'];
$token = $_ENV['JIRA_API_TOKEN'];
$targetDomain = $_ENV['TARGET_JIRA_DOMAIN'];
$targetProjectKey = $_ENV['TARGET_PROJECT_KEY'];


// Custom fields mapping
// {$sourceDomain}/rest/api/3/issue/{issueKey}/editmeta
$customFields = [
    'Consortium Jira ID' => $_ENV['CF_CONSORTIUM_JIRA_ISSUE'], 
    'Components' => $_ENV['CF_COMPONENTS'],
    'Fix Version' => $_ENV['CF_FIX_VERSION'],
    'Reporter Name' => $_ENV['CF_REPORTER_NAME'],
];

$options = getopt("", [
    "start:",      // --start N
    "end:",        // --end N
    "batches:",      // --batch N
    "batch-size:", // --batch-size N
]);

try {
    $targetJira = new AtlassianAPIEndpoints($targetDomain, $username, $token, $targetProjectKey);
    $process = new JiraDetectDuplicatesProcess($targetJira, $customFields, $log);
} catch (JiraApiException $e) {
    echo "Error initializing Jira API: " . $e->getMessage() . "\n";
    var_dump ($e->toContextArray());
    exit(1);
}

if (isset($options['batch-size'])) {
    $batchSize = (int) $options['batch-size'];
    echo "Running with batch size of $batchSize.\n";
}

if (isset($options['key'])) {
    $keys = explode(",", $options['key']);
    echo "Running script on specific issues: " . implode(", ", $keys) . "\n";
} elseif (isset($options['end'])) {
    $start = (int) $options['start'] ?? 0;
    $end = (int) $options['end'];
    echo "Running on issues from index $start to $end.\n";
} elseif (isset($options['batches'])) {
    $start = (int) $options['start'] ?? 0;
    $batches = (int) $options['batches'];
    echo "Running $batches batches starting at $start.\n";
} elseif (isset($options['start'])) {
    $start = (int) $options['start'];
    echo "Running on issues from $start onwards.\n";
}
else {
    echo "No valid options provided.\n";
    echo "Usage:\n";
    echo "  --key=ISSUE1,ISSUE2\n";
    echo "  --start=START --end=END\n";
    echo "  --batch=NUM\n";
    echo "  --batch-size=SIZE\n";
    echo "  --skip-existing\n";
    exit(1);
}

// Start the migration process
$process->processIssues($start ?? 0, $end ?? null, $batches ?? null, $batchSize ?? 100, $keys ?? null);
