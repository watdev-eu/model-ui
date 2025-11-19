<?php
require_once __DIR__ . '/app.php';

final class Database {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            env('DB_HOST','db'),
            env('DB_PORT','3306'),
            env('DB_NAME','watdev'),
            env('DB_CHARSET','utf8mb4')
        );

        // Support Docker secrets via *_FILE
        $user = getenv('DB_USER_FILE') && is_readable(getenv('DB_USER_FILE'))
            ? trim(file_get_contents(getenv('DB_USER_FILE')))
            : env('DB_USER','watdev_user');

        $pass = getenv('DB_PASS_FILE') && is_readable(getenv('DB_PASS_FILE'))
            ? trim(file_get_contents(getenv('DB_PASS_FILE')))
            : env('DB_PASS','');

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_LOCAL_INFILE => (bool)env('DB_LOCAL_INFILE',1),
        ]);
        return self::$pdo;
    }
}