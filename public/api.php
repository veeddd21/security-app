<?php
//  CORS 
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Max-Age: 3600');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../app/api.php';

//  Path resolution 
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$requestPath = preg_replace('#^/php-core/public#', '', $requestPath);
$requestPath = rtrim($requestPath, '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

//  AUTH 
if ($requestPath === '/api/auth/login' && $method === 'POST') {
    $data = api_request_data();
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        api_respond(false, 'Invalid email or password.', [], 401);
    }
    db()->query("UPDATE users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = " . (int)$user['id']);
    $token = api_issue_token($user);
    api_respond(true, 'Login successful.', ['token' => $token, 'user' => api_public_user($user)]);
}

if ($requestPath === '/api/auth/logout' && $method === 'POST') {
    $token = api_bearer_token();
    if ($token) {
        $stmt = db()->prepare("DELETE FROM api_tokens WHERE token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
    }
    api_respond(true, 'Logged out.');
}

//  PROTECTED ROUTES (require Bearer token) 
if (str_starts_with($requestPath, '/api/')) {
    $user = api_auth_user();

    //  GUARD: Overview 
    if ($requestPath === '/api/guard/overview' && $method === 'GET') {
        $stmt = db()->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        api_respond(true, 'Guard overview loaded.', [
            'user'       => api_public_user($user),
            'attendance' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        ]);
    }

    //  GUARD: Check-in 
    if ($requestPath === '/api/guard/checkin' && $method === 'POST') {
        $data  = api_request_data();
        $label = trim((string)($data['location_label'] ?? ''));
        $openAttendanceStmt = db()->prepare(
            "SELECT id FROM attendance
             WHERE user_id = ? AND check_out_at IS NULL
             LIMIT 1"
        );
        $openAttendanceStmt->bind_param('i', $user['id']);
        $openAttendanceStmt->execute();
        $openAttendance = $openAttendanceStmt->get_result()->fetch_assoc();
        if ($openAttendance) {
            api_respond(false, 'You are already checked in for an active shift.', [], 409);
        }
        $stmt  = db()->prepare(
            "INSERT INTO attendance (user_id, organization_id, location_label, check_in_at, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW(), NOW())"
        );
        $stmt->bind_param('iis', $user['id'], $user['organization_id'], $label);
        $stmt->execute();
        $id  = db()->insert_id;
        $details = json_encode([
            'attendance_id' => $id,
            'location_label' => $label
        ]);

        $activityStmt = db()->prepare(
            "INSERT INTO activities (user_id, organization_id, type, title, details, created_at )
            VALUES (?, ?, ?, ?,?, NOW())"
        );
        $type  = 'check_in';
        $title = 'Guard started shift';

        $activityStmt->bind_param('iisss', $user['id'], $user['organization_id'], $type, $title, $details);

        $activityStmt->execute();
        $row = db()->query("SELECT * FROM attendance WHERE id = $id")->fetch_assoc();
        api_respond(true, 'Checked in.', ['attendance' => $row]);
    }

    //  GUARD: Check-out 
    if ($requestPath === '/api/guard/checkout' && $method === 'POST') {
        $data         = api_request_data();
        $attendanceId = (int)($data['attendance_id'] ?? 0);
        $stmt         = db()->prepare(
            "UPDATE attendance SET check_out_at = NOW(), updated_at = NOW()
             WHERE id = ? AND user_id = ? AND check_out_at IS NULL"
        );
        $stmt->bind_param('ii', $attendanceId, $user['id']);
        $stmt->execute();
        $row = db()->query(
            "SELECT location_label
            FROM attendance
            WHERE id = " . (int)$attendanceId . "
            LIMIT 1"
        )->fetch_assoc();

        $details = json_encode([
            'attendance_id' => $attendanceId,
            'location_label' => $row['location_label'] ?? ''
        ]);

        if (db()->affected_rows > 0) {
        $activityStmt = db()->prepare(
            "INSERT INTO activities (user_id, organization_id, type, title, details, created_at )
            VALUES (?, ?, ?, ?, ?, NOW())"
        );

        $type  = 'check_out';
        $title = 'Guard stopped shift';

        $activityStmt->bind_param('iisss', $user['id'], $user['organization_id'], $type, $title, $details);

        $activityStmt->execute();
    }
        api_respond(true, 'Checked out.', ['rows_affected' => db()->affected_rows]);
    }

   //  GUARD: Selfie upload 
if ($requestPath === '/api/guard/selfie' && $method === 'POST') {
    $config    = require __DIR__ . '/../app/config.php';
    $uploadDir = rtrim($config['upload_dir'], '/\\') . '/selfies';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $uid          = (int)$user['id'];
    $orgId        = (int)$user['organization_id'];
    $ts           = date('Ymd_His');
    $capturePhase = isset($_POST['capture_phase']) ? trim($_POST['capture_phase']) : 'check_in';
    if (!in_array($capturePhase, ['check_in', 'check_out'], true)) {
        $capturePhase = 'check_in';
    }

    $fname  = null;
    $pathDb = null;   // relative path stored in DB:  /storage/uploads/selfies/selfie_X_Y.jpg

    // ── METHOD A: standard PHP file upload (native mobile / curl) 
    if (!empty($_FILES['selfie']['tmp_name']) && $_FILES['selfie']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['selfie'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) $ext = 'jpg';
        $fname  = "selfie_{$uid}_{$ts}.{$ext}";
        $dest   = $uploadDir . '/' . $fname;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            error_log("SELFIE: move_uploaded_file failed. dest=$dest");
            api_respond(false, 'Failed to save selfie (move failed).', [], 500);
        }
        $pathDb = '/storage/uploads/selfies/' . $fname;
    }

    // ── METHOD B: raw multipart parse (Flutter Web / browser fetch) 
    if ($pathDb === null) {
        $rawInput    = file_get_contents('php://input');
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'multipart/form-data') &&
            preg_match('/boundary=([^\s;]+)/', $contentType, $bm)) {
            $boundary = $bm[1];
            $parts    = explode('--' . $boundary, $rawInput);
            foreach ($parts as $part) {
                if (!str_contains($part, 'name="selfie"')) continue;
                $splitPos = strpos($part, "\r\n\r\n");
                if ($splitPos === false) $splitPos = strpos($part, "\n\n");
                if ($splitPos === false) continue;
                $headerSection = substr($part, 0, $splitPos);
                $body          = rtrim(substr($part, $splitPos + 4), "\r\n-");
                if (strlen($body) < 100) continue;
                $ext = 'jpg';
                if (preg_match('/Content-Type:\s*image\/(\w+)/i', $headerSection, $cm)) {
                    $ext = strtolower($cm[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                }
                $fname  = "selfie_{$uid}_{$ts}.{$ext}";
                $dest   = $uploadDir . '/' . $fname;
                if (file_put_contents($dest, $body) === false) {
                    api_respond(false, 'Failed to save selfie (write failed).', [], 500);
                }
                $pathDb = '/storage/uploads/selfies/' . $fname;
                // Parse capture_phase from raw body if not already in $_POST
                if (preg_match('/name="capture_phase"\r?\n\r?\n([^\r\n-]+)/', $rawInput, $pm)) {
                    $rawPhase = trim($pm[1]);
                    if (in_array($rawPhase, ['check_in', 'check_out'], true)) {
                        $capturePhase = $rawPhase;
                    }
                }
                break;
            }
        }
    }

    if ($pathDb === null) {
        error_log('SELFIE: No file received. FILES=' . print_r($_FILES, true));
        api_respond(false, 'No selfie file received.', [], 400);
    }

    // ── FACE VERIFICATION (shared with the web app — see app/faceMatch.php) 
    // Real face matching via CompreFace when configured, with the old
    // brightness-diff comparison only as a last-resort fallback.
    $referencePath = $user['identity_photo_path'] ?? null;   // enrolled guard photo
    $verification = compare_guard_images($referencePath, $pathDb);

    $verificationScore  = $verification['score'];
    $verificationPassed = (int)$verification['passed'];
    $verificationMethod = $verification['method'];
    $identityStatus      = $verification['status'];

    // ── Save to selfies table (all verification columns filled) 
    $stmt = db()->prepare(
        "INSERT INTO selfies
            (user_id, organization_id, image_path, capture_phase, captured_at,
             identity_status, reference_image_path, verification_score,
             verification_passed, verification_method, created_at)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())"
    );
    // Keep the type string aligned with the 9 bound values below.
    $stmt->bind_param(
        'iisssssis',
        $uid,
        $orgId,
        $pathDb,
        $capturePhase,
        $identityStatus,
        $referencePath,
        $verificationScore,
        $verificationPassed,
        $verificationMethod
    );
    if (!$stmt->execute()) {
        error_log('SELFIE DB error: ' . db()->error);
        api_respond(false, 'Selfie saved to disk but database insert failed.', [], 500);
    }

    // ── Log to activities table 
    $actDetails = $pathDb . ' | score=' . ($verificationScore !== null ? (string)$verificationScore : 'n/a') . ' | passed=' . $verificationPassed;
    $actStmt = db()->prepare(
        "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
         VALUES (?, ?, 'selfie', 'Selfie captured', ?, NOW())"
    );
    $actStmt->bind_param('iis', $uid, $orgId, $actDetails);
    $actStmt->execute();

    api_respond(true, 'Selfie saved.', [
        'path'               => $pathDb,
        'phase'              => $capturePhase,
        'identity_status'    => $identityStatus,
        'verification_score' => $verificationScore,
        'verification_passed'=> $verificationPassed,
    ]);
}

    //  GUARD: Attendance history 
    if ($requestPath === '/api/guard/attendance' && $method === 'GET') {
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $stmt  = db()->prepare(
            "SELECT * FROM attendance WHERE user_id = ? ORDER BY check_in_at DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $user['id'], $limit);
        $stmt->execute();
        api_respond(true, 'Attendance loaded.', [
            'attendance' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        ]);
    }

    //  GUARD: Locations history
    if ($requestPath === '/api/guard/locations' && $method === 'GET') {
        $limit = min((int)($_GET['limit'] ?? 6), 50);
        $stmt = db()->prepare(
            "SELECT * FROM locations WHERE user_id = ? ORDER BY tracked_at DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $user['id'], $limit);
        $stmt->execute();
        api_respond(true, 'Locations loaded.', [
            'locations' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        ]);
    }

    //  GUARD: History
    if ($requestPath === '/api/guard/history' && $method === 'GET') {
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $query = trim((string)($_GET['query'] ?? ''));
        $sql = "
            SELECT
                a.*,
                TIMESTAMPDIFF(MINUTE, a.check_in_at, COALESCE(a.check_out_at, NOW())) AS duration_minutes,
                CASE
                    WHEN a.check_out_at IS NULL THEN 'active'
                    ELSE 'completed'
                END AS status
            FROM attendance a
            WHERE a.user_id = ?
        ";
        if ($query !== '') {
            $sql .= " AND (
                a.location_label LIKE CONCAT('%', ?, '%')
            )";
        }
        $sql .= " ORDER BY a.check_in_at DESC LIMIT ?";
        $stmt = db()->prepare($sql);
        if ($query !== '') {
            $stmt->bind_param('isi', $user['id'], $query, $limit);
        } else {
            $stmt->bind_param('ii', $user['id'], $limit);
        }
        $stmt->execute();
        api_respond(true, 'History loaded.', [
            'records' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
        ]);
    }

    //  GUARD: Profile 
    if ($requestPath === '/api/guard/profile' && $method === 'GET') {
        api_respond(true, 'Profile loaded.', ['user' => api_public_user($user)]);
    }

    //  GUARD: Location ping 
    if ($requestPath === '/api/guard/location' && $method === 'POST') {
        $data      = api_request_data();
        $latitude  = (float)($data['latitude'] ?? 0);
        $longitude = (float)($data['longitude'] ?? 0);
        $accuracy  = (int)($data['accuracy'] ?? 0);
        $address   = trim((string)($data['address'] ?? ''));
        $dutyLabel = trim((string)($data['duty_label'] ?? ($user['shift_label'] ?? 'Field checkpoint')));
        $stmt      = db()->prepare(
            "INSERT INTO locations (user_id, organization_id, latitude, longitude, accuracy_meters, address, duty_label, tracked_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('iiddiss', $user['id'], $user['organization_id'], $latitude, $longitude, $accuracy, $address, $dutyLabel);
        $stmt->execute();
        api_respond(true, 'Location saved.');
    }

    //  ADMIN: Overview 
    if ($requestPath === '/api/admin/overview' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId  = (int)$user['organization_id'];
        $guards = db()->query("SELECT COUNT(*) AS cnt FROM users WHERE role='guard' AND organization_id=$orgId")->fetch_assoc()['cnt'];
        $onDuty = db()->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM attendance WHERE organization_id=$orgId AND DATE(check_in_at)=CURDATE() AND check_out_at IS NULL")->fetch_assoc()['cnt'];
        $sites  = db()->query("SELECT COUNT(*) AS cnt FROM duty_sites WHERE organization_id=$orgId")->fetch_assoc()['cnt'];
        $pings  = db()->query("SELECT COUNT(*) AS cnt FROM locations WHERE organization_id=$orgId AND DATE(tracked_at)=CURDATE()")->fetch_assoc()['cnt'];
        api_respond(true, 'Overview loaded.', [
            'guards'      => (int)$guards,
            'on_duty'     => (int)$onDuty,
            'sites'       => (int)$sites,
            'pings_today' => (int)$pings,
        ]);
    }

    //  ADMIN: Guards list 
    if ($requestPath === '/api/admin/guards' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId = (int)$user['organization_id'];
        $rows  = db()->query("SELECT * FROM users WHERE role='guard' AND organization_id=$orgId ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);
        api_respond(true, 'Guards loaded.', ['guards' => array_map('api_public_user', $rows)]);
    }

    //  ADMIN: Guard detail 
    if (preg_match('#^/api/admin/guards/(\d+)$#', $requestPath, $m) && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $gid   = (int)$m[1];
        $orgId = (int)$user['organization_id'];
        $guard = db()->query("SELECT * FROM users WHERE id=$gid AND organization_id=$orgId LIMIT 1")->fetch_assoc();
        if (!$guard) api_respond(false, 'Guard not found.', [], 404);
        $attendance = db()->query("SELECT * FROM attendance WHERE user_id=$gid AND DATE(check_in_at)=CURDATE() ORDER BY check_in_at DESC")->fetch_all(MYSQLI_ASSOC);
        $lastPing   = db()->query("SELECT * FROM locations WHERE user_id=$gid ORDER BY tracked_at DESC LIMIT 1")->fetch_assoc();
        api_respond(true, 'Guard detail loaded.', [
            'guard'      => api_public_user($guard),
            'attendance' => $attendance,
            'last_ping'  => $lastPing,
        ]);
    }

    //  ADMIN: Sites 
    if ($requestPath === '/api/admin/sites' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId = (int)$user['organization_id'];
        $rows  = db()->query("SELECT * FROM duty_sites WHERE organization_id=$orgId ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
        api_respond(true, 'Sites loaded.', ['sites' => $rows]);
    }

    //  ADMIN: Today attendance 
    if ($requestPath === '/api/admin/attendance/today' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId = (int)$user['organization_id'];
        $rows  = db()->query("SELECT a.*, u.full_name, u.employee_code, u.shift_label FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.organization_id=$orgId AND DATE(a.check_in_at)=CURDATE() ORDER BY a.check_in_at DESC")->fetch_all(MYSQLI_ASSOC);
        api_respond(true, 'Today attendance loaded.', ['attendance' => $rows]);
    }

    //  ADMIN: Live locations 
    if ($requestPath === '/api/admin/locations/live' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId = (int)$user['organization_id'];
        $rows  = db()->query("SELECT l.*, u.full_name, u.employee_code FROM locations l JOIN users u ON u.id = l.user_id WHERE l.organization_id=$orgId AND l.tracked_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) AND l.id = (SELECT MAX(l2.id) FROM locations l2 WHERE l2.user_id = l.user_id) ORDER BY l.tracked_at DESC")->fetch_all(MYSQLI_ASSOC);
        api_respond(true, 'Live locations loaded.', ['locations' => $rows]);
    }

    //  ADMIN: Customers 
    if ($requestPath === '/api/admin/customers' && $method === 'GET') {
        if (!in_array($user['role'], ['admin', 'super_admin'])) api_respond(false, 'Forbidden.', [], 403);
        $orgId = (int)$user['organization_id'];
        $rows  = db()->query("SELECT * FROM customers WHERE organization_id=$orgId AND status='active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
        api_respond(true, 'Customers loaded.', ['customers' => $rows]);
    }

    //  ADMIN: Geocode search 
    if ($requestPath === '/api/admin/geocode/search' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') api_respond(true, 'No query.', ['results' => []]);
        $url     = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=8&q=' . urlencode($q);
        $context = stream_context_create(['http' => ['header' => "User-Agent: Secure360/1.0\r\n"]]);
        $json    = @file_get_contents($url, false, $context);
        $items   = json_decode((string)$json, true);
        $results = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $results[] = ['label' => $item['display_name'] ?? '', 'lat' => (float)($item['lat'] ?? 0), 'lng' => (float)($item['lon'] ?? 0)];
            }
        }
        api_respond(true, 'Geocode results.', ['results' => $results]);
    }

    api_respond(false, 'Endpoint not found.', [], 404);
}

api_respond(false, 'Not found.', [], 404);
