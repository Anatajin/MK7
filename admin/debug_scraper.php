<?php
require_once 'init.php';
require_once '../includes/ThreeSixFiveScraper.php';

header('Content-Type: text/plain; charset=utf-8');

echo "Testing 365Scores API Connection...\n";
$apiUrl = "https://webws.365scores.com/web/games/?langId=27&timezoneName=UTC&userCountryId=2&startDate=27/03/2026&endDate=27/03/2026&sportIds=1";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP CODE: $httpCode\n";
if ($res) echo "JSON SUCCESS: Found " . count(json_decode($res, true)['games'] ?? []) . " games.\n";

echo "\nTesting Puppeteer (runtime directories + crashpad-safe launch)...\n";
$testScript = <<<'JS'
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');
const runtime = preparePuppeteerRuntime(__dirname);
const puppeteer = require('puppeteer');

(async () => {
    try {
        const browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime));
        console.log('Puppeteer Launch Success');
        console.log('User data dir: ' + runtime.userDataDir);
        console.log('Executable: ' + (browser.process() ? browser.process().spawnfile : 'unknown'));
        await browser.close();
    } catch (error) {
        console.error('Launch Error: ' + error.message);
        console.error('User data dir: ' + runtime.userDataDir);
    }
})();
JS;
$tmpFile = __DIR__ . '/../tmp_test.js';
file_put_contents($tmpFile, $testScript);
$result = shell_exec("node " . escapeshellarg($tmpFile) . " 2>&1");
echo "Result:\n" . trim($result) . "\n";
@unlink($tmpFile);
