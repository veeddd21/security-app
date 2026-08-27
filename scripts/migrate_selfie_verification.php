<?php

require __DIR__ . '/../app/db.php';

$alterStatements = [
    'reference_image_path' => 'ADD COLUMN reference_image_path VARCHAR(255) DEFAULT NULL AFTER identity_status',
    'verification_score' => 'ADD COLUMN verification_score DECIMAL(5,4) DEFAULT NULL AFTER reference_image_path',
    'verification_passed' => 'ADD COLUMN verification_passed TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_score',
    'verification_method' => 'ADD COLUMN verification_method VARCHAR(50) DEFAULT NULL AFTER verification_passed',
];

foreach ($alterStatements as $column => $alter) {
    $check = db()->query("SHOW COLUMNS FROM selfies LIKE '" . db()->real_escape_string($column) . "'");
    if ($check && $check->num_rows > 0) {
        echo "exists {$column}\n";
        continue;
    }

    db()->query("ALTER TABLE selfies {$alter}");
    echo "added {$column}\n";
}

echo "migration complete\n";
