<?php
/**
 * Helper for tests/log-deadlock.spec.ts, inside the Magento container:
 *   php var/log-deadlock.php --do=log --reference=<ref> --failures=<n>   fails the first n inserts of that
 *                                                                        reference with MariaDB's deadlock error, logs one line
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Qliro\QliroOne\Model\Logger\Manager;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['do:', 'reference::', 'failures::']);
$command = (string)($options['do'] ?? '');

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$log = $resource->getTableName('qliroone_log');
// MyISAM keeps the counters when the failed insert is rolled back
$failures = $resource->getTableName('qliroe2e_log_deadlock');

$db->query("DROP TRIGGER IF EXISTS qliroe2e_log_deadlock");
$db->query("DROP TABLE IF EXISTS {$failures}");

if ($command !== 'log') {
    fwrite(STDERR, "unknown --do\n");
    exit(1);
}

$reference = (string)($options['reference'] ?? '');
if ($reference === '') {
    fwrite(STDERR, "--reference is required\n");
    exit(1);
}

$db->query("CREATE TABLE {$failures} (
    reference VARCHAR(191) NOT NULL PRIMARY KEY,
    failures INT NOT NULL,
    attempts INT NOT NULL DEFAULT 0
) ENGINE=MyISAM");
$engine = $db->fetchOne(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    [$failures]
);
if ($engine !== 'MyISAM') {
    $db->query("DROP TABLE IF EXISTS {$failures}");
    fwrite(STDERR, "the server created the counter as {$engine}, it needs MyISAM\n");
    exit(1);
}
// the adapter refuses a body of several statements
$db->getConnection()->exec("CREATE TRIGGER qliroe2e_log_deadlock BEFORE INSERT ON {$log} FOR EACH ROW
BEGIN
    DECLARE left_failures INT DEFAULT NULL;
    SET left_failures = (SELECT failures FROM {$failures} WHERE BINARY reference = BINARY NEW.reference);
    IF left_failures IS NOT NULL THEN
        UPDATE {$failures} SET attempts = attempts + 1, failures = GREATEST(failures - 1, 0)
            WHERE BINARY reference = BINARY NEW.reference;
        IF left_failures > 0 THEN
            SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213,
                MESSAGE_TEXT = 'Deadlock found when trying to get lock; try restarting transaction';
        END IF;
    END IF;
END");
try {
    $db->insert($failures, ['reference' => $reference, 'failures' => (int)($options['failures'] ?? 1)]);

    $logger = $om->get(Manager::class);
    $logger->setMark('e2e deadlock');
    $logger->info('Capture sent for {reference}', [
        'reference' => $reference,
        'tags' => 'capture',
        'extra' => ['amount' => 100],
    ]);

    $attempts = (int)$db->fetchOne("SELECT attempts FROM {$failures} WHERE reference = ?", [$reference]);
    $rows = $db->fetchAll(
        "SELECT level, message, reference, tags, process_id, extra FROM {$log} WHERE reference = ?",
        [$reference]
    );
} finally {
    $db->query("DROP TRIGGER IF EXISTS qliroe2e_log_deadlock");
    $db->query("DROP TABLE IF EXISTS {$failures}");
}

echo json_encode(['attempts' => $attempts, 'rows' => $rows, 'pid' => getmypid()]) . PHP_EOL;
