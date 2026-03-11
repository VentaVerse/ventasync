<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ErrorLogController extends Controller
{
    private function logPath(): string
    {
        return storage_path('logs/error.log');
    }

    private function ensureLogExists(): void
    {
        $path = $this->logPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!file_exists($path)) {
            @file_put_contents($path, '');
        }
    }

    public function index(Request $request)
    {
        $path = $this->logPath();
        $exists = file_exists($path);

        $sizeBytes = $exists ? filesize($path) : 0;
        $sizeHuman = $this->humanBytes($sizeBytes);

        $writable = $exists ? is_writable($path) : is_writable(dirname($path));

        $lines = [];
        if ($exists && $sizeBytes > 0) {
            $lines = $this->tailFile($path, 250);
        }

        return view('error_log.index', [
            'logExists' => $exists,
            'logPath' => $path,
            'sizeBytes' => $sizeBytes,
            'sizeHuman' => $sizeHuman,
            'writable' => $writable,
            'lines' => $lines,
        ]);
    }

    public function clear(Request $request)
    {
        $this->ensureLogExists();

        $path = $this->logPath();
        @file_put_contents($path, '', LOCK_EX);
        @chmod($path, 0664);

        return redirect()->route('error_log.index')->with('status', 'error.log cleared.');
    }

    public function test(Request $request)
    {
        @trigger_error('ERP error.log test warning (web)', E_USER_WARNING);

        return redirect()->route('error_log.index')->with('status', 'Test warning generated.');
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }

    private function tailFile(string $path, int $lines = 200): array
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [];
        }

        $buffer = '';
        $chunkSize = 8192;
        $lineCount = 0;

        fseek($fh, 0, SEEK_END);
        $fileSize = ftell($fh);

        while ($fileSize > 0 && $lineCount <= $lines) {
            $seek = max($fileSize - $chunkSize, 0);
            $read = $fileSize - $seek;

            fseek($fh, $seek, SEEK_SET);
            $chunk = fread($fh, $read);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");

            if ($seek === 0) {
                break;
            }

            $fileSize = $seek;
        }

        fclose($fh);

        $allLines = preg_split("/\r\n|\n|\r/", $buffer);
        $allLines = array_values(array_filter($allLines, fn($l) => $l !== ''));

        return array_slice($allLines, -$lines);
    }
}
