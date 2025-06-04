<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;

use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Utils\LoggerFactory;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;
use Uo\AtlassianJiraMigration\JiraSyncProcess;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Setup logger
$log = LoggerFactory::create('jira_sync');

// Use environment variables
$username = $_ENV['JIRA_USERNAME'];
$token = $_ENV['JIRA_API_TOKEN'];

$sourceProjectKey = $_ENV['SOURCE_PROJECT_KEY'];
$targetProjectKey = $_ENV['TARGET_PROJECT_KEY'];

$sourceDomain = $_ENV['SOURCE_JIRA_DOMAIN'];
$targetDomain = $_ENV['TARGET_JIRA_DOMAIN'];

try {
    $sourceJira = new AtlassianAPIEndpoints($sourceDomain, $username, $token, $sourceProjectKey);
    $targetJira = new AtlassianAPIEndpoints($targetDomain, $username, $token, $targetProjectKey);
 } catch (JiraApiException $e) {
    echo "Error initializing Jira API: " . $e->getMessage() . "\n";
    var_dump ($e->toContextArray());
    exit(1);
 }


$customMap = json_decode(getenv('JIRA_TYPE_MAPPING'), true) ?? [];


// Custom fields mapping
// {$sourceDomain}/rest/api/3/issue/{issueKey}/editmeta
$customFields = [
    'Consortium Jira ID' => $_ENV['CF_CONSORTIUM_JIRA_ISSUE'], 
    'Components' => $_ENV['CF_COMPONENTS'],
    'Fix Version' => $_ENV['CF_FIX_VERSION'],
    'Reporter Name' => $_ENV['CF_REPORTER_NAME'],
];

//List of issue link types to be processed
// https://elentra.atlassian.net/rest/api/3/issueLinkType
$issueLinkTypes = [
    'Relates',
    'Cloners',
    'Blocks',
    'Duplicate',
    'Causes',
    'Depends',
    'Polaris issue link',
    'Problem/Incident',
    'QAlity Test'
];

$options = getopt("", [
    "key:",        // --key value1,value2,value3
    "start:",      // --start N
    "end:",        // --end N
    "batches:",      // --batch N
    "batch-size:", // --batch-size N
    "skip-existing", // --skip-existing
]);


$process = new JiraSyncProcess(
    $sourceJira,
    $targetJira,
    $customFields,
    $issueLinkTypes,
    $customMap,
    $log
);

if (isset($options['skip-existing'])) {
    echo "Skipping existing issues.\n";
    $process->setSkipExistingIssues(true);
} else {
    echo "Not skipping existing issues.\n";
}   



if (isset($options['batch-size'])) {
    $batchSize = (int) $options['batch-size'];
    echo "Running with batch size of $batchSize.\n";
}

if (isset($options['start'])) {
    $start = (int) $options['start'];
    echo "Running on issues from $start onwards.\n";
} elseif (isset($options['key'])) {
    $keys = explode(",", $options['key']);
    echo "Running script on specific issues: " . implode(", ", $keys) . "\n";
} elseif (isset($options['end'])) {
    $end = (int) $options['end'];
    echo "Running on issues from index $start to $end.\n";
} elseif (isset($options['batches'])) {
    $batches = (int) $options['batches'];
    echo "Running $batches batches.\n";
} else {
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
$process->syncIssues($start ?? 0, $end ?? null, $batches ?? null, $batchSize ?? 100, $keys ?? null);