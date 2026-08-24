<?php

namespace Paint\CheckboxFiscalization\Infrastructure;

defined('ABSPATH') || exit;

final class OperationRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pc_checkbox_operations';
    }

    /** @param array<string,mixed> $command @return array{created:bool,operation:array<string,mixed>} */
    public function reserve(array $command, string $hash, string $mode): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (operation_key, receipt_uuid, source_system, source_type, source_id,
                 operation_type, request_hash, status, mode, total_cents, attempts,
                 created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %d, 0, %s, %s)",
            $command['operation_key'],
            $command['receipt_id'],
            $command['source']['system'],
            $command['source']['entity_type'],
            $command['source']['entity_id'],
            $command['operation_type'],
            $hash,
            'RECEIVED',
            $mode,
            $command['expected_total_cents'],
            $now,
            $now
        ));

        $operation = $this->find($command['operation_key']) ?? $this->findByReceipt($command['receipt_id']);
        if (!$operation) {
            throw new \RuntimeException('The fiscalization operation could not be reserved.');
        }
        return ['created' => $inserted === 1, 'operation' => $operation];
    }

    /** @return array<string,mixed>|null */
    public function find(string $operation_key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE operation_key = %s LIMIT 1",
            $operation_key
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByReceipt(string $receipt_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE receipt_uuid = %s LIMIT 1",
            $receipt_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $fields */
    public function update(string $operation_key, array $fields): void
    {
        global $wpdb;
        $allowed = [
            'status', 'mode', 'checkbox_receipt_id', 'fiscal_code', 'shift_id',
            'transaction_id', 'attempts', 'http_code', 'error_code', 'error_message',
        ];
        $data = array_intersect_key($fields, array_flip($allowed));
        $data['updated_at'] = current_time('mysql', true);
        $wpdb->update($this->table, $data, ['operation_key' => $operation_key]);
    }

    public function incrementAttempt(string $operation_key, string $mode): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET attempts = attempts + 1, mode = %s, updated_at = %s
              WHERE operation_key = %s",
            $mode,
            current_time('mysql', true),
            $operation_key
        ));
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 25): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results(
            "SELECT operation_key, receipt_uuid, source_system, source_type, source_id,
                    operation_type, status, mode, total_cents, attempts, http_code,
                    error_code, created_at, updated_at
               FROM {$this->table}
              ORDER BY id DESC LIMIT {$limit}",
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }
}
