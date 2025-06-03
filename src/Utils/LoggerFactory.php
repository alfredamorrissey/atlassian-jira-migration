<?php
namespace Uo\AtlassianJiraMigration\Utils;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LoggerFactory {
    public static function create(string $name = 'jira_sync'): Logger {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logger = new Logger($name);
        $logger->pushHandler(new StreamHandler("$logDir/$name.log", Logger::DEBUG));
        return $logger;
    }
}
