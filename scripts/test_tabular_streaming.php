<?php

declare(strict_types=1);

require __DIR__.'/../app/Services/TabularFileReader.php';

use App\Services\TabularFileReader;

$tmp = tempnam(sys_get_temp_dir(), 'hf-csv-');
if ($tmp === false) {
    fwrite(STDERR, "Unable to allocate temp file\n");
    exit(1);
}

try {
    $handle = fopen($tmp, 'wb');
    fputcsv($handle, ['family_id', 'member_id', 'name', 'gender', 'age'], ',', '"', '');
    for ($i = 1; $i <= 100000; $i++) {
        fputcsv($handle, ['F'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'M'.$i, 'Member '.$i, $i % 2 ? 'male' : 'female', (string) ($i % 80)], ',', '"', '');
    }
    fclose($handle);

    $reader = new TabularFileReader();
    $count = 0;
    foreach ($reader->rows($tmp, 'csv') as $row) {
        $count++;
        if ($count === 1 && ($row['family_id'] ?? null) !== 'F0000001') {
            throw new RuntimeException('First streamed CSV row was parsed incorrectly.');
        }
    }
    if ($count !== 100000) {
        throw new RuntimeException("Expected 100000 rows, got {$count}.");
    }

    $peak = memory_get_peak_usage(true);
    if ($peak > 32 * 1024 * 1024) {
        throw new RuntimeException('CSV streaming peak memory exceeded 32 MiB: '.$peak);
    }
    echo '100k CSV streaming PASS; peak_memory_bytes='.$peak.PHP_EOL;

    file_put_contents($tmp, "Family ID,Family-ID\n1,2\n");
    try {
        iterator_to_array($reader->rows($tmp, 'csv'));
        throw new RuntimeException('Duplicate normalized header was not rejected.');
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), 'duplicate column headers')) {
            throw $e;
        }
    }
    echo 'Duplicate normalized header rejection PASS'.PHP_EOL;

    $huge = str_repeat('x', 70000);
    $handle = fopen($tmp, 'wb');
    fputcsv($handle, ['family_id', 'name'], ',', '"', '');
    fputcsv($handle, ['F1', $huge], ',', '"', '');
    fclose($handle);
    try {
        iterator_to_array($reader->rows($tmp, 'csv'));
        throw new RuntimeException('Oversized cell was not rejected.');
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), 'cell value is too large')) {
            throw $e;
        }
    }
    echo 'Oversized cell rejection PASS'.PHP_EOL;
} finally {
    @unlink($tmp);
}
