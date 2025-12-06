<?php
// src/classes/SwatRunRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SwatRunRepository
{
    /**
     * Fetch all runs ordered by creation time (newest first).
     */
    public static function all(): array
    {
        $pdo = Database::pdo();
        $sql = "
            SELECT
                id,
                study_area,
                run_label,
                run_date,
                visibility,
                description,
                period_start,
                period_end,
                time_step,
                created_at
            FROM swat_runs
            ORDER BY created_at DESC
        ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new run row.
     *
     * Note: for full imports you currently create runs via api/import_run.php.
     * This helper is kept for future use / simple inserts.
     */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO swat_runs (
                study_area,
                run_label,
                run_date,
                visibility,
                description,
                created_by,
                period_start,
                period_end,
                time_step
            ) VALUES (
                :study_area,
                :run_label,
                :run_date,
                :visibility,
                :description,
                :created_by,
                :period_start,
                :period_end,
                :time_step
            )
            RETURNING id
        ");

        $stmt->execute([
            ':study_area'   => $data['study_area'],
            ':run_label'    => $data['run_label'],
            ':run_date'     => $data['run_date']     ?? null,       // 'Y-m-d' or null
            ':visibility'   => $data['visibility']   ?? 'private',  // 'private' | 'public'
            ':description'  => $data['description']  ?? null,
            ':created_by'   => $data['created_by']   ?? null,       // user id if/when you add auth
            ':period_start' => $data['period_start'] ?? null,
            ':period_end'   => $data['period_end']   ?? null,
            ':time_step'    => $data['time_step']    ?? 'MONTHLY',
        ]);

        // RETURNING id gives us a single row with the generated id
        return (int)$stmt->fetchColumn();
    }

    // src/classes/SwatRunRepository.php

    public static function forStudyArea(string $studyArea): array
    {
        $pdo = Database::pdo();
        $sql = "
        SELECT
            id,
            study_area,
            run_label,
            run_date,
            visibility,
            description,
            period_start,
            period_end,
            time_step,
            created_at,
            is_default
        FROM swat_runs
        WHERE study_area = :study_area
        ORDER BY created_at DESC
    ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':study_area' => $studyArea]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}