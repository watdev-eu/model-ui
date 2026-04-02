<?php
// classes/RinLicenseRepository.php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class RunLicenseRepository
{
    public static function all(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query("
            SELECT id, name
            FROM run_licenses
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findOrCreateByName(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('License name is required');
        }

        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO run_licenses (name)
            VALUES (:name)
            ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
            RETURNING id
        ");
        $stmt->execute([':name' => $name]);

        return (int)$stmt->fetchColumn();
    }
}