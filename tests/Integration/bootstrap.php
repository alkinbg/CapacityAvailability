<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Alkin\CapacityAvailability\AvailabilityCalculator;
use Alkin\CapacityAvailability\Doctrine\CapacitySnapshot;
use Alkin\CapacityAvailability\Doctrine\DoctrineBookingWriter;

function connection(): Connection
{
    if (getenv('CAPACITY_DB_ISOLATED') !== 'yes') {
        throw new RuntimeException('Refusing DB tests without CAPACITY_DB_ISOLATED=yes; use only an isolated throwaway MariaDB.');
    }
    $port = (int)(getenv('CAPACITY_DB_PORT') ?: 0);
    $password = getenv('CAPACITY_DB_PASSWORD');
    if ($port < 1 || $password === false || $password === '') {
        throw new RuntimeException('Missing CAPACITY_DB_PORT / CAPACITY_DB_PASSWORD test environment.');
    }
    return DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => '127.0.0.1',
        'port' => $port,
        'dbname' => 'capacity_lib_test',
        'user' => 'capacity_app',
        'password' => $password,
        'charset' => 'utf8mb4',
        'driverOptions' => [PDO::ATTR_TIMEOUT => 15],
    ]);
}

function bookingWriter(Connection $db): DoctrineBookingWriter
{
    return new DoctrineBookingWriter(
        $db,
        new CapacitySnapshot($db),
        new AvailabilityCalculator(),
    );
}

function instant(int $minute): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-10-22T09:00:00+00:00'))
        ->modify('+'.($minute * 60).' seconds');
}
