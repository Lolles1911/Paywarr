<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin;

use Filament\Actions\Action;

/** "Export CSV" for the Paywarr pages: a semicolon-separated file Excel opens as it is (UTF-8 with BOM). */
class Csv
{
    /** The text of a CSV file with $header and $rows (lists of cells). */
    public static function text(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => is_array($cell) ? implode(', ', $cell) : (string) $cell, $row), ';');
        }
        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);

        return $text;
    }

    /** A header action that downloads what $make returns: [header, rows]. */
    public static function action(string $name, callable $make): Action
    {
        return Action::make('csv')->label('Export CSV')->icon('ri-download-2-line')->color('gray')
            ->action(function () use ($name, $make) {
                [$header, $rows] = $make();
                $text = self::text($header, $rows);

                return response()->streamDownload(function () use ($text) {
                    echo $text;
                }, $name . '-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }
}
