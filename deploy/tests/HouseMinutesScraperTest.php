<?php

use PHPUnit\Framework\TestCase;

$repo_root = dirname(__DIR__, 2);
$log_path = $repo_root . '/includes/class.Log.php';
$import_path = $repo_root . '/includes/class.Import.php';

if (!file_exists($log_path)) {
    throw new RuntimeException('Missing Log class at ' . $log_path);
}
if (!file_exists($import_path)) {
    throw new RuntimeException('Missing Import class at ' . $import_path);
}

require_once($log_path);
require_once($import_path);

class NullLog extends Log
{
    public function __construct()
    {
    }

    public function put($message, $level)
    {
        return true;
    }
}

class HouseMinutesScraperTest extends TestCase
{
    private function sampleHouseMinutesHtml(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
  <body>
    <div class="minutes-admin-vga">
      <header>
        <h5>Virginia House of Delegates</h5>
        <h5>2025 Regular Session</h5>
        <h5 class="main">House Minutes</h5>
        <h5>Don Scott, Speaker</h5>
        <h5>G. Paul Nardo, Clerk</h5>
        <h5>Wednesday, January  8, 2025</h5>
      </header>
      <div class="minutes-wrapper">
        <input type="hidden" id="minute-id" value="1956">
        <div class="wrapper minutes-sections">
          <section>
            <p>Called to order at 12 m. by Don Scott, Speaker</p>
            <p>Mace placed on Speaker's table by Sergeant at Arms</p>
            <p>Attendance roll call - Quorum present</p>
            <p>Leaves of Absence granted: Delegates Callsen and Marshall</p>
            <p>Message from Senate: Senate duly organized and ready to proceed to business</p>
            <p>RESOLUTIONS</p>
            <p>HJ 429 - Agreed to (Y-97 N-0 A-0)</p>
          </section>
        </div>
      </div>
    </div>
  </body>
</html>
HTML;
    }

    public function testParseHouseMinutesId(): void
    {
        $html = $this->sampleHouseMinutesHtml();
        $import = new Import(new NullLog());
        $this->assertSame(1956, $import->parse_house_minutes_id($html));
    }

    public function testExtractHouseMinutesData(): void
    {
        $html = $this->sampleHouseMinutesHtml();
        $import = new Import(new NullLog());
        $data = $import->extract_house_minutes_data($html);

        $this->assertIsArray($data);
        $this->assertSame('2025-01-08', $data['date']);
        $this->assertIsString($data['text']);
        $this->assertStringContainsString('Virginia House of Delegates', $data['text']);
        $this->assertStringContainsString('House Minutes', $data['text']);
        $this->assertStringContainsString('Called to order', $data['text']);
        $this->assertGreaterThan(150, strlen($data['text']));
    }
}
