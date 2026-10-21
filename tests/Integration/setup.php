<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$db=connection();
$statements=array_filter(array_map('trim', explode(';', file_get_contents(dirname(__DIR__, 2).'/docs/mariadb-schema.sql'))));
foreach($statements as $statement) {
    $db->executeStatement($statement);
}
echo 'MariaDB test schema installed via Doctrine DBAL; server='.($db->fetchOne('SELECT VERSION()'))."\n";
