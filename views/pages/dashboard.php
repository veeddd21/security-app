<?php
$guardSection = $_GET['section'] ?? 'guard-overview';
$userId = (int)($user['id'] ?? 0);
$orgId = (int)($user['organization_id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
$stmt->bind_param('i', $userId);
$stmt->execute();
$attendanceRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = db()->prepare("SELECT * FROM selfies WHERE user_id = ? ORDER BY captured_at DESC LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$latestSelfie = $stmt->get_result()->fetch_assoc();

$stmt = db()->prepare("SELECT * FROM locations WHERE user_id = ? ORDER BY tracked_at DESC LIMIT 6");
$stmt->bind_param('i', $userId);
$stmt->execute();
$locationRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = db()->prepare("SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
$stmt->bind_param('i', $userId);
$stmt->execute();
$activityRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = db()->prepare("SELECT * FROM duty_sites WHERE organization_id = ? AND status = 'active' ORDER BY name ASC");
$stmt->bind_param('i', $orgId);
$stmt->execute();
$dutySiteRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$currentAttendance = null;
foreach ($attendanceRows as $row) {
    if (empty($row['check_out_at'])) {
        $currentAttendance = $row;
        break;
    }
}

$attendanceMinutesToday = 0;
foreach ($attendanceRows as $row) {
    $checkInTs = strtotime((string)($row['check_in_at'] ?? ''));
    if (!$checkInTs) {
        continue;
    }
    $checkOutTs = !empty($row['check_out_at']) ? strtotime((string)$row['check_out_at']) : time();
    if ($checkOutTs) {
        $attendanceMinutesToday += max(0, (int)(($checkOutTs - $checkInTs) / 60));
    }
}

$attendanceRecordsToday = count($attendanceRows);
$attendanceHoursToday = intdiv($attendanceMinutesToday, 60);
$attendanceRemainderMinutes = $attendanceMinutesToday % 60;

$viewTitle = match ($guardSection) {
    'guard-attendance' => 'Attendance & Selfie Verification',
    'guard-map' => 'Live Route Map',
    'guard-history-page' => 'Shift History',
    default => 'Guard Command Hub',
};

$viewSubtitle = match ($guardSection) {
    'guard-attendance' => 'Selfie and GPS proof.',
    'guard-map' => 'Live GPS while this screen is open.',
    'guard-history-page' => 'Recent shift records.',
    default => 'Selfie, shift, and live route.',
};

function guard_section_active(string $current, string $expected): bool {
    return $current === $expected;
}

function selfie_status_label(?array $selfie): string {
    if (!$selfie) {
        return 'Awaiting capture';
    }

    return match ((string)($selfie['identity_status'] ?? '')) {
        'matched' => 'Identity matched',
        'possible_match' => 'Review-band match',
        'reference_review' => 'Manual review',
        'upload_review' => 'Uploaded proof review',
        'identity_processing' => 'Processing',
        'face_not_detected' => 'Face not clear',
        'no_reference' => 'No reference enrolled',
        'match_not_configured' => 'Scoring not enabled',
        'match_unavailable' => 'Scoring unavailable',
        default => (int)($selfie['verification_passed'] ?? 0) === 1 ? 'Identity matched' : 'Selfie captured for review',
    };
}

function selfie_score_text(?array $selfie): string {
    if (!$selfie) {
        return 'Pending';
    }

    $score = $selfie['verification_score'] ?? null;
    if ($score === null || $score === '') {
        return 'Not available';
    }

    return (int)round(((float)$score) * 100) . '%';
}

function selfie_tone(?array $selfie): string {
    $status = (string)($selfie['identity_status'] ?? '');
    if (in_array($status, ['matched'], true) || (int)($selfie['verification_passed'] ?? 0) === 1) {
        return 'badge badge--soft';
    }

    if (in_array($status, ['mismatch', 'face_not_detected'], true)) {
        return 'badge badge--danger';
    }

    return 'badge badge--soft';
}

$checkInSelfie = $_SESSION['guard_check_in_selfie'] ?? null;
$checkOutSelfie = $_SESSION['guard_check_out_selfie'] ?? null;
$activeSelfie = $currentAttendance ? $checkOutSelfie : $checkInSelfie;
$shiftPreviewImage = $activeSelfie['image_path'] ?? ($latestSelfie['image_path'] ?? null);
$autoCamera = ($_GET['auto_camera'] ?? '0') === '1';
$capturePhase = $currentAttendance ? 'check_out' : 'check_in';
?>
<section class="page-grid page-grid--guard" data-selfie-refresh="<?= $activeSelfie ? '1' : '0' ?>">
  <?php if (guard_section_active($guardSection, 'guard-overview')): ?>
    <section class="guard-hero card" id="guard-overview">
      <div class="guard-hero__identity">
        <div class="avatar avatar--lg"><?= strtoupper(substr((string)($user['full_name'] ?? 'IS'), 0, 2)) ?></div>
        <div class="guard-hero__copy">
          <p class="eyebrow">Guard profile</p>
          <h2><?= h($user['full_name'] ?? '') ?></h2>
          <p><?= h($user['employee_code'] ?? '') ?></p>
        </div>
        <div class="guard-badges">
          <span class="badge"><?= h($currentAttendance ? 'On Duty' : 'Standby') ?></span>
          <span class="badge badge--soft"><?= h($latestSelfie ? 'Profile photo' : 'No profile photo') ?></span>
        </div>
      </div>
    </section>

    <section class="quick-panel card" id="guard-attendance">
      <div class="panel-head">
        <div>
          <p class="eyebrow">Quick shift action</p>
          <h2><?= $currentAttendance ? 'Stop duty shift' : 'Start duty shift' ?></h2>
          <p>Selfie, zone, and GPS.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="?page=dashboard&section=guard-attendance"><?= $currentAttendance ? 'Capture checkout selfie' : 'Capture check-in selfie' ?></a>
      </div>
      <div class="quick-action-body">
        <?php if ($activeSelfie || $autoCamera): ?>
          <div class="guard-confirm-card">
            <div class="guard-confirm-card__head">
              <div>
                <p class="guard-confirm-kicker"><?= $currentAttendance ? 'Confirm checkout' : 'Confirm shift' ?></p>
                <p class="guard-confirm-copy"><?= $currentAttendance ? 'Checkout selfie ready. Confirm before stopping.' : 'Selfie ready. Confirm before starting.' ?></p>
              </div>
              <span class="badge badge--soft">Selfie ready</span>
            </div>

            <div class="preview-ready">
              <div class="preview-ready__thumb">
                <?php if ($shiftPreviewImage): ?>
                  <img src="<?= h(asset_url($shiftPreviewImage)) ?>" alt="Captured selfie preview">
                <?php endif; ?>
              </div>
              <div class="preview-ready__copy">
                <p class="preview-ready__eyebrow">Preview ready</p>
                <strong><?= $currentAttendance ? 'Check the image before stopping.' : 'Check the image before starting.' ?></strong>
                <p>Retake if the selfie is not clear or the lighting changed.</p>
              </div>
              <form method="post">
                <input type="hidden" name="action" value="clear_precheck_selfie">
                <button class="btn btn-outline btn-sm" type="submit">Retake</button>
              </form>
            </div>

            <div class="field-summary">
              <span>Camera mode</span>
              <select id="camera-mode-overview" class="app-input">
                <option value="user">Front selfie camera</option>
                <option value="environment">Back camera</option>
                <option value="default">Browser default camera</option>
              </select>
              <p>Use front camera for selfie capture. Switch if the device supports it.</p>
            </div>

            <div id="camera-wrap-overview" class="camera-wrap">
              <video id="selfie-video-overview" class="camera-preview" autoplay playsinline muted></video>
              <canvas id="selfie-canvas-overview" class="sr-only"></canvas>
            </div>

            <div class="shift-actions">
              <button class="btn btn-outline btn-wide" type="button" onclick="startSelfieCamera('overview')">Use live selfie</button>
              <button class="btn btn-outline" type="button" onclick="stopSelfieCamera()">Cancel camera</button>
            </div>

            <div class="guard-confirm-grid">
              <div class="field-summary">
                <span>Duty zone</span>
                <select id="duty-zone-overview" class="app-input" form="shift-submit-form-overview" name="location_label">
                  <?php if ($dutySiteRows): ?>
                    <?php foreach ($dutySiteRows as $site): ?>
                      <option value="<?= h($site['name']) ?>"><?= h($site['name']) ?></option>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <option value="North Perimeter">North Perimeter</option>
                    <option value="Lobby">Lobby</option>
                    <option value="Control Room">Control Room</option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="field-summary">
                <span>Shift note</span>
                <input id="shift-note-overview" class="app-input" form="shift-submit-form-overview" name="note" type="text" placeholder="Optional note before shift start.">
              </div>
            </div>

            <form method="post" id="shift-submit-form-overview" class="shift-actions">
              <input type="hidden" name="action" value="guard_shift">
              <input type="hidden" name="mode" value="<?= $currentAttendance ? 'check_out' : 'check_in' ?>">
              <input type="hidden" name="organization_id" value="<?= (int)$orgId ?>">
              <input type="hidden" name="capture_phase" value="<?= h($capturePhase) ?>">
              <button class="btn btn-primary btn-wide" type="submit"><?= $currentAttendance ? 'Stop Shift' : 'Start Shift' ?></button>
              <a class="btn btn-outline" href="?page=dashboard&section=guard-map">Live map</a>
            </form>
          </div>
        <?php else: ?>
          <a
            class="btn btn-primary btn-wide quick-shift-button"
            href="?page=dashboard&section=guard-overview&auto_camera=1"
          >
            <?= $currentAttendance ? 'Stop Shift' : 'Start Shift' ?>
          </a>
          <div class="quick-link-row">
            <a class="quick-link-pill" href="?page=dashboard&section=guard-attendance">Shift details</a>
            <a class="quick-link-pill" href="?page=dashboard&section=guard-map">Live map</a>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="stats-row stats-row--guard" id="guard-overview-stats">
      <div class="stat-card card"><span>Shift state</span><strong><?= h($currentAttendance ? 'On Duty' : 'Standby') ?></strong><small><?= $currentAttendance ? 'Started ' . h(date('M j, Y g:i A', strtotime($currentAttendance['check_in_at']))) : 'Not checked in yet' ?></small></div>
      <div class="stat-card card">
        <span>Selfie proof</span>
        <strong><?= h(selfie_score_text($latestSelfie)) ?></strong>
        <small><?= $latestSelfie ? h(selfie_status_label($latestSelfie)) : 'Upload a new selfie to verify' ?></small>
      </div>
      <div class="stat-card card"><span>Live route</span><strong><?= count($locationRows) ?></strong><small><?= $locationRows ? 'Latest ping ' . h(date('g:i A', strtotime($locationRows[0]['tracked_at']))) : 'No GPS points saved yet' ?></small></div>
      <div class="stat-card card"><span>Hours today</span><strong><?= h($attendanceHoursToday . 'h ' . $attendanceRemainderMinutes . 'm') ?></strong><small><?= $attendanceRecordsToday ?> attendance records today</small></div>
    </section>
  <?php endif; ?>

  <?php if (guard_section_active($guardSection, 'guard-attendance')): ?>
    <section class="panel card guard-page-card guard-page-card--shift" id="guard-attendance-details">
      <div class="panel-head">
        <div>
          <h2><?= h($viewTitle) ?></h2>
          <p><?= h($viewSubtitle) ?></p>
        </div>
        <span class="badge"><?= $currentAttendance ? ($checkOutSelfie ? 'Checkout Ready' : 'On Duty') : ($checkInSelfie ? 'Selfie Ready' : 'Standby') ?></span>
      </div>

      <div class="guard-attendance-grid">
        <div class="subcard">
          <p class="subcard__title"><?= $currentAttendance ? 'Check-out selfie verification' : 'Check-in selfie verification' ?></p>
          <p class="helper"><?= $currentAttendance ? 'Capture a live selfie before ending the shift.' : 'Live selfie required before starting the shift.' ?></p>

          <?php if (!$activeSelfie): ?>
            <div class="field-summary">
              <span>Camera mode</span>
              <select id="camera-mode" class="app-input">
                <option value="user">Front selfie camera</option>
                <option value="environment">Back camera</option>
                <option value="default">Browser default camera</option>
              </select>
              <p>Use front camera for selfie capture. Switch if the device supports it.</p>
            </div>

            <div id="camera-wrap" class="camera-wrap">
              <video id="selfie-video" class="camera-preview" autoplay playsinline muted></video>
              <canvas id="selfie-canvas" class="sr-only"></canvas>
            </div>

            <div class="shift-actions">
              <button class="btn btn-outline btn-wide" type="button" onclick="startSelfieCamera()">Capture live selfie</button>
              <button class="btn btn-outline" type="button" onclick="captureSelfie()">Save selfie</button>
              <button class="btn btn-outline" type="button" onclick="stopSelfieCamera()">Cancel camera</button>
            </div>
          <?php else: ?>
            <div class="preview-ready preview-ready--shift">
              <div class="preview-ready__thumb">
                <img src="<?= h(asset_url($activeSelfie['image_path'])) ?>" alt="Captured selfie preview">
              </div>
              <div class="preview-ready__copy">
                <p class="preview-ready__eyebrow">Preview ready</p>
                <strong><?= $currentAttendance ? 'Check the image before stopping.' : 'Check the image before starting.' ?></strong>
                <p>Retake if the selfie is not clear or the lighting changed.</p>
              </div>
              <form method="post" class="preview-ready__action">
                <input type="hidden" name="action" value="clear_precheck_selfie">
                <button class="btn btn-outline btn-sm" type="submit">Retake</button>
              </form>
            </div>

            <div class="guard-confirm-grid guard-confirm-grid--shift">
              <div class="field-summary">
                <span>Duty zone</span>
                <select id="duty-zone" class="app-input" form="shift-submit-form" name="location_label">
                  <?php if ($dutySiteRows): ?>
                    <?php foreach ($dutySiteRows as $site): ?>
                      <option value="<?= h($site['name']) ?>"><?= h($site['name']) ?></option>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <option value="North Perimeter">North Perimeter</option>
                    <option value="Lobby">Lobby</option>
                    <option value="Control Room">Control Room</option>
                  <?php endif; ?>
                </select>
                <p>Managed by your admin.</p>
              </div>

              <div class="field-summary">
                <span>Shift note</span>
                <textarea id="shift-note" class="app-input" form="shift-submit-form" name="note" placeholder="Optional note before shift start."></textarea>
              </div>
            </div>

            <form method="post" id="shift-submit-form" class="shift-actions shift-submit-inline">
              <input type="hidden" name="action" value="guard_shift">
              <input type="hidden" name="mode" value="<?= $currentAttendance ? 'check_out' : 'check_in' ?>">
              <input type="hidden" name="organization_id" value="<?= (int)$orgId ?>">
              <input type="hidden" name="capture_phase" value="<?= h($capturePhase) ?>">
              <button class="btn btn-primary btn-wide" type="submit"><?= $currentAttendance ? 'Stop Shift' : 'Start Shift' ?></button>
              <a class="btn btn-outline" href="?page=dashboard&section=guard-map">Live map</a>
            </form>
          <?php endif; ?>
        </div>

        <div class="subcard subcard--preview">
          <p class="subcard__title">Latest verification snapshot</p>
          <div class="photo-placeholder photo-placeholder--shift">
            <?php if ($shiftPreviewImage): ?>
              <img src="<?= h(asset_url($shiftPreviewImage)) ?>" alt="Latest selfie" style="width:100%;height:100%;object-fit:cover;border-radius:16px;">
            <?php else: ?>
              <p>Attach a live selfie during check-in or check-out to create a verification trail.</p>
            <?php endif; ?>
          </div>
          <div class="shift-review-row">
            <div class="review-copy">
              <strong><?= $currentAttendance ? 'Checkout selfie captured for review' : 'Check-in selfie captured for review' ?></strong>
              <p>Identity review is visible to control room.</p>
              <small><?= h($activeSelfie['captured_at'] ?? ($latestSelfie['captured_at'] ?? 'Awaiting first shift')) ?></small>
            </div>
              <span class="<?= h(selfie_tone($activeSelfie ?: $latestSelfie)) ?>"><?= h(selfie_status_label($activeSelfie ?: $latestSelfie)) ?></span>
            </div>
          </div>
        </div>
    </section>
  <?php endif; ?>

  <?php if (guard_section_active($guardSection, 'guard-map')): ?>
    <section class="panel card guard-page-card guard-page-card--map" id="guard-map">
      <div class="panel-head">
        <div>
          <h2><?= h($viewTitle) ?></h2>
          <p><?= h($viewSubtitle) ?></p>
        </div>
      </div>
      <div class="leaflet-frame">
        <div id="leaflet-map" class="leaflet-map map-placeholder"></div>
      </div>
      <form method="post" id="save-location-form" class="shift-actions mt-4">
        <input type="hidden" name="action" value="save_location">
        <input type="hidden" name="latitude" id="location-latitude">
        <input type="hidden" name="longitude" id="location-longitude">
        <input type="hidden" name="accuracy" id="location-accuracy">
        <input type="hidden" name="address" id="location-address">
        <input type="hidden" name="duty_label" value="<?= h($user['shift_label'] ?: 'Field checkpoint') ?>">
        <button class="btn btn-primary" type="button" onclick="captureLocation()">Save current location</button>
        <button class="btn btn-outline" type="button" onclick="focusCurrentLocation()">Focus current location</button>
      </form>
      <div class="guard-map-grid">
        <div class="mini-stat">
          <span>Auto-sync status</span>
          <p id="location-status">Location not captured yet.</p>
        </div>
        <div class="mini-stat">
          <span>Live zone</span>
          <p><?= h($user['shift_label'] ?: 'Field checkpoint') ?></p>
          <small>Duty label</small>
          <p><?= h($user['shift_label'] ?: 'Field checkpoint') ?></p>
        </div>
        <div class="mini-stat">
          <span>Accuracy</span>
          <p>N/A</p>
        </div>
        <div class="mini-stat">
          <span>Position freshness</span>
          <p><?= !empty($locationRows) ? h(date('M j, Y g:i A', strtotime($locationRows[0]['tracked_at']))) : 'Unavailable' ?></p>
        </div>
      </div>
      <div class="timeline mt-4">
        <?php if ($locationRows): ?>
          <?php foreach ($locationRows as $location): ?>
            <div class="timeline-item">
              <span></span>
              <div>
                <strong><?= h($location['duty_label'] ?: 'Field checkpoint') ?></strong>
                <p><?= h($location['latitude']) ?>, <?= h($location['longitude']) ?></p>
                <small><?= h(date('M j, Y g:i A', strtotime($location['tracked_at']))) ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="muted">No route points have been saved yet.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (guard_section_active($guardSection, 'guard-history-page')): ?>
    <section class="panel card guard-page-card guard-page-card--history" id="guard-history-page">
      <div class="panel-head">
        <div>
          <h2><?= h($viewTitle) ?></h2>
          <p><?= h($viewSubtitle) ?></p>
        </div>
      </div>
      <div class="timeline">
        <?php if ($attendanceRows): ?>
          <?php foreach ($attendanceRows as $entry): ?>
            <div class="timeline-item">
              <span></span>
              <div>
                <strong><?= h($entry['check_out_at'] ? 'Completed shift' : 'Active shift') ?></strong>
                <p><?= h($entry['location_label'] ?: 'Field checkpoint') ?></p>
                <small><?= h(date('M j, Y g:i A', strtotime($entry['check_in_at']))) ?></small>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="muted">No attendance records have been logged today.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
</section>

<script>
let selfieStream = null;
let selfieData = "";
let guardLocationSyncTimer = null;

async function startSelfieCamera(surface = 'attendance') {
  const isOverview = surface === 'overview';
  const video = document.getElementById(isOverview ? 'selfie-video-overview' : 'selfie-video');
  const mode = document.getElementById(isOverview ? 'camera-mode-overview' : 'camera-mode')?.value || 'user';
  try {
    stopSelfieCamera();
    selfieStream = await navigator.mediaDevices.getUserMedia({
      video: mode === 'default' ? true : { facingMode: mode },
      audio: false
    });
    video.srcObject = selfieStream;
    await video.play();
  } catch (error) {
    alert('Camera access is required for live selfie capture.');
  }
}

function stopSelfieCamera() {
  if (selfieStream) {
    selfieStream.getTracks().forEach((track) => track.stop());
    selfieStream = null;
  }
  const video = document.getElementById('selfie-video');
  if (video) {
    video.srcObject = null;
  }
  const overviewVideo = document.getElementById('selfie-video-overview');
  if (overviewVideo) {
    overviewVideo.srcObject = null;
  }
}

async function captureSelfie() {
  const isOverview = Boolean(document.getElementById('selfie-video-overview')?.srcObject);
  const video = document.getElementById(isOverview ? 'selfie-video-overview' : 'selfie-video');
  const canvas = document.getElementById(isOverview ? 'selfie-canvas-overview' : 'selfie-canvas');
  if (!video || !video.videoWidth) {
    alert('Open the camera first.');
    return;
  }

  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
  selfieData = canvas.toDataURL('image/png');

  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = `
    <input type="hidden" name="action" value="capture_selfie">
    <input type="hidden" name="capture_phase" value="<?= h($capturePhase) ?>">
    <input type="hidden" name="image_data" value="${selfieData.replace(/"/g, '&quot;')}">
  `;
  document.body.appendChild(form);
  form.submit();
}

function syncGuardLocationSilently() {
  const locationStatus = document.getElementById('location-status');
  if (!navigator.geolocation) {
    if (locationStatus) locationStatus.textContent = 'Geolocation unavailable.';
    return;
  }

  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const accuracy = Math.round(pos.coords.accuracy || 0);
      const statusText = `Live position ready (${accuracy || 'N/A'} m)`;
      if (locationStatus) locationStatus.textContent = statusText;
      const latInput = document.getElementById('location-latitude');
      const lngInput = document.getElementById('location-longitude');
      const accInput = document.getElementById('location-accuracy');
      const addrInput = document.getElementById('location-address');
      if (latInput) latInput.value = lat;
      if (lngInput) lngInput.value = lng;
      if (accInput) accInput.value = accuracy;
      if (addrInput) addrInput.value = 'GPS location';
    },
    () => {
      if (locationStatus) locationStatus.textContent = 'Location permission was denied on this device.';
    },
    { enableHighAccuracy: true, timeout: 12000 }
  );
}

(function () {
  const shell = document.querySelector('[data-selfie-refresh="1"]');
  if (!shell) return;

  let refreshCount = 0;
  const refreshTimer = window.setInterval(() => {
    refreshCount += 1;
    window.location.reload();
    if (refreshCount >= 3) {
      window.clearInterval(refreshTimer);
    }
  }, 4000);
})();
</script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let guardMap = null;
let guardMarker = null;
const initialLocation = <?= json_encode($locationRows[0] ?? null) ?>;
const autoCamera = new URLSearchParams(window.location.search).get('auto_camera') === '1';

function initGuardMap() {
  const mapEl = document.getElementById('leaflet-map');
  if (!mapEl || guardMap) return;
  guardMap = L.map(mapEl).setView(
    [initialLocation?.latitude || 28.6139, initialLocation?.longitude || 77.209],
    initialLocation ? 15 : 5
  );
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(guardMap);
  if (initialLocation) {
    guardMarker = L.marker([initialLocation.latitude, initialLocation.longitude]).addTo(guardMap);
    guardMarker.bindPopup(initialLocation.duty_label || 'Current location').openPopup();
  }
}

function focusCurrentLocation() {
  initGuardMap();
  if (!navigator.geolocation) {
    document.getElementById('location-status').textContent = 'Geolocation unavailable.';
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const coords = [pos.coords.latitude, pos.coords.longitude];
      guardMap.setView(coords, 16);
      if (guardMarker) guardMarker.remove();
      guardMarker = L.marker(coords).addTo(guardMap).bindPopup('Current position');
      document.getElementById('location-status').textContent = 'Position ready to save.';
      document.getElementById('location-latitude').value = pos.coords.latitude;
      document.getElementById('location-longitude').value = pos.coords.longitude;
      document.getElementById('location-accuracy').value = Math.round(pos.coords.accuracy || 0);
    },
    () => {
      document.getElementById('location-status').textContent = 'Location permission was denied on this device.';
    },
    { enableHighAccuracy: true, timeout: 15000 }
  );
}

function captureLocation() {
  if (!navigator.geolocation) {
    alert('Geolocation is not available in this browser.');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      initGuardMap();
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      if (guardMarker) guardMarker.remove();
      guardMarker = L.marker([lat, lng]).addTo(guardMap).bindPopup('Saved location');
      document.getElementById('location-latitude').value = lat;
      document.getElementById('location-longitude').value = lng;
      document.getElementById('location-accuracy').value = Math.round(pos.coords.accuracy || 0);
      document.getElementById('location-address').value = 'GPS location';
      document.getElementById('location-status').textContent = 'Saving location...';
      document.getElementById('save-location-form')?.submit();
    },
    () => alert('Unable to read location. Please allow browser location access.'),
    { enableHighAccuracy: true, timeout: 15000 }
  );
}

window.addEventListener('load', initGuardMap);
window.addEventListener('load', () => {
  if (autoCamera && document.getElementById('selfie-video-overview')) {
    window.setTimeout(() => {
      startSelfieCamera('overview');
    }, 350);
  }
  if (document.getElementById('location-status')) {
    syncGuardLocationSilently();
    guardLocationSyncTimer = window.setInterval(syncGuardLocationSilently, 120000);
  }
});
window.addEventListener('beforeunload', () => {
  stopSelfieCamera();
  if (guardLocationSyncTimer) {
    window.clearInterval(guardLocationSyncTimer);
  }
});
</script>
