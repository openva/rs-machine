<?php

use PHPUnit\Framework\TestCase;

final class BillsJsonlExportTest extends TestCase
{
    public function testJsonlExportWritesPerYearFile(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL extension not available.');
        }

        $temp_root = sys_get_temp_dir() . '/rs-machine-jsonl-' . bin2hex(random_bytes(6));
        $api_root = $temp_root . '/api';
        $downloads_dir = $temp_root . '/downloads';

        mkdir($api_root . '/bills', 0775, true);
        mkdir($api_root . '/bill/2024', 0775, true);
        mkdir($downloads_dir, 0775, true);

        $bill_list = [
            ['number' => 'hb1'],
            ['number' => 'sb2'],
        ];
        file_put_contents($api_root . '/bills/2024.json', json_encode($bill_list));

        $bill_detail_hb1 = ['number' => 'hb1', 'year' => '2024', 'title' => 'Test Bill'];
        $bill_detail_sb2 = ['number' => 'sb2', 'year' => '2024', 'title' => 'Another Bill'];
        file_put_contents($api_root . '/bill/2024/hb1.json', json_encode($bill_detail_hb1));
        file_put_contents($api_root . '/bill/2024/sb2.json', json_encode($bill_detail_sb2));

        $env = [
            'RS_JSONL_API_BASE' => 'file://' . $api_root,
            'RS_JSONL_START_YEAR' => '2024',
            'RS_JSONL_CURRENT_YEAR' => '2024',
            'RS_JSONL_DOWNLOADS_DIR' => $downloads_dir,
            'RS_JSONL_SLEEP_USEC' => '0',
            'RS_JSONL_ONLY' => '1',
        ];

        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg('cron/export.php');

        $descriptor_spec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor_spec, $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($process, 'Failed to start export process.');

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);
        $this->assertSame(0, $exit_code, 'Export script failed: ' . $stderr . $stdout);

        $output_path = $downloads_dir . '/bills-2024.jsonl';
        $this->assertFileExists($output_path);

        $lines = file($output_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        $decoded_first = json_decode($lines[0], true);
        $decoded_second = json_decode($lines[1], true);

        $this->assertSame('2024', $decoded_first['year']);
        $this->assertSame('2024', $decoded_second['year']);
        $this->assertContains($decoded_first['number'], ['hb1', 'sb2']);
        $this->assertContains($decoded_second['number'], ['hb1', 'sb2']);

        $this->cleanupDir($temp_root);
    }

    private function cleanupDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
