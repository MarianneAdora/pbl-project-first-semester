<?php
// generate_hash.php
$adminPassword = 'admin123';
$hrPassword = 'hr123';

$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
$hrHash = password_hash($hrPassword, PASSWORD_DEFAULT);

echo "<strong>Admin Hash (admin123):</strong><br>" . $adminHash . "<br><br>";
echo "<strong>HR Hash (hr123):</strong><br>" . $hrHash . "<br><br>";
echo "Copy these hashes and paste them into your seed.sql or pgAdmin!";
?>