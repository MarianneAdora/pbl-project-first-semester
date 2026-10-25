<?php
$content = file_get_contents('applicant/application-status.php');
$needle = "require_once __DIR__ . '/../src/Helpers/display.php';";
$pos = strpos($content, $needle);
if ($pos === false) { echo 'needle not found' . PHP_EOL; exit; }
$end = $pos + strlen($needle);
$insert = "\n\n// Interview functions live in hr/static-data.php (no interviews table in DB yet --\n// DATABASE: replace static_interview_*() with queries on an interviews table).\nrequire_once __DIR__ . '/../hr/static-data.php';";
$content = substr_replace($content, $insert, $end, 0);
file_put_contents('applicant/application-status.php', $content);
echo 'Inserted include' . PHP_EOL;