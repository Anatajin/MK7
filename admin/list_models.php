<?php
$apiKey = getenv('GEMINI_API_KEY') ?: '';
if ($apiKey === '') {
    die("Missing GEMINI_API_KEY environment variable.\n");
}
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=$apiKey";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if (isset($data['models'])) {
    foreach ($data['models'] as $model) {
        echo $model['name'] . "\n";
    }
} else {
    echo "No models found or error: " . $response;
}
