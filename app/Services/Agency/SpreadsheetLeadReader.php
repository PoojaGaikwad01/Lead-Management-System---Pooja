<?php

namespace App\Services\Agency;

use App\Support\Phone;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads an agency Excel/CSV file: finds the header row (first 10 rows) by flexible matching, one candidate per data row.
 */
class SpreadsheetLeadReader
{
    // Normalized header (lowercase letters only) => field. Checked in order; first match per field wins.
    private const HEADERS = [
        'phone' => ['phone', 'mobile', 'whatsapp', 'contact', 'cell', 'mob', 'number', 'no'],
        'name' => ['customername', 'clientname', 'parentname', 'fullname', 'name'],
        'city' => ['city', 'location', 'area', 'locality', 'place'],
    ];

    // Headers containing these are never the phone/name column ("No. of kids", "Child name", "Agency name").
    private const EXCLUDE = ['kid', 'child', 'guest', 'pax', 'people', 'count', 'age', 'agency', 'company', 'sr', 'serial', 'date'];

    public static function supports(?string $filename, ?string $mime): bool
    {
        return in_array(strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'csv', 'ods'], true)
            || preg_match('/spreadsheet|excel|csv/i', (string) $mime);
    }

    /**
     * @return array{rows: list<array{row: int, name: ?string, phone: ?string, city: ?string, ambiguous: bool, cells: array<string, string>}>, header_found: bool}
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $grid = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        $grid = array_map(fn ($row) => array_map(fn ($v) => $this->cell($v), $row), $grid);

        foreach (array_slice($grid, 0, 10, true) as $headerIndex => $header) {
            $map = $this->mapHeader($header);
            if (isset($map['phone'])) {
                return ['rows' => $this->rows($grid, $headerIndex, $header, $map), 'header_found' => true];
            }
        }

        return ['rows' => [], 'header_found' => false];
    }

    private function rows(array $grid, int $headerIndex, array $header, array $map): array
    {
        $rows = [];
        foreach (array_slice($grid, $headerIndex + 1, null, true) as $i => $row) {
            if (! array_filter($row, fn ($v) => $v !== '')) {
                continue; // blank row
            }
            $phoneCell = $row[$map['phone']] ?? '';
            $phones = array_keys(TextLeadExtractor::phones($phoneCell));
            $phone = Phone::normalize($phoneCell) ?? ($phones[0] ?? null);

            $rows[] = [
                'row' => $i + 1, // 1-based like Excel
                'name' => isset($map['name']) ? (($row[$map['name']] ?? '') ?: null) : null,
                'phone' => $phone,
                'city' => isset($map['city']) ? (($row[$map['city']] ?? '') ?: null) : null,
                'ambiguous' => ! $phone || count($phones) > 1,
                'cells' => array_filter(array_combine(
                    array_map(fn ($h, $k) => $h !== '' ? $h : 'Column '.($k + 1), $header, array_keys($header)),
                    array_pad($row, count($header), '')
                ), fn ($v) => $v !== ''),
            ];
        }

        return $rows;
    }

    /** @return array<string, int> field => column index */
    private function mapHeader(array $header): array
    {
        $normalized = array_map(fn ($h) => preg_replace('/[^a-z]/', '', strtolower($h)), $header);
        $map = [];

        foreach (self::HEADERS as $field => $keywords) {
            foreach ($keywords as $keyword) {
                foreach ($normalized as $col => $h) {
                    $excluded = collect(self::EXCLUDE)->contains(fn ($x) => str_contains($h, $x)) && $field !== 'city';
                    if ($h !== '' && ! $excluded && ! in_array($col, $map, true)
                        && ($h === $keyword || (strlen($keyword) > 2 && str_contains($h, $keyword)))) {
                        $map[$field] = $col;
                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    /** Excel stores phone numbers as floats: 9812345604.0 → "9812345604". */
    private function cell(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_float($v) && floor($v) === $v => sprintf('%.0f', $v),
            default => trim((string) $v),
        };
    }
}
