<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * SQLite partagé entre le serveur web et le worker : journal WAL (lectures pendant les écritures),
 * attente au lieu d'une erreur « database is locked », clés étrangères actives.
 */
#[AsMiddleware]
final class SqlitePragmas implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (str_contains(strtolower($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 15000');
                    $connection->exec('PRAGMA foreign_keys = ON');
                    $connection->exec('PRAGMA synchronous = NORMAL');
                }

                return $connection;
            }
        };
    }
}
