<?php
declare(strict_types=1);
namespace ChupaCabra;

use PDO;
use Predis\Client;

final class Bootstrap
{
    public static function init(): void
    {
        date_default_timezone_set(getenv('TZ') ?: 'America/Sao_Paulo');
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
    }

    public static function db(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'db', (int)(getenv('DB_PORT') ?: 3306), getenv('DB_NAME') ?: 'chupacabra'),
            getenv('DB_USER') ?: 'chupacabra', getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]
        );
    }

    public static function redis(): Client
    {
        return new Client(['scheme' => 'tcp', 'host' => getenv('REDIS_HOST') ?: 'redis', 'port' => (int)(getenv('REDIS_PORT') ?: 6379), 'password' => getenv('REDIS_PASSWORD') ?: null,
            'read_write_timeout' => 10]);
    }
}
