<?php

declare(strict_types=1);

namespace App\Core;

final class Csv
{
    /**
     * @param string[] $header
     * @param iterable<array<int,scalar|null>> $rows
     */
    public static function stream(string $filename, array $header, iterable $rows): string
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return '';
    }
}
