<?php

namespace Lavka\ProductMediaUpload;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class RegistryReader
{
    private const HEADERS = ['sku', 'barcode', 'source_file', 'role', 'position'];

    public function read(array $upload, bool $legacy_main_confirm): array
    {
        $this->assert_upload($upload);

        $name = (string) ($upload['name'] ?? '');
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new \RuntimeException(__('Only XLS and XLSX registries are supported.', 'lavka-product-media-upload'));
        }

        $max_registry_bytes = (int) apply_filters('lavka_product_media_upload_max_registry_bytes', 5 * MB_IN_BYTES);
        if ((int) ($upload['size'] ?? 0) > $max_registry_bytes) {
            throw new \RuntimeException(__('The registry file is larger than the allowed limit.', 'lavka-product-media-upload'));
        }

        $reader = IOFactory::createReaderForFile((string) $upload['tmp_name']);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load((string) $upload['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $highest_row = (int) $sheet->getHighestDataRow();
        $highest_column = $sheet->getHighestDataColumn();
        $max_rows = (int) apply_filters('lavka_product_media_upload_max_registry_rows', 5000);

        if ($highest_row < 1) {
            throw new \RuntimeException(__('The registry is empty.', 'lavka-product-media-upload'));
        }

        // Some legacy XLS files keep formatting below the last data row.
        while ($highest_row > 1) {
            $trailing_row = $this->read_row($sheet, $highest_row, $highest_column);
            if (!$this->is_empty($trailing_row['values']) || $trailing_row['errors']) {
                break;
            }
            $highest_row--;
        }

        if ($highest_row > $max_rows) {
            throw new \RuntimeException(__('The registry contains too many rows.', 'lavka-product-media-upload'));
        }

        $first = $this->read_row($sheet, 1, $highest_column);
        $header_map = $this->header_map($first['values']);
        $header_mode = $header_map !== null;
        $start_row = $header_mode ? 2 : 1;

        if (!$header_mode && !$legacy_main_confirm) {
            throw new \RuntimeException(__('Confirm that every row in the four-column registry is a main image before checking the batch.', 'lavka-product-media-upload'));
        }

        $rows = [];
        for ($row_number = $start_row; $row_number <= $highest_row; $row_number++) {
            $raw = $this->read_row($sheet, $row_number, $highest_column);
            if ($this->is_empty($raw['values'])) {
                $rows[] = [
                    'row_number' => $row_number,
                    'sku' => '',
                    'barcode' => '',
                    'source_file' => '',
                    'role' => '',
                    'position' => '',
                    'manifest_product_name' => '',
                    'legacy' => !$header_mode,
                    'manifest_errors' => array_merge(
                        $raw['errors'],
                        [__('The registry row is empty.', 'lavka-product-media-upload')]
                    ),
                ];
                continue;
            }

            if ($header_mode) {
                $identifier_errors = $this->numeric_identifier_errors(
                    $raw['numeric_indexes'],
                    $header_map['sku'] ?? null,
                    $header_map['barcode'] ?? null
                );
                $row = [
                    'row_number' => $row_number,
                    'sku' => $this->at($raw['values'], $header_map['sku'] ?? null),
                    'barcode' => $this->at($raw['values'], $header_map['barcode'] ?? null),
                    'source_file' => $this->at($raw['values'], $header_map['source_file'] ?? null),
                    'role' => strtolower($this->at($raw['values'], $header_map['role'] ?? null)),
                    'position' => $this->at($raw['values'], $header_map['position'] ?? null),
                    'manifest_product_name' => '',
                    'legacy' => false,
                    'manifest_errors' => array_merge($raw['errors'], $identifier_errors),
                ];
            } else {
                $identifier_errors = $this->numeric_identifier_errors(
                    $raw['numeric_indexes'],
                    0,
                    3
                );
                $row = [
                    'row_number' => $row_number,
                    'sku' => $this->at($raw['values'], 0),
                    'source_file' => $this->at($raw['values'], 1),
                    'manifest_product_name' => $this->at($raw['values'], 2),
                    'barcode' => $this->at($raw['values'], 3),
                    'role' => 'main',
                    'position' => '',
                    'legacy' => true,
                    'manifest_errors' => array_merge($raw['errors'], $identifier_errors),
                ];
            }

            $rows[] = $row;
        }

        if (!$rows) {
            throw new \RuntimeException(__('The registry contains no data rows.', 'lavka-product-media-upload'));
        }

        return [
            'mode' => $header_mode ? 'header' : 'legacy',
            'rows' => $rows,
            'manifest_hash' => hash_file('sha256', (string) $upload['tmp_name']) ?: '',
            'source_name' => $this->safe_display_name($name),
        ];
    }

    public static function normalize_text($value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\xC2\xA0", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $text);
        $text = preg_replace('/[\x{2007}\x{202F}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ ]{2,}/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        return $text;
    }

    private function read_row($sheet, int $row_number, string $highest_column): array
    {
        $range = $sheet->rangeToArray(
            'A' . $row_number . ':' . $highest_column . $row_number,
            null,
            true,
            true,
            false
        );
        $cells = $range[0] ?? [];
        $values = [];
        $errors = [];
        $numeric_indexes = [];

        foreach ($cells as $column_index => $unused) {
            $cell = $sheet->getCell([$column_index + 1, $row_number]);
            if ($cell->getDataType() === DataType::TYPE_NUMERIC) {
                $numeric_indexes[] = $column_index;
            }
            [$value, $error] = $this->cell_string($cell);
            $values[] = $value;
            if ($error !== '') {
                $errors[] = sprintf(
                    /* translators: 1: spreadsheet cell, 2: reason */
                    __('Cell %1$s: %2$s', 'lavka-product-media-upload'),
                    $cell->getCoordinate(),
                    $error
                );
            }
        }

        return [
            'values' => $values,
            'errors' => $errors,
            'numeric_indexes' => $numeric_indexes,
        ];
    }

    private function cell_string(Cell $cell): array
    {
        $data_type = $cell->getDataType();
        $raw = $cell->getValue();

        if ($data_type === DataType::TYPE_FORMULA) {
            return ['', __('Formulas are not allowed in registry identifiers.', 'lavka-product-media-upload')];
        }

        $formatted = $cell->getFormattedValue();
        if (is_string($formatted) && preg_match('/^[+-]?\d+(?:[.,]\d+)?E[+-]?\d+$/i', trim($formatted))) {
            return ['', __('Scientific notation is not allowed. Store identifiers as text.', 'lavka-product-media-upload')];
        }

        if (is_int($raw)) {
            $formatted = (string) $raw;
        }

        return [self::normalize_text($formatted), ''];
    }

    private function header_map(array $first_row): ?array
    {
        $map = [];
        foreach ($first_row as $index => $value) {
            $header = strtolower(str_replace([' ', '-'], '_', self::normalize_text($value)));
            if ($header === '') {
                continue;
            }
            if (isset($map[$header])) {
                throw new \RuntimeException(__('The registry contains duplicate column headers.', 'lavka-product-media-upload'));
            }
            $map[$header] = $index;
        }

        $recognized = array_intersect(array_keys($map), self::HEADERS);
        if (!$recognized) {
            return null;
        }

        foreach (['source_file', 'role'] as $required) {
            if (!isset($map[$required])) {
                throw new \RuntimeException(
                    sprintf(
                        /* translators: %s: required column */
                        __('The registry is missing the required “%s” column.', 'lavka-product-media-upload'),
                        $required
                    )
                );
            }
        }
        if (!isset($map['sku']) && !isset($map['barcode'])) {
            throw new \RuntimeException(__('The registry must contain an SKU or barcode column.', 'lavka-product-media-upload'));
        }

        return $map;
    }

    private function assert_upload(array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                sprintf(
                    /* translators: %d: PHP upload error code */
                    __('The registry upload failed with PHP error %d.', 'lavka-product-media-upload'),
                    $error
                )
            );
        }

        $tmp_name = (string) ($upload['tmp_name'] ?? '');
        if ($tmp_name === '' || !is_file($tmp_name) || !is_readable($tmp_name) || !is_uploaded_file($tmp_name)) {
            throw new \RuntimeException(__('The registry temporary file is invalid.', 'lavka-product-media-upload'));
        }
    }

    private function at(array $values, ?int $index): string
    {
        return $index === null ? '' : self::normalize_text($values[$index] ?? '');
    }

    private function numeric_identifier_errors(array $numeric_indexes, ?int $sku_index, ?int $barcode_index): array
    {
        $errors = [];

        if ($sku_index !== null && in_array($sku_index, $numeric_indexes, true)) {
            $errors[] = __('The SKU is stored in Excel as a number and may have lost precision or leading zeroes. Format the cell as Text and re-enter the SKU.', 'lavka-product-media-upload');
        }
        if ($barcode_index !== null && in_array($barcode_index, $numeric_indexes, true)) {
            $errors[] = __('The barcode is stored in Excel as a number and may have lost precision or leading zeroes. Format the cell as Text and re-enter the barcode.', 'lavka-product-media-upload');
        }

        return $errors;
    }

    private function is_empty(array $values): bool
    {
        foreach ($values as $value) {
            if (self::normalize_text($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function safe_display_name(string $name): string
    {
        return sanitize_file_name(wp_basename(str_replace('\\', '/', $name)));
    }
}
