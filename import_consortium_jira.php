<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Uo\AtlassianJiraMigration\Utils\AtlassianAPIEndpoints;
use Uo\AtlassianJiraMigration\Exception\JiraApiException;
use Uo\AtlassianJiraMigration\JiraSyncProcess;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Setup logger
$log = new Logger('jira_sync');
$log->pushHandler(new StreamHandler(__DIR__ . '/logs/app.log', Logger::DEBUG));

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

$process = new JiraSyncProcess(
    $sourceJira,
    $targetJira,
    $customFields,
    $issueLinkTypes,
    $customMap,
    $log
);
// Start the migration process
$process->syncIssues(0, 100);