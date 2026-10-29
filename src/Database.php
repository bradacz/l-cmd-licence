<?php

declare(strict_types=1);

namespace App;

use App\Support\Env;
use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $db = Env::get('DB_DATABASE') ?? Env::get('DB_NAME', 'license_server');
        $user = Env::get('DB_USERNAME') ?? Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASSWORD') ?? Env::get('DB_PASS', '');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $socket = Env::get('DB_SOCKET', '');
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket};dbname={$db};charset={$charset}"
            : "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }

    /** @template T
     * @param callable(): T $work
     * @return T */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
