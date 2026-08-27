<?php

require_once __DIR__ . '/../app/actions.php';

$page = $_GET['page'] ?? 'landing';
$section = $_GET['section'] ?? null;
$action = $_POST['action'] ?? null;

function ensure_directory(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function clear_guard_precheck_selfies(): void
{
    unset($_SESSION['guard_check_in_selfie'], $_SESSION['guard_check_out_selfie']);
}

// Real face verification (CompreFace, with legacy-brightness fallback) now
// lives in app/faceMatch.php and is loaded once via bootstrap.php, since
// api.php (the Flutter app's endpoint) needs the exact same logic.

function dashboard_route_for_role(?string $role): string
{
    return match ($role) {
        'super_admin' => '/index.php?page=super-admin',
        'admin' => '/index.php?page=admin',
        default => '/index.php?page=dashboard',
    };
}

function admin_ensure_org_duty_labels_column(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $result = db()->query("SHOW COLUMNS FROM organizations LIKE 'duty_labels'");
        if ($result && $result->num_rows > 0) {
            return;
        }

        db()->query("ALTER TABLE organizations ADD COLUMN duty_labels JSON DEFAULT NULL AFTER subscription_status");
    } catch (Throwable $e) {
        // If the runtime migration fails, fall back to empty labels instead of crashing.
    }
}

function admin_ensure_duty_sites_customer_column(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $result = db()->query("SHOW COLUMNS FROM duty_sites LIKE 'customer_id'");
        if ($result && $result->num_rows > 0) {
            return;
        }

        db()->query("ALTER TABLE duty_sites ADD COLUMN customer_id INT DEFAULT NULL AFTER organization_id");
    } catch (Throwable $e) {
        // Fall back to allowing duty sites without customer linkage on legacy databases.
    }
}

function admin_ensure_selfies_capture_phase_column(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $result = db()->query("SHOW COLUMNS FROM selfies LIKE 'capture_phase'");
        if ($result && $result->num_rows > 0) {
            return;
        }

        db()->query("ALTER TABLE selfies ADD COLUMN capture_phase ENUM('check_in','check_out') NOT NULL DEFAULT 'check_in' AFTER image_path");
    } catch (Throwable $e) {
        // Legacy databases can still fall back to the default check-in flow.
    }
}

function admin_fetch_org_duty_labels(int $organizationId): array
{
    admin_ensure_org_duty_labels_column();
    $stmt = db()->prepare("SELECT duty_labels FROM organizations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['duty_labels'])) {
        return [];
    }

    $decoded = json_decode((string)$row['duty_labels'], true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $decoded), static fn($label) => $label !== ''));
}

function admin_save_org_duty_labels(int $organizationId, array $labels): bool
{
    admin_ensure_org_duty_labels_column();
    $normalized = array_values(array_filter(array_map('trim', $labels), static fn($label) => $label !== ''));
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $stmt = db()->prepare("UPDATE organizations SET duty_labels = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $json, $organizationId);
    return $stmt->execute();
}

function admin_read_zone_labels_from_request(): array
{
    $labels = $_POST['zone_labels'] ?? null;
    if (is_array($labels)) {
        return array_values(array_filter(array_map('trim', $labels), static fn($label) => $label !== ''));
    }

    $single = trim((string)($_POST['zone_label'] ?? ''));
    if ($single === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,]+/', $single) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn($label) => $label !== ''));
}

function super_admin_upload_path(array $file, string $prefix, string $subdir): ?string
{
    if (empty($file['name']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $uploadsDir = __DIR__ . '/../storage/uploads/' . trim($subdir, '/');
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $safeExt = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
    $filename = $prefix . '_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower((string)$file['name'])) . '_' . date('Ymd_His') . '.' . $safeExt;
    $absolutePath = $uploadsDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
      return null;
    }

    return '/storage/uploads/' . trim($subdir, '/') . '/' . $filename;
}

if ($action === 'login') {
    try {
        clear_guard_precheck_selfies();
        $user = authenticate(trim($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        redirect(dashboard_route_for_role($user['role'] ?? null));
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('/index.php?page=auth');
    }
}

if ($action === 'logout') {
    logout_user();
    redirect('/index.php?page=landing');
}

if ($action === 'create_organization') {
    require_role(['super_admin']);
    $logoUrl = super_admin_upload_path($_FILES['organization_logo'] ?? [], 'org_logo', 'organizations');
    $adminPhotoUrl = super_admin_upload_path($_FILES['admin_photo'] ?? [], 'admin_photo', 'admins');
    $stmt = db()->prepare(
        "INSERT INTO organizations (name, code, contact_email, phone, logo_url, status, plan, guard_limit, subscription_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'active', ?, ?, 'active', NOW(), NOW())"
    );
    $plan = $_POST['plan'] ?? 'starter';
    $guardLimit = (int)($_POST['guard_limit'] ?? 50);
    $stmt->bind_param(
        'sssssii',
        $_POST['name'],
        $_POST['code'],
        $_POST['contact_email'],
        $_POST['phone'],
        $logoUrl,
        $plan,
        $guardLimit
    );
    $stmt->execute();
    $orgId = (int)$stmt->insert_id;
    if (!empty($_POST['admin_full_name']) && !empty($_POST['admin_email']) && !empty($_POST['admin_password'])) {
        $passwordHash = password_hash((string)$_POST['admin_password'], PASSWORD_DEFAULT);
        $adminStmt = db()->prepare(
            "INSERT INTO users (organization_id, role, full_name, email, password_hash, phone, avatar_url, shift_label, status, created_at, updated_at, last_seen_at)
             VALUES (?, 'admin', ?, ?, ?, ?, ?, 'Control Room', 'active', NOW(), NOW(), NOW())"
        );
        $adminStmt->bind_param(
            'issssss',
            $orgId,
            $_POST['admin_full_name'],
            $_POST['admin_email'],
            $passwordHash,
            $_POST['admin_phone'],
            $adminPhotoUrl
        );
        $adminStmt->execute();
    }
    flash_set('success', 'Organization workspace created.');
    redirect('/index.php?page=super-admin');
}

if ($action === 'create_admin') {
    require_role(['super_admin']);
    $passwordHash = password_hash((string)($_POST['password'] ?? ''), PASSWORD_DEFAULT);
    $orgId = (int)($_POST['organization_id'] ?? 0);
    $adminPhotoUrl = super_admin_upload_path($_FILES['admin_photo'] ?? [], 'admin_photo', 'admins');
    $stmt = db()->prepare(
        "INSERT INTO users (organization_id, role, full_name, email, password_hash, phone, employee_code, avatar_url, shift_label, status, created_at, updated_at, last_seen_at)
         VALUES (?, 'admin', ?, ?, ?, ?, ?, ?, 'Control Room', 'active', NOW(), NOW(), NOW())"
    );
    $stmt->bind_param(
        'issssss',
        $orgId,
        $_POST['full_name'],
        $_POST['email'],
        $passwordHash,
        $_POST['phone'],
        $_POST['employee_code'],
        $adminPhotoUrl
    );
    $stmt->execute();
    flash_set('success', 'Organization admin created.');
    redirect('/index.php?page=super-admin');
}

if ($action === 'create_guard') {
    require_role(['admin']);
    $plainPassword = trim((string)($_POST['password'] ?? ''));
    if ($plainPassword === '') {
        flash_set('error', 'Guard password is required.');
        redirect('/index.php?page=admin&section=admin-create-guard');
    }
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $orgId = (int)($_POST['organization_id'] ?? 0);
    $dutySiteId = (int)($_POST['duty_site_id'] ?? 0);
    $dutyZoneRaw = $_POST['duty_zone_labels'] ?? [];
    if (!is_array($dutyZoneRaw)) {
        $dutyZoneRaw = explode(',', (string)$dutyZoneRaw);
    }
    $dutyZoneLabels = array_values(array_filter(array_map('trim', $dutyZoneRaw)));
    $shiftLabel = trim((string)($_POST['shift_label'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $identityPhotoPath = null;
    $identitySelfiePath = null;

    if (!empty($_FILES['identity_photo']['name']) && is_uploaded_file($_FILES['identity_photo']['tmp_name'])) {
        $uploadsDir = __DIR__ . '/../storage/uploads/guards';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }

        $extension = strtolower(pathinfo((string)$_FILES['identity_photo']['name'], PATHINFO_EXTENSION));
        $safeExt = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $filename = 'guard_identity_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($fullName ?: ('guard_' . $orgId))) . '_' . date('Ymd_His') . '.' . $safeExt;
        $absolutePath = $uploadsDir . '/' . $filename;
        move_uploaded_file($_FILES['identity_photo']['tmp_name'], $absolutePath);
        $identityPhotoPath = '/storage/uploads/guards/' . $filename;
    }

    if (!empty($_FILES['identity_selfie']['name']) && is_uploaded_file($_FILES['identity_selfie']['tmp_name'])) {
        $uploadsDir = __DIR__ . '/../storage/uploads/guards';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }
        $extension = strtolower(pathinfo((string)$_FILES['identity_selfie']['name'], PATHINFO_EXTENSION));
        $safeExt = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $filename = 'guard_selfie_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($fullName ?: ('guard_' . $orgId))) . '_' . date('Ymd_His') . '.' . $safeExt;
        $absolutePath = $uploadsDir . '/' . $filename;
        move_uploaded_file($_FILES['identity_selfie']['tmp_name'], $absolutePath);
        $identitySelfiePath = '/storage/uploads/guards/' . $filename;
    }

    $identityEnrolledAt = date('Y-m-d H:i:s');
    if ($dutySiteId > 0) {
        $stmt = db()->prepare(
            "INSERT INTO users (organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code, shift_label, status, avatar_url, identity_photo_path, identity_selfie_path, identity_enrolled_at, created_at, updated_at, last_seen_at)
             VALUES (?, ?, 'guard', ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW(), NOW(), NOW(), NOW())"
        );
        $stmt->bind_param(
            'iisssssssss',
            $orgId,
            $dutySiteId,
            $fullName,
            $email,
            $passwordHash,
            $phone,
            $employeeCode,
            $shiftLabel,
            $identityPhotoPath,
            $identityPhotoPath,
            $identitySelfiePath
        );
    } else {
        $stmt = db()->prepare(
            "INSERT INTO users (organization_id, duty_site_id, role, full_name, email, password_hash, phone, employee_code, shift_label, status, avatar_url, identity_photo_path, identity_selfie_path, identity_enrolled_at, created_at, updated_at, last_seen_at)
             VALUES (?, NULL, 'guard', ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW(), NOW(), NOW(), NOW())"
        );
        $stmt->bind_param(
            'isssssssss',
            $orgId,
            $fullName,
            $email,
            $passwordHash,
            $phone,
            $employeeCode,
            $shiftLabel,
            $identityPhotoPath,
            $identityPhotoPath,
            $identitySelfiePath
        );
    }
    $stmt->execute();
    $guardId = (int)$stmt->insert_id;
    if ($dutyZoneLabels) {
        $zoneSummary = implode(', ', $dutyZoneLabels);
        $activity = db()->prepare(
            "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
             VALUES (?, ?, 'guard', 'Duty zones assigned', ?, NOW())"
        );
        $details = $zoneSummary . ($identityPhotoPath ? ' | ' . $identityPhotoPath : '');
        $activity->bind_param('iis', $guardId, $orgId, $details);
        $activity->execute();
    }
    flash_set('success', 'Guard account created.');
    redirect('/index.php?page=admin&section=admin-create-guard');
}

if ($action === 'create_customer') {
    require_role(['admin']);
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $stmt = db()->prepare(
        "INSERT INTO customers (organization_id, name, description, status, created_at, updated_at)
         VALUES (?, ?, ?, 'active', NOW(), NOW())"
    );
    $stmt->bind_param('iss', $organizationId, $name, $description);
    $stmt->execute();
    flash_set('success', 'Customer record saved.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'update_customer') {
    require_role(['admin']);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = $_POST['status'] ?? 'active';
    $stmt = db()->prepare("UPDATE customers SET name = ?, description = ?, status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('sssi', $name, $description, $status, $customerId);
    $stmt->execute();
    flash_set('success', 'Customer updated.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'delete_customer') {
    require_role(['admin']);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    flash_set('success', 'Customer deleted.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'create_customer_location') {
    require_role(['admin']);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $area = trim((string)($_POST['area'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $stmt = db()->prepare(
        "INSERT INTO customer_locations (customer_id, organization_id, name, area, address, latitude, longitude, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->bind_param('iissddss', $customerId, $organizationId, $name, $area, $address, $latitude, $longitude, $status);
    $stmt->execute();
    flash_set('success', 'Customer location saved.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'update_customer_location') {
    require_role(['admin']);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $area = trim((string)($_POST['area'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $stmt = db()->prepare(
        "UPDATE customer_locations SET name = ?, area = ?, address = ?, latitude = ?, longitude = ?, status = ?, updated_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('sssddsi', $name, $area, $address, $latitude, $longitude, $status, $locationId);
    $stmt->execute();
    flash_set('success', 'Customer location updated.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'delete_customer_location') {
    require_role(['admin']);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM customer_locations WHERE id = ?");
    $stmt->bind_param('i', $locationId);
    $stmt->execute();
    flash_set('success', 'Customer location deleted.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'create_customer_assignment') {
    require_role(['admin']);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $locationId = (int)($_POST['customer_location_id'] ?? 0);
    $guardId = (int)($_POST['guard_id'] ?? 0);
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $stmt = db()->prepare(
        "INSERT INTO customer_guard_assignments (customer_id, customer_location_id, guard_id, organization_id, status, notes, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->bind_param('iiiiss', $customerId, $locationId, $guardId, $organizationId, $status, $notes);
    $stmt->execute();
    flash_set('success', 'Guard assigned to customer location.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'update_customer_assignment') {
    require_role(['admin']);
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $stmt = db()->prepare("UPDATE customer_guard_assignments SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $status, $notes, $assignmentId);
    $stmt->execute();
    flash_set('success', 'Guard booking updated.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'delete_customer_assignment') {
    require_role(['admin']);
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM customer_guard_assignments WHERE id = ?");
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    flash_set('success', 'Guard booking deleted.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'guard_shift') {
    require_role(['guard']);
    $currentUser = current_user();
    $mode = $_POST['mode'] ?? 'check_in';
    $locationLabel = trim((string)($_POST['location_label'] ?? 'Field checkpoint'));
    $note = trim((string)($_POST['note'] ?? ''));
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $capturePhase = ($_POST['capture_phase'] ?? '') === 'check_out' ? 'check_out' : 'check_in';
    $requiredSelfie = $_SESSION['guard_' . $capturePhase . '_selfie'] ?? null;
    if (empty($requiredSelfie['image_path'] ?? null)) {
        flash_set('error', 'Please capture a fresh selfie before ' . ($mode === 'check_out' ? 'stopping' : 'starting') . ' your shift.');
        redirect('/index.php?page=dashboard&section=guard-attendance&auto_camera=1');
    }

    if ($mode === 'check_out') {
        $stmt = db()->prepare(
            "UPDATE attendance
             SET check_out_at = NOW(), note = CASE WHEN note = '' THEN ? ELSE note END, updated_at = NOW()
             WHERE user_id = ? AND check_out_at IS NULL
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->bind_param('si', $note, $currentUser['id']);
        $stmt->execute();
        $activity = db()->prepare(
            "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
             VALUES (?, ?, 'attendance', 'Shift stopped', ?, NOW())"
        );
        $details = $locationLabel . ($note !== '' ? ' | ' . $note : '');
        $activity->bind_param('iis', $currentUser['id'], $organizationId, $details);
        $activity->execute();
        clear_guard_precheck_selfies();
        flash_set('success', 'Shift stopped.');
        redirect('/index.php?page=dashboard');
    }

    $openAttendanceStmt = db()->prepare(
        "SELECT id FROM attendance
         WHERE user_id = ? AND check_out_at IS NULL
         LIMIT 1"
    );
    $openAttendanceStmt->bind_param('i', $currentUser['id']);
    $openAttendanceStmt->execute();
    $openAttendance = $openAttendanceStmt->get_result()->fetch_assoc();
    if ($openAttendance) {
        flash_set('error', 'You are already checked in for an active shift.');
        redirect('/index.php?page=dashboard');
    }

    $stmt = db()->prepare(
        "INSERT INTO attendance (user_id, organization_id, location_label, note, check_in_at, check_out_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NULL, NOW(), NOW())"
    );
    $stmt->bind_param('iiss', $currentUser['id'], $organizationId, $locationLabel, $note);
    $stmt->execute();
    $activity = db()->prepare(
        "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
         VALUES (?, ?, 'attendance', 'Shift started', ?, NOW())"
    );
    $details = $locationLabel . ($note !== '' ? ' | ' . $note : '');
    $activity->bind_param('iis', $currentUser['id'], $organizationId, $details);
    $activity->execute();
    clear_guard_precheck_selfies();
    flash_set('success', 'Shift started.');
    redirect('/index.php?page=dashboard');
}

if ($action === 'capture_selfie') {
    require_role(['guard']);
    $currentUser = current_user();
    $imageData = (string)($_POST['image_data'] ?? '');
    $capturePhase = ($_POST['capture_phase'] ?? 'check_in') === 'check_out' ? 'check_out' : 'check_in';
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#', $imageData)) {
        flash_set('error', 'Invalid selfie image.');
        redirect('/index.php?page=dashboard&section=guard-attendance');
    }

    $payload = preg_replace('#^data:image/(png|jpeg|jpg);base64,#', '', $imageData);
    $binary = base64_decode($payload, true);
    if ($binary === false) {
        flash_set('error', 'Could not read selfie image.');
        redirect('/index.php?page=dashboard&section=guard-attendance');
    }

    admin_ensure_selfies_capture_phase_column();
    $uploadsDir = __DIR__ . '/../storage/uploads/selfies';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'selfie_' . $currentUser['id'] . '_' . date('Ymd_His') . '.png';
    $absolutePath = $uploadsDir . '/' . $filename;
    file_put_contents($absolutePath, $binary);

    $relativePath = '/storage/uploads/selfies/' . $filename;
    $referencePath = $currentUser['identity_photo_path'] ?? null;
    $verification = compare_guard_images($referencePath, $relativePath);
    $identityStatus = $verification['status'] ?? ($verification['passed'] ? 'matched' : 'no_reference');
    $verificationScore = $verification['score'];
    $verificationPassed = (int)$verification['passed'];
    $verificationMethod = $verification['method'];
    $stmt = db()->prepare(
        "INSERT INTO selfies (user_id, organization_id, image_path, capture_phase, captured_at, identity_status, reference_image_path, verification_score, verification_passed, verification_method, created_at)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param(
        'iissssdis',
        $currentUser['id'],
        $currentUser['organization_id'],
        $relativePath,
        $capturePhase,
        $identityStatus,
        $referencePath,
        $verificationScore,
        $verificationPassed,
        $verificationMethod
    );
    $stmt->execute();
    $_SESSION['guard_' . $capturePhase . '_selfie'] = [
        'image_path' => $relativePath,
        'captured_at' => now(),
        'verification_score' => $verificationScore,
        'verification_passed' => $verificationPassed,
        'reference_image_path' => $referencePath,
    ];
    $activity = db()->prepare(
        "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
         VALUES (?, ?, 'selfie', 'Selfie captured', ?, NOW())"
    );
    $details = $relativePath . ' | score=' . ($verificationScore !== null ? (string)$verificationScore : 'n/a') . ' | passed=' . $verificationPassed;
    $activity->bind_param('iis', $currentUser['id'], $currentUser['organization_id'], $details);
    $activity->execute();

    flash_set('success', $capturePhase === 'check_out' ? 'Checkout selfie captured.' : 'Selfie captured.');
    redirect('/index.php?page=dashboard&section=guard-attendance');
}

if ($action === 'clear_precheck_selfie') {
    require_role(['guard']);
    clear_guard_precheck_selfies();
    flash_set('success', 'Selfie cleared. Capture a new one to continue.');
    redirect('/index.php?page=dashboard&section=guard-attendance&auto_camera=1');
}

if ($action === 'save_location') {
    require_role(['guard']);
    $currentUser = current_user();
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $accuracy = (int)($_POST['accuracy'] ?? 0);
    $address = trim((string)($_POST['address'] ?? ''));
    $dutyLabel = trim((string)($_POST['duty_label'] ?? ($currentUser['shift_label'] ?? 'Field checkpoint')));

    $stmt = db()->prepare(
        "INSERT INTO locations (user_id, organization_id, latitude, longitude, accuracy_meters, address, duty_label, tracked_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->bind_param(
        'iiddiss',
        $currentUser['id'],
        $currentUser['organization_id'],
        $latitude,
        $longitude,
        $accuracy,
        $address,
        $dutyLabel
    );
    $stmt->execute();
    $activity = db()->prepare(
        "INSERT INTO activities (user_id, organization_id, type, title, details, created_at)
         VALUES (?, ?, 'location', 'Location saved', ?, NOW())"
    );
    $details = $dutyLabel . ' | ' . $latitude . ',' . $longitude;
    $activity->bind_param('iis', $currentUser['id'], $currentUser['organization_id'], $details);
    $activity->execute();
    flash_set('success', 'Location saved.');
    redirect('/index.php?page=dashboard&section=guard-map');
}

if ($action === 'update_guard') {
    require_role(['admin']);
    $guardId = (int)($_POST['guard_id'] ?? 0);
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $employeeCode = trim((string)($_POST['employee_code'] ?? ''));
    $shiftLabel = trim((string)($_POST['shift_label'] ?? ''));
    $status = $_POST['status'] ?? 'active';
    $dutySiteIdRaw = trim((string)($_POST['duty_site_id'] ?? ''));
    $dutySiteId = $dutySiteIdRaw === '' ? null : (int)$dutySiteIdRaw;
    $plainPassword = trim((string)($_POST['password'] ?? ''));

    $identityPhotoPath = null;
    $identitySelfiePath = null;
    $avatarUrl = null;

    if (!empty($_FILES['identity_photo']['name']) && is_uploaded_file($_FILES['identity_photo']['tmp_name'])) {
        $uploadsDir = __DIR__ . '/../storage/uploads/guards';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }
        $extension = strtolower(pathinfo((string)$_FILES['identity_photo']['name'], PATHINFO_EXTENSION));
        $safeExt = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $filename = 'guard_identity_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($fullName ?: ('guard_' . $guardId))) . '_' . date('Ymd_His') . '.' . $safeExt;
        $absolutePath = $uploadsDir . '/' . $filename;
        if (move_uploaded_file($_FILES['identity_photo']['tmp_name'], $absolutePath)) {
            $identityPhotoPath = '/storage/uploads/guards/' . $filename;
            $avatarUrl = $identityPhotoPath;
        }
    }

    if (!empty($_FILES['identity_selfie']['name']) && is_uploaded_file($_FILES['identity_selfie']['tmp_name'])) {
        $uploadsDir = __DIR__ . '/../storage/uploads/guards';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }
        $extension = strtolower(pathinfo((string)$_FILES['identity_selfie']['name'], PATHINFO_EXTENSION));
        $safeExt = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $filename = 'guard_selfie_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($fullName ?: ('guard_' . $guardId))) . '_' . date('Ymd_His') . '.' . $safeExt;
        $absolutePath = $uploadsDir . '/' . $filename;
        if (move_uploaded_file($_FILES['identity_selfie']['tmp_name'], $absolutePath)) {
            $identitySelfiePath = '/storage/uploads/guards/' . $filename;
        }
    }

    $guardStmt = db()->prepare("SELECT identity_photo_path, identity_selfie_path, avatar_url, password_hash FROM users WHERE id = ? AND role = 'guard' LIMIT 1");
    $guardStmt->bind_param('i', $guardId);
    $guardStmt->execute();
    $existingGuard = $guardStmt->get_result()->fetch_assoc() ?: [];

    $nextAvatar = $avatarUrl ?? ($existingGuard['avatar_url'] ?? null);
    $nextIdentityPhoto = $identityPhotoPath ?? ($existingGuard['identity_photo_path'] ?? null);
    $nextIdentitySelfie = $identitySelfiePath ?? ($existingGuard['identity_selfie_path'] ?? null);
    $nextPasswordHash = $existingGuard['password_hash'] ?? '';
    if ($plainPassword !== '') {
        $nextPasswordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    if ($dutySiteId !== null) {
        $stmt = db()->prepare(
            "UPDATE users
             SET full_name = ?, email = ?, phone = ?, employee_code = ?, shift_label = ?, status = ?, duty_site_id = ?, avatar_url = COALESCE(?, avatar_url), identity_photo_path = COALESCE(?, identity_photo_path), identity_selfie_path = COALESCE(?, identity_selfie_path), identity_enrolled_at = COALESCE(identity_enrolled_at, NOW()), password_hash = ?, updated_at = NOW()
             WHERE id = ? AND role = 'guard'"
        );
        $stmt->bind_param('ssssssissssi', $fullName, $email, $phone, $employeeCode, $shiftLabel, $status, $dutySiteId, $nextAvatar, $nextIdentityPhoto, $nextIdentitySelfie, $nextPasswordHash, $guardId);
    } else {
        $stmt = db()->prepare(
            "UPDATE users
             SET full_name = ?, email = ?, phone = ?, employee_code = ?, shift_label = ?, status = ?, duty_site_id = NULL, avatar_url = COALESCE(?, avatar_url), identity_photo_path = COALESCE(?, identity_photo_path), identity_selfie_path = COALESCE(?, identity_selfie_path), identity_enrolled_at = COALESCE(identity_enrolled_at, NOW()), password_hash = ?, updated_at = NOW()
             WHERE id = ? AND role = 'guard'"
        );
        $stmt->bind_param('ssssssssssi', $fullName, $email, $phone, $employeeCode, $shiftLabel, $status, $nextAvatar, $nextIdentityPhoto, $nextIdentitySelfie, $nextPasswordHash, $guardId);
    }
    $stmt->execute();

    $referenceForRecheck = $nextIdentityPhoto ?: $nextAvatar;
    if ($referenceForRecheck) {
        admin_ensure_selfies_capture_phase_column();
        $selfieStmt = db()->prepare("SELECT id, image_path, capture_phase FROM selfies WHERE user_id = ? ORDER BY captured_at DESC");
        if ($selfieStmt) {
            $selfieStmt->bind_param('i', $guardId);
            $selfieStmt->execute();
            $selfieRows = $selfieStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($selfieRows as $selfieRow) {
                $imagePath = (string)($selfieRow['image_path'] ?? '');
                if ($imagePath === '') {
                    continue;
                }
                $recheck = compare_guard_images($referenceForRecheck, $imagePath);
    $newStatus = $recheck['status'] ?? ($recheck['passed'] ? 'matched' : 'no_reference');
                $updateSelfie = db()->prepare(
                    "UPDATE selfies
                     SET reference_image_path = ?, verification_score = ?, verification_passed = ?, verification_method = ?, identity_status = ?
                     WHERE id = ?"
                );
                if ($updateSelfie) {
                    $updateSelfie->bind_param(
                        'sdissi',
                        $referenceForRecheck,
                        $recheck['score'],
                        $recheck['passed'],
                        $recheck['method'],
                        $newStatus,
                        $selfieRow['id']
                    );
                    $updateSelfie->execute();
                }
            }
        }
    }

    flash_set('success', 'Guard updated.');
    redirect('/index.php?page=admin&section=admin-guard-detail&edit_guard=' . $guardId);
}

if ($action === 'delete_guard') {
    require_role(['admin']);
    $guardId = (int)($_POST['guard_id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM users WHERE id = ? AND role = 'guard'");
    $stmt->bind_param('i', $guardId);
    $stmt->execute();
    flash_set('success', 'Guard deleted.');
    redirect('/index.php?page=admin&section=admin-guard-detail');
}

if ($action === 'create_duty_site') {
    require_role(['admin']);
    admin_ensure_duty_sites_customer_column();
    $stmt = db()->prepare(
        "INSERT INTO duty_sites (organization_id, customer_id, name, area, address, latitude, longitude, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $area = trim((string)($_POST['area'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $stmt->bind_param('iissddss', $organizationId, $customerId, $name, $area, $address, $latitude, $longitude, $status);
    $stmt->execute();
    flash_set('success', 'Duty site created.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'update_duty_site') {
    require_role(['admin']);
    admin_ensure_duty_sites_customer_column();
    $siteId = (int)($_POST['site_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $area = trim((string)($_POST['area'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $latitude = (float)($_POST['latitude'] ?? 0);
    $longitude = (float)($_POST['longitude'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $organizationId = (int)($_POST['organization_id'] ?? (current_user()['organization_id'] ?? 0));
    $stmt = db()->prepare(
        "UPDATE duty_sites SET name = ?, area = ?, address = ?, latitude = ?, longitude = ?, status = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?"
    );
    $stmt->bind_param('sssddsii', $name, $area, $address, $latitude, $longitude, $status, $siteId, $organizationId);
    $stmt->execute();
    flash_set('success', 'Duty site updated.');
    redirect('/index.php?page=admin&section=admin-duty-site-management');
}

if ($action === 'delete_duty_site') {
    require_role(['admin']);
    $siteId = (int)($_POST['site_id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM duty_sites WHERE id = ?");
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    flash_set('success', 'Duty site deleted.');
    redirect('/index.php?page=admin&section=admin-master');
}

if ($action === 'add_zone_label') {
    require_role(['admin']);
    $currentUser = current_user();
    $organizationId = (int)($currentUser['organization_id'] ?? 0);
    $zoneLabels = admin_fetch_org_duty_labels($organizationId);
    foreach (admin_read_zone_labels_from_request() as $label) {
        if (!in_array($label, $zoneLabels, true)) {
            $zoneLabels[] = $label;
        }
    }
    admin_save_org_duty_labels($organizationId, $zoneLabels);
    flash_set('success', 'Duty zone label added.');
    redirect('/index.php?page=admin&section=admin-duty-site-management');
}

if ($action === 'delete_zone_label') {
    require_role(['admin']);
    $currentUser = current_user();
    $organizationId = (int)($currentUser['organization_id'] ?? 0);
    $removeLabels = admin_read_zone_labels_from_request();
    $removeLabel = $removeLabels[0] ?? '';
    $zoneLabels = array_values(array_filter(
        admin_fetch_org_duty_labels($organizationId),
        static fn($label) => $label !== $removeLabel
    ));
    admin_save_org_duty_labels($organizationId, $zoneLabels);
    flash_set('success', 'Duty zone label removed.');
    redirect('/index.php?page=admin&section=admin-duty-site-management');
}

if ($action === 'save_zone_labels') {
    require_role(['admin']);
    $currentUser = current_user();
    $organizationId = (int)($currentUser['organization_id'] ?? 0);
    $zoneLabels = admin_read_zone_labels_from_request();
    if (!$zoneLabels) {
        $zoneLabels = admin_fetch_org_duty_labels($organizationId);
    }
    admin_save_org_duty_labels($organizationId, $zoneLabels);
    flash_set('success', 'Duty zone labels saved.');
    redirect('/index.php?page=admin&section=admin-duty-site-management');
}

if ($action === 'set_zone_label_selection') {
    require_role(['admin']);
    $currentUser = current_user();
    $organizationId = (int)($currentUser['organization_id'] ?? 0);
    $selected = trim((string)($_POST['zone_label'] ?? ''));
    if ($selected !== '') {
        $zoneLabels = admin_fetch_org_duty_labels($organizationId);
        if (!in_array($selected, $zoneLabels, true)) {
            $zoneLabels[] = $selected;
            admin_save_org_duty_labels($organizationId, $zoneLabels);
            flash_set('success', 'Duty zone label added.');
        } else {
            flash_set('success', 'Duty zone label selected.');
        }
    }
    redirect('/index.php?page=admin&section=admin-duty-site-management');
}

if ($action === 'update_organization') {
    require_role(['super_admin']);
    $orgId = (int)($_POST['organization_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $plan = $_POST['plan'] ?? 'starter';
    $guardLimit = (int)($_POST['guard_limit'] ?? 50);
    $status = $_POST['status'] ?? 'active';
    $subscriptionStatus = $_POST['subscription_status'] ?? 'active';
    $logoUrl = super_admin_upload_path($_FILES['organization_logo'] ?? [], 'org_logo', 'organizations');
    if ($logoUrl === null) {
        $logoUrl = null;
    }
    $stmt = db()->prepare(
        "UPDATE organizations
         SET name = ?, code = ?, contact_email = ?, phone = ?, plan = ?, guard_limit = ?, status = ?, subscription_status = ?, logo_url = COALESCE(?, logo_url), updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('sssssisssi', $name, $code, $contactEmail, $phone, $plan, $guardLimit, $status, $subscriptionStatus, $logoUrl, $orgId);
    $stmt->execute();
    flash_set('success', 'Organization updated.');
    redirect('/index.php?page=super-admin&section=organizations');
}

if ($action === 'reset_admin_password') {
    require_role(['super_admin']);
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $tempPassword = 'Temp@' . random_int(100000, 999999);
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = NOW() WHERE id = ? AND role = 'admin'");
    $stmt->bind_param('si', $hash, $adminId);
    $stmt->execute();
    $_SESSION['super_admin_temp_passwords'] = $_SESSION['super_admin_temp_passwords'] ?? [];
    $_SESSION['super_admin_temp_passwords'][$adminId] = $tempPassword;
    flash_set('success', 'Temporary password: ' . $tempPassword);
    redirect('/index.php?page=super-admin&section=organization-admins');
}

if ($action === 'update_admin_profile') {
    require_role(['super_admin']);
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $avatarUrl = super_admin_upload_path($_FILES['admin_photo'] ?? [], 'admin_photo', 'admins');
    $stmt = db()->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, avatar_url = COALESCE(?, avatar_url), updated_at = NOW() WHERE id = ? AND role = 'admin'");
    $stmt->bind_param('ssssi', $fullName, $email, $phone, $avatarUrl, $adminId);
    $stmt->execute();
    flash_set('success', 'Admin profile updated.');
    redirect('/index.php?page=super-admin&section=organization-admins');
}

if ($page === 'dashboard') {
    $currentUser = current_user();
    if ($currentUser && ($currentUser['role'] ?? null) !== 'guard') {
        redirect(dashboard_route_for_role($currentUser['role'] ?? null));
    }
}

include __DIR__ . '/../views/layout.php';
