<?php
namespace PaintCore\PCOE;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

defined('ABSPATH') || exit;

final class ContactWorkbook {
    public const ORGANIZATION = 'Организация / филиал';
    public const SHEET = 'Контакты';

    /** Read values without evaluating workbook formulas. Preserve the source headers and all columns. */
    public static function read(string $path, array &$blockedEmails = []): array {
        $invalid = __('Check the Contacts worksheet, column headers and unique organization IDs.', 'pc-order-import-export');
        $zip = new \ZipArchive();
        if (!is_file($path) || filesize($path) > 5242880 || $zip->open($path) !== true) {
            throw new \RuntimeException($invalid);
        }
        try {
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $expanded += $zip->statIndex($i)['size'];
                if ($expanded > 32 * 1024 * 1024) throw new \RuntimeException($invalid);
            }
        } finally { $zip->close(); }
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly([self::SHEET,'Email']);
        $reader->setReadDataOnly(true);
        foreach ($reader->listWorksheetInfo($path) as $info) {
            if (in_array($info['worksheetName'],[self::SHEET,'Email'],true) && (($info['worksheetName'] === 'Email' ? 10020 : 2020) < $info['totalRows'] || $info['totalColumns'] > 64)) {
                throw new \RuntimeException($invalid);
            }
        }
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName(self::SHEET);
            if (!$sheet) throw new \RuntimeException($invalid);
            $headers = []; $rows = []; $ids = [];
            foreach ($sheet->getRowIterator() as $line) {
                $values = [];
                $cells = $line->getCellIterator();
                $cells->setIterateOnlyExistingCells(true);
                foreach ($cells as $cell) {
                    $value = trim((string)$cell->getValue());
                    if ($value === '') continue;
                    if ($cell->isFormula() || strlen($value) > 32000) throw new \RuntimeException($invalid);
                    $values[$cell->getColumn()] = $value;
                }
                if (!$headers) {
                    if (!in_array('ID', $values, true)) continue;
                    if (array_diff([self::ORGANIZATION, 'Email', 'Телефон'], $values) || count(array_unique($values)) !== count($values)) throw new \RuntimeException($invalid);
                    $headers = $values;
                    continue;
                }
                if (!$values) continue;
                if (array_diff_key($values, $headers)) throw new \RuntimeException($invalid);
                $row = [];
                foreach ($headers as $column => $label) $row[$label] = $values[$column] ?? '';
                if (!$row['ID'] || strlen($row['ID']) > 150 || !$row[self::ORGANIZATION] || isset($ids[$row['ID']]) || count($rows) >= 2000) throw new \RuntimeException($invalid);
                $ids[$row['ID']] = true;
                $rows[] = $row;
            }
            if (!$rows) throw new \RuntimeException($invalid);
            $blockedEmails = [];
            $addresses = $book->getSheetByName('Email');
            if ($addresses) {
                $emailColumn = ''; $statusColumn = '';
                foreach ($addresses->getRowIterator() as $line) {
                    if (!$emailColumn) {
                        $columns = [];
                        $cells = $line->getCellIterator();
                        $cells->setIterateOnlyExistingCells(true);
                        foreach ($cells as $cell) $columns[trim((string)$cell->getValue())] = $cell->getColumn();
                        if (isset($columns['Email'],$columns['Статус обращения'])) {
                            $emailColumn = $columns['Email']; $statusColumn = $columns['Статус обращения'];
                        }
                        continue;
                    }
                    $status = $addresses->getCell($statusColumn.$line->getRowIndex());
                    if ($status->isFormula()) throw new \RuntimeException($invalid);
                    if (ContactInvitation::blocked(['Статус обращения'=>(string)$status->getValue()])) {
                        $email = strtolower(trim((string)$addresses->getCell($emailColumn.$line->getRowIndex())->getValue()));
                        if (!is_email($email)) throw new \RuntimeException($invalid);
                        $blockedEmails[$email] = true;
                    }
                }
                if (!$emailColumn) throw new \RuntimeException($invalid);
            }
            return $rows;
        } finally { $book->disconnectWorksheets(); }
    }

    public static function emails(array $row): array {
        $emails = preg_split('/[;,\s]+/u', strtolower($row['Email']), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($emails, static fn($email) => strlen($email) <= 100 && is_email($email))));
    }
}
