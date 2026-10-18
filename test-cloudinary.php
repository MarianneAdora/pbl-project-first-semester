<?php
require_once 'src/Services/CloudinaryService.php';

echo "<h2>Cloudinary test</h2>";

try {
    $cloud = new CloudinaryService();
    echo "Credentials loaded from .env — OK<br>";
} catch (Exception $e) {
    die("FAILED: " . htmlspecialchars($e->getMessage()));
}

// ---- 1. Text upload (no file picker needed) ----
// Uses Cloudinary's raw/upload with an inline text file to prove the credentials + signature work.
$env = parse_ini_file(__DIR__ . '/.env');
$cloudName = $env['CLOUDINARY_CLOUD_NAME'] ?? '';
$apiKey    = $env['CLOUDINARY_API_KEY'] ?? '';
$apiSecret = $env['CLOUDINARY_API_SECRET'] ?? '';

$tmp = tempnam(sys_get_temp_dir(), 'cloudtest') . '.txt';
file_put_contents($tmp, 'Cloudinary test from EBSMI system - ' . date('Y-m-d H:i:s'));

$timestamp = time();
$params = ['folder' => 'test', 'timestamp' => $timestamp];
ksort($params);
$signature = sha1(http_build_query($params) . $apiSecret);

$ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => new CURLFile($tmp, 'text/plain', 'cloudinary-test.txt'),
    'api_key' => $apiKey,
    'timestamp' => $timestamp,
    'folder' => 'test',
    'signature' => $signature,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);

if ($httpCode === 200) {
    $result = json_decode($response, true);
    echo "SUCCESS! Cloudinary is working.<br>";
    echo "Test file URL: <a href='" . htmlspecialchars($result['secure_url'] ?? '') . "' target='_blank'>" . htmlspecialchars($result['secure_url'] ?? '') . "</a><br>";
} else {
    echo "FAILED (HTTP {$httpCode}).<br><pre>" . htmlspecialchars($response) . "</pre>";
}

// ---- 2. Real CV upload (optional file picker) ----
echo "<hr><h3>Upload a real CV</h3>";
echo "<form method='post' enctype='multipart/form-data'>";
echo "<input type='file' name='cv' accept='.pdf,.doc,.docx'>";
echo "<button type='submit'>Upload CV to Cloudinary</button>";
echo "</form>";

if (!empty($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
    try {
        // Flat ebsmi_cvs layout; returns ['url','public_id','name'].
        $uploaded = $cloud->uploadCV($_FILES['cv']['tmp_name'], 'test', 'ebsmi_cvs', (string) ($_FILES['cv']['name'] ?? ''), (string) ($_FILES['cv']['type'] ?? ''));
        $url = is_array($uploaded) ? ($uploaded['url'] ?? '') : (string) $uploaded;
        echo "SUCCESS! CV uploaded: <a href='" . htmlspecialchars($url) . "' target='_blank'>" . htmlspecialchars($url) . "</a><br>";
        echo "Public ID: <code>" . htmlspecialchars(is_array($uploaded) ? ($uploaded['public_id'] ?? '') : '') . "</code> (flat inside ebsmi_cvs, no subfolder)<br>";
        if (stripos($url, '/image/upload/') !== false) echo "Type: image (PDF previewable — Gemini can fetch this URL later)<br>";
        else echo "Type: raw (DOC/DOCX — downloads instead of previewing)<br>";
    } catch (Exception $e) {
        echo "CV upload FAILED: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
} elseif (!empty($_FILES['cv'])) {
    echo "File error code: " . (int) $_FILES['cv']['error'] . "<br>";
}
