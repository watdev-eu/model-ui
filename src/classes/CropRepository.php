<?php
// src/classes/CropRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class CropRepository
{
    public static function all(): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query("
            SELECT code, name, dry_matter_fraction
            FROM crops
            ORDER BY code
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function upsert(string $code, string $name, ?float $dryMatterFraction = null): void
    {
        $code = trim($code);
        $name = trim($name);

        if ($code === '') {
            throw new InvalidArgumentException('Code is required');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO crops (code, name, dry_matter_fraction)
            VALUES (:code, :name, :dry_matter_fraction)
            ON CONFLICT (code) DO UPDATE SET
                name = EXCLUDED.name,
                dry_matter_fraction = EXCLUDED.dry_matter_fraction
        ");
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':dry_matter_fraction' => $dryMatterFraction,
        ]);
    }

    public static function renameAndUpdate(string $oldCode, string $newCode, string $name, ?float $dryMatterFraction = null): void
    {
        $oldCode = trim($oldCode);
        $newCode = trim($newCode);
        $name    = trim($name);

        if ($newCode === '') {
            throw new InvalidArgumentException('New code is required');
        }

        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
            UPDATE crops
            SET code = :new_code,
                name = :name,
                dry_matter_fraction = :dry_matter_fraction
            WHERE code = :old_code
        ");
        $stmt->execute([
            ':old_code' => $oldCode,
            ':new_code' => $newCode,
            ':name' => $name,
            ':dry_matter_fraction' => $dryMatterFraction,
        ]);

        if ($stmt->rowCount() === 0) {
            self::upsert($newCode, $name, $dryMatterFraction);
        }
    }

    public static function delete(string $code): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("DELETE FROM crops WHERE code = :code");
        $stmt->execute([':code' => trim($code)]);
    }
}