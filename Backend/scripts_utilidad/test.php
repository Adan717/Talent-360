<?php
$pdo = new PDO('sqlite:database/database.sqlite');

echo "--- TIME ENTRIES (Counts by Type) ---\n";
$stmt = $pdo->query("SELECT type, COUNT(*) as c FROM time_entries GROUP BY type");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- TIME ENTRIES (Last 5) ---\n";
$stmt = $pdo->query("SELECT * FROM time_entries ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- CONTINGENCIES ---\n";
$stmt = $pdo->query("SELECT * FROM contingencies LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- AUDIT LOGS ---\n";
$stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
