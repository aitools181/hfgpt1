<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

class TabularFileReader
{
    private const DEFAULT_MAX_SHEET_BYTES = 104_857_600; // 100 MiB uncompressed
    private const DEFAULT_MAX_SHARED_STRINGS_BYTES = 25_165_824; // 24 MiB uncompressed
    private const DEFAULT_MAX_SHARED_STRINGS = 250_000;
    private const DEFAULT_MAX_ROW_BYTES = 2_097_152; // 2 MiB XML row safety bound
    private const DEFAULT_MAX_DELIMITED_LINE_BYTES = 1_048_576; // 1 MiB line safety bound
    private const DEFAULT_MAX_ZIP_RATIO = 200;
    private const DEFAULT_MAX_COLUMNS = 256;
    private const DEFAULT_MAX_CELL_BYTES = 65_536;

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

    private function delimitedRows(string $path, string $delimiter): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        try {
            $headers = fgetcsv($handle, self::DEFAULT_MAX_DELIMITED_LINE_BYTES, $delimiter, '"', '') ?: [];
            if ($headers === []) {
                return;
            }
            if (count($headers) > self::DEFAULT_MAX_COLUMNS) {
                throw new RuntimeException('Import file has too many columns.');
            }
            $headers = array_map([$this, 'normalizeHeader'], $headers);
            $this->assertHeaders($headers);

            while (($values = fgetcsv($handle, self::DEFAULT_MAX_DELIMITED_LINE_BYTES, $delimiter, '"', '')) !== false) {
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($values) > self::DEFAULT_MAX_COLUMNS) {
                    throw new RuntimeException('Import row has too many columns.');
                }
                $values = array_map([$this, 'boundedCell'], $values);
                $values = array_pad($values, count($headers), null);
                yield array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stream XLSX rows one at a time. The previous implementation loaded the
     * complete worksheet XML and every parsed row into PHP memory, which could
     * exhaust a production host on a compressed XLSX with a large worksheet.
     */
    private function xlsxRows(string $path): Generator
    {
        if (! class_exists(XMLReader::class)) {
            throw new RuntimeException('XLSX import requires the PHP XMLReader extension.');
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('Unable to read uploaded XLSX file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($realPath) !== true) {
            throw new RuntimeException('Unable to open XLSX file.');
        }

        try {
            $this->assertZipEntrySafe($zip, 'xl/worksheets/sheet1.xml', self::DEFAULT_MAX_SHEET_BYTES, true);
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $this->assertZipEntrySafe($zip, 'xl/sharedStrings.xml', self::DEFAULT_MAX_SHARED_STRINGS_BYTES, false);
            }
        } finally {
            $zip->close();
        }

        $shared = $this->readSharedStrings($realPath);
        $reader = new XMLReader();
        if (! $reader->open($this->zipUri($realPath, 'xl/worksheets/sheet1.xml'), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('XLSX first worksheet could not be streamed.');
        }

        $headers = null;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();
                if ($rowXml === '') {
                    continue;
                }
                if (strlen($rowXml) > self::DEFAULT_MAX_ROW_BYTES) {
                    throw new RuntimeException('XLSX row is too large to import safely.');
                }
                $values = $this->parseXlsxRow($rowXml, $shared);
                if ($values === [] || count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                if ($headers === null) {
                    if (count($values) > self::DEFAULT_MAX_COLUMNS) {
                        throw new RuntimeException('Import file has too many columns.');
                    }
                    $headers = array_map([$this, 'normalizeHeader'], $values);
                    $this->assertHeaders($headers);
                    continue;
                }

                if (count($values) > self::DEFAULT_MAX_COLUMNS) {
                    throw new RuntimeException('Import row has too many columns.');
                }
                $values = array_pad($values, count($headers), null);
                yield array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            $reader->close();
            unset($shared);
        }
    }

    private function readSharedStrings(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX file.');
        }
        $hasSharedStrings = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();
        if (! $hasSharedStrings) {
            return [];
        }

        $reader = new XMLReader();
        if (! $reader->open($this->zipUri($path, 'xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Unable to stream XLSX shared strings.');
        }

        $shared = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }
                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    $shared[] = '';
                    continue;
                }
                if (count($shared) >= self::DEFAULT_MAX_SHARED_STRINGS) {
                    throw new RuntimeException('XLSX contains too many shared strings to import safely.');
                }
                $shared[] = $this->extractTextNodes($xml);
            }
        } finally {
            $reader->close();
        }

        return $shared;
    }

    private function parseXlsxRow(string $rowXml, array $shared): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($rowXml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Malformed XLSX worksheet row.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);
        $record = [];
        foreach ($xpath->query('/*[local-name()="row"]/*[local-name()="c"]') as $cell) {
            $ref = $cell->attributes?->getNamedItem('r')?->nodeValue ?? 'A1';
            preg_match('/[A-Z]+/i', $ref, $match);
            $column = $this->columnIndex(strtoupper($match[0] ?? 'A'));
            if ($column >= self::DEFAULT_MAX_COLUMNS) {
                throw new RuntimeException('Import row exceeds the supported column limit.');
            }

            $type = $cell->attributes?->getNamedItem('t')?->nodeValue ?? '';
            if ($type === 'inlineStr') {
                $parts = [];
                foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $text) {
                    $parts[] = $text->textContent;
                }
                $value = implode('', $parts);
            } else {
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') {
                    $index = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    $value = $index === false ? '' : ($shared[$index] ?? '');
                }
            }

            $record[$column] = $this->boundedCell($value);
        }

        if ($record === []) {
            return [];
        }
        ksort($record);
        $max = max(array_keys($record));

        return array_map(fn (int $index) => $record[$index] ?? '', range(0, $max));
    }

    private function extractTextNodes(string $xml): string
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                return '';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $parts = [];
        foreach ($xpath->query('//*[local-name()="t"]') as $text) {
            $parts[] = $text->textContent;
        }

        return $this->boundedCell(implode('', $parts));
    }

    private function assertZipEntrySafe(ZipArchive $zip, string $name, int $maxBytes, bool $required): void
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            if ($required) {
                throw new RuntimeException('XLSX first worksheet was not found.');
            }
            return;
        }

        $size = (int) ($stat['size'] ?? 0);
        $compressed = max(1, (int) ($stat['comp_size'] ?? 1));
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('XLSX content is too large to import safely.');
        }
        if (($size / $compressed) > self::DEFAULT_MAX_ZIP_RATIO) {
            throw new RuntimeException('XLSX compression ratio is unsafe; possible zip bomb rejected.');
        }
    }

    private function zipUri(string $path, string $entry): string
    {
        return 'zip://'.$path.'#'.$entry;
    }

    private function assertHeaders(array $headers): void
    {
        if (in_array('', $headers, true)) {
            throw new RuntimeException('Import contains an empty/invalid column header.');
        }
        if (count($headers) !== count(array_unique($headers))) {
            throw new RuntimeException('Import contains duplicate column headers after normalization.');
        }
    }

    private function boundedCell(mixed $value): string
    {
        $value = (string) $value;
        if (strlen($value) > self::DEFAULT_MAX_CELL_BYTES) {
            throw new RuntimeException('Import cell value is too large.');
        }

        return $value;
    }

    private function normalizeHeader(mixed $header): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $header)), '_');
    }

    private function columnIndex(string $letters): int
    {
        $result = 0;
        foreach (str_split($letters) as $letter) {
            $result = $result * 26 + (ord($letter) - 64);
        }

        return $result - 1;
    }
}
