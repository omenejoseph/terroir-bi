<?php

declare(strict_types=1);

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download without buffering the whole file in memory — the one
 * piece of response-shaping every export endpoint shares, so a row's shape
 * only ever needs deciding once per feature (in the controller, from the same
 * Query the on-screen table already uses), not reimplemented per download.
 */
class CsvExporter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<scalar|null>>  $rows
     */
    public function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
