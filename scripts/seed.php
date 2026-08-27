<?php

require_once __DIR__ . '/../app/bootstrap.php';

$db = db();

$passwords = [
    'super_admin' => password_hash('Super@123', PASSWORD_DEFAULT),
    'admin' => password_hash('Admin@123', PASSWORD_DEFAULT),
    'guard' => password_hash('Guard@123', PASSWORD_DEFAULT),
];

$db->begin_transaction();

try {
    $now = now();

    $stmt = $db->prepare(
        "INSERT INTO organizations (name, code, contact_email, phone, status, plan, guard_limit, subscription_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', 'enterprise', 250, 'active', ?, ?)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           contact_email = VALUES(contact_email),
           phone = VALUES(phone),
           status = VALUES(status),
           plan = VALUES(plan),
           guard_limit = VALUES(guard_limit),
           subscription_status = VALUES(subscription_status),
           updated_at = VALUES(updated_at)"
    );
    $name = 'Infipre Security';
    $code = 'INFIPRE';
    $email = 'ops@infipre.local';
    $phone = '+91 90000 00000';
    $stmt->bind_param('ssssss', $name, $code, $email, $phone, $now, $now);
    $stmt->execute();

    $orgId = (int)$db->insert_id;
    if ($orgId === 0) {
        $row = $db->query("SELECT id FROM organizations WHERE code = 'INFIPRE' LIMIT 1")->fetch_assoc();
        $orgId = (int)($row['id'] ?? 0);
    }
    if ($orgId <= 0) {
        throw new RuntimeException('Failed to resolve organization id.');
    }

    $seedUsers = [
        [
            'role' => 'super_admin',
            'full_name' => 'Richard Infipre',
            'email' => 'richard.infipre@gmail.com',
            'password_hash' => $passwords['super_admin'],
            'phone' => '+91 90000 00001',
            'employee_code' => 'SA-001',
            'shift_label' => 'HQ',
        ],
        [
            'role' => 'admin',
            'full_name' => 'Infipre Admin',
            'email' => 'admin@infipre.local',
            'password_hash' => $passwords['admin'],
            'phone' => '+91 90000 00002',
            'employee_code' => 'AD-001',
            'shift_label' => 'Control Room',
        ],
        [
            'role' => 'guard',
            'full_name' => 'Infipre Guard',
            'email' => 'guard@infipre.local',
            'password_hash' => $passwords['guard'],
            'phone' => '+91 90000 00003',
            'employee_code' => 'GR-001',
            'shift_label' => 'Field checkpoint',
        ],
    ];

    $userStmt = $db->prepare(
        "INSERT INTO users (
            organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code,
            shift_label, status, avatar_url, identity_photo_path, identity_selfie_path, identity_enrolled_at,
            session_version, last_login_at, last_seen_at, created_at, updated_at
        ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'active', NULL, NULL, NULL, ?, 1, NOW(), NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
           organization_id = VALUES(organization_id),
           role = VALUES(role),
           full_name = VALUES(full_name),
           password_hash = VALUES(password_hash),
           phone = VALUES(phone),
           employee_code = VALUES(employee_code),
           shift_label = VALUES(shift_label),
           status = VALUES(status),
           identity_enrolled_at = VALUES(identity_enrolled_at),
           updated_at = VALUES(updated_at)"
    );

    foreach ($seedUsers as $seedUser) {
        $identityEnrolledAt = $now;
        $createdAt = $now;
        $updatedAt = $now;
        $userStmt->bind_param(
            'issssssssss',
            $orgId,
            $seedUser['role'],
            $seedUser['full_name'],
            $seedUser['email'],
            $seedUser['password_hash'],
            $seedUser['phone'],
            $seedUser['employee_code'],
            $seedUser['shift_label'],
            $identityEnrolledAt,
            $createdAt,
            $updatedAt
        );
        $userStmt->execute();
    }

    $seedDutySites = [
        ['North Perimeter', 'North Gate', 'Main entry and perimeter patrol', 28.613900, 77.209000],
        ['Lobby', 'Front Desk', 'Reception and visitor screening', 28.614120, 77.209450],
        ['Control Room', 'HQ', 'Monitoring and command desk', 28.613500, 77.208900],
    ];

    $siteStmt = $db->prepare(
        "INSERT INTO duty_sites (organization_id, name, area, address, latitude, longitude, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)
         ON DUPLICATE KEY UPDATE
           area = VALUES(area),
           address = VALUES(address),
           latitude = VALUES(latitude),
           longitude = VALUES(longitude),
           status = VALUES(status),
           updated_at = VALUES(updated_at)"
    );

    foreach ($seedDutySites as $site) {
        [$siteName, $siteArea, $siteAddress, $lat, $lng] = $site;
        $siteCreatedAt = $now;
        $siteUpdatedAt = $now;
        $siteStmt->bind_param('isssddss', $orgId, $siteName, $siteArea, $siteAddress, $lat, $lng, $siteCreatedAt, $siteUpdatedAt);
        $siteStmt->execute();
    }

    $db->commit();
    echo "Seed completed successfully.\n";
    echo "Super Admin: richard.infipre@gmail.com / Super@123\n";
    echo "Admin: admin@infipre.local / Admin@123\n";
    echo "Guard: guard@infipre.local / Guard@123\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
