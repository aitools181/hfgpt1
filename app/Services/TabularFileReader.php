<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class TabularFileReader
{
    public function rows(string $path, string $extension): iterable
    {
        $extension = strtolower($extension);
        return match ($extension) {
            'csv', 'txt' => $this->delimitedRows($path, ','),
            'tsv' => $this->delimitedRows($path, "\t"),
            'xlsx' => $this->xlsxRows($path),
            default => throw new RuntimeException('Unsupported import format. Use CSV, TSV or XLSX.'),
        };
    }

    private function delimitedRows(string $path, string $delimiter): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Unable to read uploaded file.');
        try {
            $headers = fgetcsv($handle, 0, $delimiter, '"', '') ?: [];
            $headers = array_map([$this, 'normalizeHeader'], $headers);
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) continue;
                $values = array_pad($values, count($headers), null);
                yield array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Unable to open XLSX file.');

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $dom = new \DOMDocument();
            $dom->loadXML($sharedXml);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[local-name()="si"]') as $si) {
                $parts = [];
                foreach ($xpath->query('.//*[local-name()="t"]', $si) as $text) $parts[] = $text->textContent;
                $shared[] = implode('', $parts);
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) throw new RuntimeException('XLSX first worksheet was not found.');

        $dom = new \DOMDocument();
        $dom->loadXML($sheetXml);
        $xpath = new \DOMXPath($dom);
        $rawRows = [];
        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $record = [];
            foreach ($xpath->query('./*[local-name()="c"]', $row) as $cell) {
                $ref = $cell->attributes?->getNamedItem('r')?->nodeValue ?? 'A1';
                preg_match('/[A-Z]+/', $ref, $match);
                $col = $this->columnIndex($match[0] ?? 'A');
                $type = $cell->attributes?->getNamedItem('t')?->nodeValue ?? '';
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') $value = $shared[(int) $value] ?? '';
                if ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $text) $parts[] = $text->textContent;
                    $value = implode('', $parts);
                }
                $record[$col] = $value;
            }
            if ($record !== []) {
                ksort($record);
                $max = max(array_keys($record));
                $rawRows[] = array_map(fn ($i) => $record[$i] ?? '', range(0, $max));
            }
        }
        if ($rawRows === []) return [];
        $headers = array_map([$this, 'normalizeHeader'], array_shift($rawRows));
        return array_values(array_filter(array_map(function (array $values) use ($headers) {
            $values = array_pad($values, count($headers), null);
            return array_combine($headers, array_slice($values, 0, count($headers)));
        }, $rawRows), fn (array $row) => count(array_filter($row, fn ($v) => trim((string) $v) !== '')) > 0));
    }

    private function normalizeHeader(mixed $header): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $header)), '_');
    }

    private function columnIndex(string $letters): int
    {
        $result = 0;
        foreach (str_split($letters) as $letter) $result = $result * 26 + (ord($letter) - 64);
        return $result - 1;
    }
}
