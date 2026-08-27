<?php
$adminSection = $_GET['section'] ?? 'admin-overview';
$organizationId = (int)($user['organization_id'] ?? 0);

function admin_table_exists(string $table): bool
{
    $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
    if ($safeTable === '') {
        return false;
    }

    $result = db()->query("SHOW TABLES LIKE '" . db()->real_escape_string($safeTable) . "'");
    return $result && $result->num_rows > 0;
}

function admin_fetch_rows(?string $sql, string $types = '', array $params = []): array
{
    if (!$sql) {
        return [];
    }

    $stmt = db()->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function relative_time(?string $datetime): string
{
    if (!$datetime) {
        return 'just now';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'just now';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'just now';
    }

    if ($diff < 3600) {
        return (int)floor($diff / 60) . 'm ago';
    }

    if ($diff < 86400) {
        return (int)floor($diff / 3600) . 'h ago';
    }

    return date('M j', $timestamp);
}

function format_coordinates($lat, $lng): string
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return 'Coordinates not set';
    }

    return number_format((float)$lat, 5) . ', ' . number_format((float)$lng, 5);
}

$stmt = db()->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $organizationId);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();

$orgDutyLabels = [];
if (!empty($org['duty_labels'])) {
    $decoded = json_decode($org['duty_labels'], true);
    if (is_array($decoded)) {
        $orgDutyLabels = array_values(array_filter($decoded, fn($l) => trim((string)$l) !== ''));
    }
}
if (empty($orgDutyLabels)) {
    $orgDutyLabels = ['Gate', 'Lobby', 'Warehouse', 'Perimeter', 'Ring'];
}

$stmt = db()->prepare("SELECT * FROM users WHERE role = 'guard' AND organization_id = ? ORDER BY full_name ASC");
$stmt->bind_param('i', $organizationId);
$stmt->execute();
$guards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = db()->prepare("SELECT * FROM duty_sites WHERE organization_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $organizationId);
$stmt->execute();
$dutySites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$customers = admin_table_exists('customers')
    ? admin_fetch_rows("SELECT * FROM customers WHERE organization_id = ? ORDER BY created_at DESC", 'i', [$organizationId])
    : [];

$customerLocations = admin_table_exists('customer_locations')
    ? admin_fetch_rows("SELECT * FROM customer_locations WHERE organization_id = ? ORDER BY created_at DESC", 'i', [$organizationId])
    : [];

$customerAssignments = admin_table_exists('customer_guard_assignments')
    ? admin_fetch_rows("SELECT * FROM customer_guard_assignments WHERE organization_id = ? ORDER BY created_at DESC", 'i', [$organizationId])
    : [];

$customerById = [];
foreach ($customers as $customer) {
    $customerById[(int)$customer['id']] = $customer;
}

$guardById = [];
foreach ($guards as $guard) {
    $guardById[(int)$guard['id']] = $guard;
}

$customerLocationById = [];
foreach ($customerLocations as $location) {
    $customerLocationById[(int)$location['id']] = $location;
}

$activeLocations = 0;
$coveredLocations = 0;
foreach ($customerLocations as $location) {
    if ((string)($location['status'] ?? '') === 'active') {
        $activeLocations++;
        foreach ($customerAssignments as $assignment) {
            if ((int)$assignment['customer_location_id'] === (int)$location['id'] && (string)($assignment['status'] ?? '') === 'active') {
                $coveredLocations++;
                break;
            }
        }
    }
}
$coverageRate = $activeLocations > 0 ? (int)round(($coveredLocations / $activeLocations) * 100) : 0;

$customerMetrics = [];
foreach ($customers as $customer) {
    $customerId = (int)$customer['id'];
    $customerLocationRows = array_values(array_filter($customerLocations, function ($location) use ($customerId) {
        return (int)($location['customer_id'] ?? 0) === $customerId;
    }));
    $customerAssignmentRows = array_values(array_filter($customerAssignments, function ($assignment) use ($customerLocationRows) {
        foreach ($customerLocationRows as $location) {
            if ((int)$assignment['customer_location_id'] === (int)$location['id']) {
                return true;
            }
        }
        return false;
    }));
    $latestActivityAt = null;
    foreach ($customerLocationRows as $location) {
        $candidate = $location['updated_at'] ?? $location['created_at'] ?? null;
        if ($candidate && (!$latestActivityAt || strtotime($candidate) > strtotime($latestActivityAt))) {
            $latestActivityAt = $candidate;
        }
    }
    foreach ($customerAssignmentRows as $assignment) {
        $candidate = $assignment['updated_at'] ?? $assignment['created_at'] ?? null;
        if ($candidate && (!$latestActivityAt || strtotime($candidate) > strtotime($latestActivityAt))) {
            $latestActivityAt = $candidate;
        }
    }
    $customerMetrics[$customerId] = [
        'locationCount' => count($customerLocationRows),
        'bookedGuardCount' => count(array_unique(array_map(static fn($assignment) => (int)$assignment['guard_id'], $customerAssignmentRows))),
        'lastActivityAt' => $latestActivityAt ?: ($customer['updated_at'] ?? $customer['created_at'] ?? null)
    ];
}

$today = date('Y-m-d');
$todayAttendance = admin_table_exists('attendance')
    ? admin_fetch_rows(
        "SELECT a.*, u.full_name
         FROM attendance a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.organization_id = ? AND DATE(a.check_in_at) = ?
         ORDER BY a.check_in_at DESC",
        'is',
        [$organizationId, $today]
    )
    : [];

$todayLocationRows = admin_table_exists('locations')
    ? admin_fetch_rows(
        "SELECT user_id, COUNT(*) AS ping_count
         FROM locations
         WHERE organization_id = ? AND DATE(tracked_at) = ?
         GROUP BY user_id",
        'is',
        [$organizationId, $today]
    )
    : [];

$openAttendanceRows = admin_table_exists('attendance')
    ? admin_fetch_rows(
        "SELECT user_id, id, check_in_at, location_label
         FROM attendance
         WHERE organization_id = ? AND check_out_at IS NULL
         ORDER BY check_in_at DESC",
        'i',
        [$organizationId]
    )
    : [];

$activeAttendanceByGuardId = [];
foreach ($openAttendanceRows as $attendanceRow) {
    $uid = (int)($attendanceRow['user_id'] ?? 0);
    if ($uid > 0 && !isset($activeAttendanceByGuardId[$uid])) {
        $activeAttendanceByGuardId[$uid] = $attendanceRow;
    }
}

$latestLocationRows = admin_table_exists('locations')
    ? admin_fetch_rows(
        "SELECT l.*, u.full_name, u.status AS guard_status
         FROM locations l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.organization_id = ?
         ORDER BY l.tracked_at DESC
         LIMIT 50",
        'i',
        [$organizationId]
    )
    : [];

$liveFeedRows = admin_table_exists('activities')
    ? admin_fetch_rows(
        "SELECT a.*, u.full_name
         FROM activities a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.organization_id = ?
         ORDER BY a.created_at DESC
         LIMIT 20",
        'i',
        [$organizationId]
    )
    : [];

$guardCount = count($guards);
$customerCount = count($customers);
$locationCount = count($customerLocations);
$assignmentCount = count($customerAssignments);
$onDutyCount = 0;
$trackedGuardCount = count($todayLocationRows);
$locationPingCount = 0;
$latestLat = null;
$latestLng = null;

foreach ($guards as $guard) {
    if ((string)($guard['status'] ?? '') === 'active' && isset($activeAttendanceByGuardId[(int)$guard['id']])) {
        $onDutyCount++;
    }
}

foreach ($todayLocationRows as $pingRow) {
    $locationPingCount += (int)($pingRow['ping_count'] ?? 0);
}

if ($latestLocationRows) {
    $latestLat = $latestLocationRows[0]['latitude'] ?? null;
    $latestLng = $latestLocationRows[0]['longitude'] ?? null;
}

$checkedInCount = count($todayAttendance);
$attendancePercent = $guardCount > 0 ? (int)round(($checkedInCount / $guardCount) * 100) : 0;
$activeDutySites = 0;
$assignedGuardCount = 0;

foreach ($dutySites as $site) {
    if ((string)($site['status'] ?? '') === 'active') {
        $activeDutySites++;
    }
}

foreach ($guards as $guard) {
    if (!empty($guard['duty_site_id'])) {
        $assignedGuardCount++;
    }
}

$masterTab = $_GET['master_tab'] ?? 'home';
$customersReady = admin_table_exists('customers');
$customerLocationsReady = admin_table_exists('customer_locations');
$customerAssignmentsReady = admin_table_exists('customer_guard_assignments');

$editGuardId = (int)($_GET['edit_guard'] ?? 0);
$editSiteId = (int)($_GET['edit_site'] ?? 0);
$editLocationId = (int)($_GET['edit_location'] ?? 0);
$guardEdit = null;
$siteEdit = null;
$locationEdit = null;

// --- Guard detail extra data ---
$guardSearch      = trim($_GET['guard_search'] ?? '');
$selectedGuardId  = (int)($_GET['edit_guard'] ?? 0);

// Filter guards by search
$filteredGuards = $guards;
if ($guardSearch !== '') {
    $sq = mb_strtolower($guardSearch);
    $filteredGuards = array_values(array_filter($guards, function ($g) use ($sq, $dutySites) {
        $siteName = '';
        foreach ($dutySites as $s) {
            if ((int)$s['id'] === (int)($g['duty_site_id'] ?? 0)) {
                $siteName = ($s['name'] ?? '') . ' ' . ($s['area'] ?? '');
                break;
            }
        }
        $hay = mb_strtolower(implode(' ', [
            $g['full_name'] ?? '',
            $g['email'] ?? '',
            $g['shift_label'] ?? '',
            $g['employee_code'] ?? '',
            $siteName
        ]));
        return str_contains($hay, $sq);
    }));
}

// Auto-select first if none chosen
if (!$selectedGuardId && $filteredGuards) {
    $selectedGuardId = (int)$filteredGuards[0]['id'];
}

// Build lookup map: site by guard
$siteByGuardId = [];
foreach ($guards as $g) {
    if (!empty($g['duty_site_id'])) {
        foreach ($dutySites as $s) {
            if ((int)$s['id'] === (int)$g['duty_site_id']) {
                $siteByGuardId[(int)$g['id']] = $s;
                break;
            }
        }
    }
}

// Tracked user ids today
$trackedUserIds = array_column($todayLocationRows, 'user_id');

// On-duty count in filtered
$onDutyFiltered  = count(array_filter($filteredGuards, fn($g) => (string)($g['status'] ?? '') === 'active' && isset($activeAttendanceByGuardId[(int)$g['id']])));
$trackedFiltered = count(array_filter($filteredGuards, fn($g) => in_array((string)$g['id'], array_map('strval', $trackedUserIds))));

// Selected guard full data
$detailGuard = null;
foreach ($guards as $g) {
    if ((int)$g['id'] === $selectedGuardId) { $detailGuard = $g; break; }
}

foreach ($guards as $guard) {
    if ((int)$guard['id'] === $editGuardId) {
        $guardEdit = $guard;
        break;
    }
}

foreach ($dutySites as $site) {
    if ((int)$site['id'] === $editSiteId) {
        $siteEdit = $site;
        break;
    }
}

foreach ($customerLocations as $location) {
    if ((int)$location['id'] === $editLocationId) {
        $locationEdit = $location;
        break;
    }
}

// Latest location for each guard
$latestLocByGuard = [];
foreach ($latestLocationRows as $lr) {
    $uid = (int)$lr['user_id'];
    if (!isset($latestLocByGuard[$uid])) {
        $latestLocByGuard[$uid] = $lr;
    }
}

// Selfies for selected guard (most recent first)
$guardSelfies = [];
$guardCheckInSelfies = [];
$guardCheckOutSelfies = [];
if ($detailGuard && admin_table_exists('selfies')) {
    $guardSelfies = admin_fetch_rows(
        "SELECT * FROM selfies WHERE user_id = ? ORDER BY captured_at DESC LIMIT 20",
        'i',
        [$selectedGuardId]
    );
    admin_ensure_selfies_capture_phase_column();
    $guardCheckInSelfies = admin_fetch_rows(
        "SELECT * FROM selfies WHERE user_id = ? AND capture_phase = 'check_in' ORDER BY captured_at DESC LIMIT 10",
        'i',
        [$selectedGuardId]
    );
    $guardCheckOutSelfies = admin_fetch_rows(
        "SELECT * FROM selfies WHERE user_id = ? AND capture_phase = 'check_out' ORDER BY captured_at DESC LIMIT 10",
        'i',
        [$selectedGuardId]
    );
}
 
// Attendance for selected guard (all time recent, used for detail panel trail)
$guardAttendanceAll = [];
if ($detailGuard && admin_table_exists('attendance')) {
    $guardAttendanceAll = admin_fetch_rows(
        "SELECT * FROM attendance WHERE user_id = ? ORDER BY check_in_at DESC LIMIT 5",
        'i',
        [$selectedGuardId]
    );
}
 
// Today attendance for selected guard
$guardTodayAttendance = array_values(array_filter(
    $todayAttendance,
    fn($a) => (int)($a['user_id'] ?? 0) === $selectedGuardId
));
 
// Activities for selected guard
$guardActivities = [];
if ($detailGuard && admin_table_exists('activities')) {
    $guardActivities = admin_fetch_rows(
        "SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
        'i',
        [$selectedGuardId]
    );
}
 
// Count stats for selected guard
$selectedGuardForMetrics = $detailGuard ?? ($filteredGuards[0] ?? null);
$selectedGuardIdForMetrics = (int)($selectedGuardForMetrics['id'] ?? 0);
$selectedDutySiteForMetrics = null;
if (!empty($selectedGuardForMetrics['duty_site_id'])) {
    foreach ($dutySites as $site) {
        if ((int)$site['id'] === (int)$selectedGuardForMetrics['duty_site_id']) {
            $selectedDutySiteForMetrics = $site;
            break;
        }
    }
}
$guardPingsToday = 0;
foreach ($todayLocationRows as $ping) {
    if ((int)$ping['user_id'] === $selectedGuardIdForMetrics) {
        $guardPingsToday = (int)$ping['ping_count'];
        break;
    }
}
$guardVerifiedSelfies = count(array_filter($guardSelfies, fn($s) => (string)($s['identity_status'] ?? '') === 'verified'));
$guardEventsToday = count(array_filter($guardActivities, fn($a) => date('Y-m-d', strtotime($a['created_at'])) === $today));
 
// Minutes on duty today
$guardDutyMinutesToday = 0;
foreach ($guardTodayAttendance as $att) {
    $inTs  = strtotime($att['check_in_at'] ?? '');
    $outTs = $att['check_out_at'] ? strtotime($att['check_out_at']) : time();
    if ($inTs) $guardDutyMinutesToday += max(0, (int)(($outTs - $inTs) / 60));
}

$selectedGuardOpenAttendance = $activeAttendanceByGuardId[$selectedGuardIdForMetrics] ?? null;
$isSelectedGuardOnDuty = $selectedGuardOpenAttendance !== null;
$selectedDutySiteLabel = $selectedGuardOpenAttendance['location_label'] ?? null;
if (!$selectedDutySiteLabel) {
    $selectedDutySiteLabel = $selectedDutySiteForMetrics
        ? trim((string)($selectedDutySiteForMetrics['name'] ?? '') . (!empty($selectedDutySiteForMetrics['area']) ? ' · ' . $selectedDutySiteForMetrics['area'] : ''))
        : (($selectedGuardForMetrics['shift_label'] ?? '') ?: 'Unassigned duty');
}
 
// Latest location freshness for selected guard
$detailGuardLoc = $latestLocByGuard[$selectedGuardId] ?? null;
$freshnessLabel = 'Awaiting GPS';
if ($detailGuardLoc) {
    $diffMins = (int)round((time() - strtotime($detailGuardLoc['tracked_at'])) / 60);
    $freshnessLabel = $diffMins < 1 ? 'Just now' : ($diffMins < 60 ? "{$diffMins}m ago" : floor($diffMins/60).'h ago');
}
 
// Helper: initials
function guard_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach ($parts as $p) { if ($p) $out .= strtoupper(substr($p, 0, 1)); }
    return substr($out, 0, 2) ?: 'G';
}
 
// Helper: format duration minutes
function fmt_duration(int $mins): string {
    if ($mins <= 0) return '0m';
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $h ? "{$h}h {$m}m" : "{$m}m";
}

?>

<section class="page-grid page-grid--admin">
  <?php if ($adminSection === 'admin-overview'): ?>
    <div id="admin-live-dashboard">
      <div class="stats-row stats-row--overview">
        <div class="stat-card card"><span>Guards</span><strong><?= $guardCount ?></strong><small>Total registered field users</small></div>
        <div class="stat-card card stat-card--jade"><span>On duty</span><strong><?= $onDutyCount ?></strong><small>Active open shifts</small></div>
        <div class="stat-card card stat-card--slate"><span>Attendance %</span><strong><?= $attendancePercent ?>%</strong><small><?= $checkedInCount ?>/<?= $guardCount ?> guards checked in today</small></div>
        <div class="stat-card card stat-card--gold"><span>Incidents</span><strong>0</strong><small>Alerts and incidents recorded today</small></div>
        <div class="stat-card card stat-card--jade"><span>Duty Sites</span><strong><?= $activeDutySites ?></strong><small><?= $assignedGuardCount ?>/<?= $guardCount ?> guards assigned</small></div>
        <div class="stat-card card stat-card--slate"><span>Tracked</span><strong><?= $trackedGuardCount ?></strong><small><?= $locationPingCount ?> location pings synced today</small></div>
      </div>

      <div class="panel card" style="margin-top:18px">
        <div class="panel-head">
          <div>
            <h2>Live Operations Feed</h2>
            <p>Attendance, activity, and location updates.</p>
          </div>
          <a class="btn btn-outline btn-sm" href="?page=admin&section=admin-feed">View more</a>
        </div>
        <div class="guard-list">
          <?php if ($liveFeedRows): ?>
            <?php foreach (array_slice($liveFeedRows, 0, 5) as $event): ?>
              <article class="guard-card" style="margin-top:0;margin-bottom:12px;">
                <div class="guard-card__head">
                  <div>
                    <strong><?= h($event['type'] ?? $event['event_type'] ?? 'Event') ?></strong>
                    <p><?= h($event['details'] ?? $event['description'] ?? $event['title'] ?? 'No extra detail.') ?></p>
                    <small><?= h(($event['full_name'] ?? 'System') . ' • ' . relative_time($event['created_at'] ?? null)) ?></small>
                  </div>
                  <span class="status-badge"><?= h($event['type'] ?? 'event') ?></span>
                </div>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="color:var(--muted);font-size:13px;">No live events are currently available.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel card" id="admin-overview">
        <div class="panel-head">
          <div>
            <h2>Operations Overview</h2>
            <p>Duty coverage, guard activity, and live assignment status.</p>
          </div>
        </div>
        <div class="coverage-grid">
          <?php foreach ($dutySites as $site): ?>
            <?php
              $siteGuardRows = array_values(array_filter($guards, function ($guard) use ($site) {
                return !empty($guard['duty_site_id']) && (int)$guard['duty_site_id'] === (int)$site['id'];
              }));
              $siteAssigned = count($siteGuardRows);
              $siteOnDuty = count(array_filter($siteGuardRows, function ($guard) {
                return (string)($guard['status'] ?? '') === 'active';
              }));
              $siteTracked = count(array_filter($todayLocationRows, function ($pingRow) use ($siteGuardRows) {
                foreach ($siteGuardRows as $guard) {
                    if ((int)$guard['id'] === (int)$pingRow['user_id']) {
                        return true;
                    }
                }
                return false;
              }));
              $guardChips = array_slice($siteGuardRows, 0, 6);
            ?>
          <article class="coverage-card card">
            <div class="coverage-card__head">
              <div>
                <h3><?= h($site['name']) ?></h3>
                <small class="eyebrow"><?= h($site['area'] ?? 'General area') ?></small>
              </div>
              <span class="status-badge"><?= h($site['status']) ?></span>
            </div>
            <div class="coverage-mini-grid">
              <div class="mini-stat"><span>Assigned</span><strong><?= $siteAssigned ?></strong><small>Guards</small></div>
              <div class="mini-stat"><span>On duty</span><strong><?= $siteOnDuty ?></strong><small>Active</small></div>
              <div class="mini-stat"><span>Tracked</span><strong><?= $siteTracked ?></strong><small>Today</small></div>
            </div>
            <div class="coverage-meta">
              <p><?= h($site['address'] ?? 'No address on file') ?></p>
              <p><?= h(trim((string)($site['latitude'] ?? '')) !== '' ? (string)$site['latitude'] : 'No latitude') ?>, <?= h(trim((string)($site['longitude'] ?? '')) !== '' ? (string)$site['longitude'] : 'No longitude') ?></p>
            </div>
            <div class="guard-chip-row">
              <?php if ($guardChips): ?>
                <?php foreach ($guardChips as $guard): ?>
                  <a class="guard-chip<?= (int)$editGuardId === (int)$guard['id'] ? ' is-active' : '' ?>" href="?page=admin&section=admin-guard-detail&edit_guard=<?= (int)$guard['id'] ?>"><?= h($guard['full_name']) ?></a>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="guard-chip guard-chip--empty">No guards assigned</span>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if (!$dutySites): ?>
          <div class="map-placeholder" style="min-height:240px">
            <div>No duty sites yet. Create the first site in <a href="?page=admin&section=admin-create-guard">Guard Setup</a>.</div>
          </div>
        <?php endif; ?>
        </div>
      </div>

      <div class="panel card" id="admin-attendance-snapshot" style="margin-top:18px;">
        <div class="panel-head">
          <div>
            <h2>Today's Attendance Snapshot</h2>
            <p>Today check-ins.</p>
          </div>
        </div>
        <div class="guard-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
          <?php if ($todayAttendance): ?>
            <?php foreach ($todayAttendance as $entry):
              $checkinTs = strtotime($entry['check_in_at'] ?? '');
              $checkoutTs = !empty($entry['check_out_at']) ? strtotime($entry['check_out_at']) : time();
              $durationMins = max(0, (int)(($checkoutTs - $checkinTs) / 60));
              $hours = intdiv($durationMins, 60);
              $mins = $durationMins % 60;
              $durationLabel = $hours ? "{$hours}h {$mins}m" : "{$mins}m";
            ?>
              <article class="guard-card">
                <div class="guard-card__head">
                  <div>
                    <strong><?= h($entry['full_name'] ?? 'Unknown') ?></strong>
                    <p><?= h($entry['location_label'] ?? 'Field checkpoint') ?></p>
                    <small>Check-in: <?= h(date('g:i A', strtotime($entry['check_in_at']))) ?></small>
                  </div>
                  <span class="status-badge"><?= !empty($entry['check_out_at']) ? 'Completed' : 'On Duty' ?></span>
                </div>
                <p style="margin-top:8px;font-size:13px;color:var(--body);">Hours completed: <?= h($durationLabel) ?></p>
                <?php if (!empty($entry['note'])): ?>
                  <p style="margin-top:8px;font-size:12px;color:var(--muted);padding:8px;background:var(--surface-soft);border-radius:10px;">Shift note: <?= h($entry['note']) ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="map-placeholder" style="min-height:240px">
              <div>No attendance records have been logged today.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <script>
    (function () {
      const refreshRoot = document.getElementById('admin-live-dashboard');
      if (!refreshRoot) return;

      let refreshTimer = null;
      let refreshInFlight = false;
      let lastScrollY = 0;

      async function refreshOverview() {
        if (refreshInFlight) return;
        refreshInFlight = true;
        lastScrollY = window.scrollY || window.pageYOffset || 0;

        try {
          const response = await fetch(window.location.href, { credentials: 'same-origin' });
          if (!response.ok) return;

          const html = await response.text();
          const doc = new DOMParser().parseFromString(html, 'text/html');
          const nextRoot = doc.getElementById('admin-live-dashboard');
          if (!nextRoot || !refreshRoot.parentNode) return;

          refreshRoot.outerHTML = nextRoot.outerHTML;
          window.scrollTo({ top: lastScrollY, left: window.scrollX || 0, behavior: 'auto' });
        } catch (error) {
          // Keep the existing view on refresh failure.
        } finally {
          refreshInFlight = false;
        }
      }

      const schedule = () => {
        window.clearInterval(refreshTimer);
        refreshTimer = window.setInterval(refreshOverview, 5000);
      };

      refreshOverview();
      schedule();
      window.addEventListener('focus', refreshOverview);
      window.addEventListener('online', refreshOverview);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          refreshOverview();
        }
      });
    })();
    </script>
    </div>
  <?php endif; ?>

  <?php if ($adminSection === 'admin-feed'): ?>
    <section class="panel card" id="admin-feed">
      <div class="panel-head">
        <div>
          <h2>Live Operations Feed</h2>
          <p>Attendance, activity, and location updates.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="?page=admin&section=admin-overview">Back to overview</a>
      </div>
      <div class="guard-list">
        <?php if ($liveFeedRows): ?>
          <?php foreach ($liveFeedRows as $event): ?>
            <article class="guard-card" style="margin-top:0;margin-bottom:12px;">
              <div class="guard-card__head">
                <div>
                  <strong><?= h($event['type'] ?? $event['event_type'] ?? 'Event') ?></strong>
                  <p><?= h($event['details'] ?? $event['description'] ?? $event['title'] ?? 'No extra detail.') ?></p>
                  <small><?= h(($event['full_name'] ?? 'System') . ' • ' . relative_time($event['created_at'] ?? null)) ?></small>
                </div>
                <span class="status-badge"><?= h($event['type'] ?? 'event') ?></span>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="color:var(--muted);font-size:13px;">No live events are currently available.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($adminSection === 'admin-master'): ?>
    <section class="panel card" id="admin-master">
      <div class="panel-head">
        <div>
          <h2>Master Control</h2>
          <p>Clients, sites, bookings, and guard setup</p>
        </div>
      </div>
      <?php
      $masterTabs = [
          ['id' => 'stats', 'label' => 'Stats', 'value' => $coverageRate . '%'],
          ['id' => 'customers', 'label' => 'Customers', 'value' => count($customers)],
          ['id' => 'sites', 'label' => 'Sites', 'value' => count($customerLocations)],
          ['id' => 'bookings', 'label' => 'Bookings', 'value' => count($customerAssignments)],
      ];
      ?>
      <div class="stats-row stats-row--master">
        <?php foreach ($masterTabs as $tab): ?>
          <a class="stat-card card master-nav-card<?= $masterTab === $tab['id'] ? ' is-active' : '' ?>" href="?page=admin&section=admin-master&master_tab=<?= h($tab['id']) ?>">
            <span><?= h($tab['label']) ?></span>
            <strong><?= h((string)$tab['value']) ?></strong>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($masterTab === 'stats'): ?>
        <section class="master-stats-panel">
          <div class="stats-row stats-row--overview">
            <div class="stat-card card stat-card--jade"><span>Customers</span><strong><?= count(array_filter($customers, fn($customer) => (string)($customer['status'] ?? '') === 'active')) ?></strong><small><?= count($customers) ?> total client records</small></div>
            <div class="stat-card card stat-card--gold"><span>Sites</span><strong><?= $activeLocations ?></strong><small><?= count($customerLocations) ?> total branches</small></div>
            <div class="stat-card card stat-card--slate"><span>Booked guards</span><strong><?= count(array_filter($customerAssignments, fn($assignment) => (string)($assignment['status'] ?? '') === 'active')) ?></strong><small><?= count($customerAssignments) ?> active bookings</small></div>
            <div class="stat-card card stat-card--jade"><span>Available</span><strong><?= max(0, $guardCount - count(array_filter($customerAssignments, fn($assignment) => (string)($assignment['status'] ?? '') === 'active'))) ?></strong><small>Active guards not booked to a customer</small></div>
            <div class="stat-card card stat-card--gold"><span>On duty</span><strong><?= $onDutyCount ?></strong><small><?= count($guards) ?> active guards</small></div>
            <div class="stat-card card stat-card--slate"><span>Coverage</span><strong><?= $coverageRate ?>%</strong><small><?= $coveredLocations ?>/<?= $activeLocations ?> active sites covered</small></div>
          </div>

          <div class="panel card" style="margin-top:18px">
            <div class="panel-head">
              <div>
                <p class="eyebrow">Master workspace</p>
                <h2>Clients, sites, bookings, and guard setup</h2>
                <p>Create the customer record first, add every customer site or branch, then book available guards to the exact branch.</p>
              </div>
            </div>
            <div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start;">
              <div class="guard-list" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
                <?php if ($customers): ?>
                  <?php foreach ($customers as $customer): ?>
                    <?php $metrics = $customerMetrics[(int)$customer['id']] ?? ['locationCount' => 0, 'bookedGuardCount' => 0, 'lastActivityAt' => null]; ?>
                    <article class="guard-card">
                      <div class="guard-card__head">
                        <div>
                          <strong><?= h($customer['name']) ?></strong>
                          <p style="margin:6px 0 0;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;"><?= h($customer['description'] ?: 'No description') ?></p>
                        </div>
                        <span class="status-badge"><?= h($customer['status']) ?></span>
                      </div>
                      <div class="coverage-mini-grid" style="margin-top:12px">
                        <div class="mini-stat"><span>Sites</span><strong><?= (int)$metrics['locationCount'] ?></strong><small>Linked locations</small></div>
                        <div class="mini-stat"><span>Guards</span><strong><?= (int)$metrics['bookedGuardCount'] ?></strong><small>Booked guards</small></div>
                        <div class="mini-stat"><span>Updated</span><strong><?= h(relative_time($metrics['lastActivityAt'])) ?></strong><small>Last activity</small></div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="map-placeholder" style="min-height:180px;grid-column:1 / -1;border-style:dashed">
                    <div>No customer records yet. Open the Customers tab to create the first record.</div>
                  </div>
                <?php endif; ?>
              </div>
              <div class="panel card" style="margin-top:0">
                <div class="stats-row" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                  <div class="mini-stat"><span>Open sites</span><strong><?= max(0, $activeLocations - $coveredLocations) ?></strong><small>Unassigned locations</small></div>
                  <div class="mini-stat"><span>Bookings</span><strong><?= count(array_filter($customerAssignments, fn($assignment) => (string)($assignment['status'] ?? '') === 'active')) ?></strong><small>Active guard bookings</small></div>
                </div>
              </div>
            </div>
          </div>
        </section>
      <?php elseif ($masterTab === 'customers'): ?>
        <section class="master-tab-panel">
          <div class="grid" style="grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:16px;align-items:start;">
            <form class="form-grid two" method="post">
              <input type="hidden" name="action" value="create_customer">
              <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
              <label class="full-row"><span>Customer name</span><input name="name" placeholder="Secure360 Client" required></label>
              <label class="full-row"><span>Description</span><textarea name="description" placeholder="Contract scope, notes, or billing reference"></textarea></label>
              <label class="full-row"><span>Status</span>
                <select name="status">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </label>
              <div class="full-row"><button class="btn btn-primary" type="submit">Save customer</button></div>
            </form>
            <div class="guard-list">
              <?php if ($customers): ?>
                <?php foreach ($customers as $customer): ?>
                  <?php $metrics = $customerMetrics[(int)$customer['id']] ?? ['locationCount' => 0, 'bookedGuardCount' => 0, 'lastActivityAt' => null]; ?>
                  <article class="guard-card">
                    <div class="guard-card__head">
                      <div>
                        <small style="display:block;text-transform:uppercase;letter-spacing:.24em;color:var(--muted);">Customer ID <?= (int)$customer['id'] ?></small>
                        <strong><?= h($customer['name']) ?></strong>
                        <p><?= h($customer['description'] ?: 'No customer description saved.') ?></p>
                        <small><?= h('Last updated ' . relative_time($metrics['lastActivityAt'])) ?></small>
                      </div>
                      <div style="display:grid;gap:8px;justify-items:end;">
                        <span class="status-badge"><?= h($customer['status']) ?></span>
                        <form method="post">
                          <input type="hidden" name="action" value="update_customer">
                          <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                          <input type="hidden" name="name" value="<?= h($customer['name']) ?>">
                          <input type="hidden" name="description" value="<?= h($customer['description'] ?? '') ?>">
                          <input type="hidden" name="status" value="<?= $customer['status'] === 'active' ? 'inactive' : 'active' ?>">
                          <button class="btn btn-outline btn-sm" type="submit"><?= $customer['status'] === 'active' ? 'Pause' : 'Activate' ?></button>
                        </form>
                      </div>
                    </div>
                    <div class="coverage-mini-grid" style="margin-top:12px">
                      <div class="mini-stat"><span>Sites</span><strong><?= (int)$metrics['locationCount'] ?></strong><small>Location count</small></div>
                      <div class="mini-stat"><span>Active</span><strong><?= (int)count(array_filter($customerLocations, fn($location) => (int)($location['customer_id'] ?? 0) === (int)$customer['id'] && (string)($location['status'] ?? '') === 'active')) ?></strong><small>Active location count</small></div>
                      <div class="mini-stat"><span>Guards</span><strong><?= (int)$metrics['bookedGuardCount'] ?></strong><small>Booked guards</small></div>
                      <div class="mini-stat"><span>Bookings</span><strong><?= (int)count(array_filter($customerAssignments, fn($assignment) => (int)($assignment['customer_id'] ?? 0) === (int)$customer['id'] && (string)($assignment['status'] ?? '') === 'active')) ?></strong><small>Assignment count</small></div>
                    </div>
                  </article>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="map-placeholder" style="min-height:220px;grid-column:1 / -1;border-style:dashed">
                  <div>No customer records yet. Create the first customer, then add their locations and guards.</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php elseif ($masterTab === 'sites'): ?>
        <section class="master-tab-panel">
          <div class="grid" style="grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:16px;align-items:start;">
            <form class="form-grid two" method="post">
              <input type="hidden" name="action" value="create_customer_location">
              <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
              <label class="full-row">
                <span>Customer</span>
                <select name="customer_id" required>
                  <option value="">Select customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['id'] ?>"><?= h($customer['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label><span>Site / branch name</span><input name="name" placeholder="Panjim Gate" required></label>
              <label><span>Area</span><input name="area" placeholder="Panjim"></label>
              <label class="full-row"><span>Address / detail</span><input name="address" placeholder="Main entrance, lobby, or guard post detail"></label>
              <label><span>Latitude</span><input name="latitude" type="number" step="0.000001" placeholder="28.613900"></label>
              <label><span>Longitude</span><input name="longitude" type="number" step="0.000001" placeholder="77.209000"></label>
              <label><span>Status</span>
                <select name="status">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </label>
              <div class="full-row"><button class="btn btn-primary" type="submit">Save site</button></div>
            </form>
            <div class="guard-list">
              <?php if ($customerLocations): ?>
                <?php foreach ($customerLocations as $location): ?>
                  <?php
                    $locationAssignments = array_values(array_filter($customerAssignments, fn($assignment) => (int)$assignment['customer_location_id'] === (int)$location['id']));
                    $activeLocationAssignments = array_values(array_filter($locationAssignments, fn($assignment) => (string)($assignment['status'] ?? '') === 'active'));
                    $assignmentChips = array_slice($locationAssignments, 0, 12);
                  ?>
                  <article class="guard-card">
                    <div class="guard-card__head">
                      <div>
                        <small style="display:block;text-transform:uppercase;letter-spacing:.24em;color:var(--muted);">Site ID <?= (int)$location['id'] ?></small>
                        <strong><?= h($location['name']) ?></strong>
                        <p><?= h(($customerById[(int)$location['customer_id']]['name'] ?? 'Unlinked customer')) ?></p>
                        <small><?= h(($location['area'] || $location['address']) ? trim(($location['area'] ?: '') . ($location['area'] && $location['address'] ? ' - ' : '') . ($location['address'] ?: '')) : 'No site detail saved') ?></small>
                      </div>
                      <div style="display:grid;gap:8px;justify-items:end;">
                        <span class="status-badge"><?= h($location['status']) ?></span>
                        <form method="post">
                          <input type="hidden" name="action" value="update_customer_location">
                          <input type="hidden" name="location_id" value="<?= (int)$location['id'] ?>">
                          <input type="hidden" name="customer_id" value="<?= (int)$location['customer_id'] ?>">
                          <input type="hidden" name="name" value="<?= h($location['name']) ?>">
                          <input type="hidden" name="area" value="<?= h($location['area'] ?? '') ?>">
                          <input type="hidden" name="address" value="<?= h($location['address'] ?? '') ?>">
                          <input type="hidden" name="latitude" value="<?= h((string)($location['latitude'] ?? '')) ?>">
                          <input type="hidden" name="longitude" value="<?= h((string)($location['longitude'] ?? '')) ?>">
                          <input type="hidden" name="status" value="<?= $location['status'] === 'active' ? 'inactive' : 'active' ?>">
                          <button class="btn btn-outline btn-sm" type="submit"><?= $location['status'] === 'active' ? 'Pause' : 'Activate' ?></button>
                        </form>
                      </div>
                    </div>
                    <div class="coverage-mini-grid" style="margin-top:12px">
                      <div class="mini-stat"><span>Customer ID</span><strong><?= (int)$location['customer_id'] ?></strong><small>Linked customer</small></div>
                      <div class="mini-stat"><span>Area</span><strong><?= h($location['area'] ?: 'Not set') ?></strong><small>Area</small></div>
                      <div class="mini-stat"><span>Booked</span><strong><?= count($activeLocationAssignments) ?></strong><small>Active assignments</small></div>
                      <div class="mini-stat"><span>Coordinates</span><strong><?= h(format_coordinates($location['latitude'] ?? null, $location['longitude'] ?? null)) ?></strong><small>lat, lng</small></div>
                    </div>
                    <div class="guard-chip-row">
                      <?php if ($assignmentChips): ?>
                        <?php foreach ($assignmentChips as $assignment): ?>
                          <button type="button" class="guard-chip" form="assignment-toggle-<?= (int)$assignment['id'] ?>" onclick="document.getElementById('assignment-toggle-<?= (int)$assignment['id'] ?>').requestSubmit()">
                            <?= h(($guardById[(int)$assignment['guard_id']]['full_name'] ?? 'Guard #' . (int)$assignment['guard_id'])) ?> - <?= h($assignment['status']) ?>
                          </button>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="guard-chip guard-chip--empty">No guard booked here</span>
                      <?php endif; ?>
                    </div>
                      <?php foreach ($assignmentChips as $assignment): ?>
                      <form id="assignment-toggle-<?= (int)$assignment['id'] ?>" method="post" style="display:none;">
                        <input type="hidden" name="action" value="update_customer_assignment">
                        <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
                        <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                        <input type="hidden" name="customer_id" value="<?= (int)$location['customer_id'] ?>">
                        <input type="hidden" name="customer_location_id" value="<?= (int)$location['id'] ?>">
                        <input type="hidden" name="status" value="<?= $assignment['status'] === 'active' ? 'inactive' : 'active' ?>">
                      </form>
                    <?php endforeach; ?>
                  </article>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php elseif ($masterTab === 'bookings'): ?>
        <section class="master-tab-panel">
          <div class="grid" style="grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:16px;align-items:start;">
            <form class="form-grid two" method="post">
              <input type="hidden" name="action" value="create_customer_assignment">
              <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
              <p class="eyebrow full-row">Booking</p>
              <h2 class="full-row" style="margin:0;">Guard booking</h2>
              <label class="full-row">
                <span>Customer</span>
                <select name="customer_id" required>
                  <option value="">Select customer</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['id'] ?>"><?= h($customer['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="full-row">
                <span>Customer location</span>
                <select name="customer_location_id" required>
                  <option value="">Select location</option>
                  <?php foreach ($customerLocations as $location): ?>
                    <option value="<?= (int)$location['id'] ?>" data-customer-id="<?= (int)$location['customer_id'] ?>">
                      <?= h(($customerById[(int)$location['customer_id']]['name'] ?? 'Unlinked customer') . ' - ' . $location['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="full-row">
                <span>Guard</span>
                <select name="guard_id" required>
                  <option value="">Select guard</option>
                  <?php foreach ($guards as $guard): ?>
                    <?php
                      $isBooked = false;
                      $editingAssignmentId = (int)($_GET['edit_assignment'] ?? 0);
                      foreach ($customerAssignments as $assignment) {
                          if ((int)$assignment['guard_id'] === (int)$guard['id'] && (string)($assignment['status'] ?? '') === 'active' && (int)$assignment['id'] !== $editingAssignmentId) {
                              $isBooked = true;
                              break;
                          }
                      }
                    ?>
                    <option value="<?= (int)$guard['id'] ?>"<?= $isBooked ? ' disabled' : '' ?>><?= h($guard['full_name'] . ($isBooked ? ' (Booked)' : '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="full-row"><span>Notes</span><textarea name="notes" placeholder="Shift coverage, timing, or client instruction"></textarea></label>
              <div class="full-row"><button class="btn btn-primary" type="submit">Save booking</button></div>
            </form>
            <script>
            (function(){
              const custSel = document.querySelector('[name="customer_id"]');
              const locSel = document.querySelector('[name="customer_location_id"]');
              if(!custSel || !locSel) return;
              function filterLocs(){
                const cid = custSel.value;
                Array.from(locSel.options).forEach(opt => {
                  if(!opt.value) return;
                  opt.hidden = cid && opt.dataset.customerId !== cid;
                });
                if(locSel.selectedOptions[0]?.hidden) locSel.value = '';
              }
              custSel.addEventListener('change', filterLocs);
              filterLocs();
            })();
            </script>
            <div class="guard-list">
              <?php if ($customerAssignments): ?>
                <?php foreach ($customerAssignments as $assignment): ?>
                  <?php
                    $assignmentCustomer = $customerById[(int)$assignment['customer_id']] ?? null;
                    $assignmentLocation = $customerLocationById[(int)$assignment['customer_location_id']] ?? null;
                    $assignmentGuard = $guardById[(int)$assignment['guard_id']] ?? null;
                    $assignmentTitle = $assignmentGuard['full_name'] ?? 'Unknown guard';
                    $assignmentContext = trim(($assignmentCustomer['name'] ?? 'Unknown customer') . ' - ' . ($assignmentLocation['name'] ?? 'Unknown site'));
                  ?>
                  <form method="post" class="guard-card<?= (string)($assignment['status'] ?? '') === 'active' ? ' guard-card--active' : '' ?>" style="margin-top:18px;padding:16px;border:1px solid var(--line);border-radius:22px;background:<?= (string)($assignment['status'] ?? '') === 'active' ? 'rgba(71,216,162,.12)' : 'rgba(255,255,255,.68)' ?>;cursor:pointer" onclick="if(event.target.closest('button,input,select,textarea,a,label')) return; this.requestSubmit();">
                    <input type="hidden" name="action" value="update_customer_assignment">
                    <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                    <input type="hidden" name="status" value="<?= (string)($assignment['status'] ?? '') === 'active' ? 'inactive' : 'active' ?>">
                    <div class="guard-card__head">
                      <div>
                        <small style="display:block;text-transform:uppercase;letter-spacing:.24em;color:var(--muted);">Booking ID <?= (int)$assignment['id'] ?></small>
                        <strong><?= h($assignmentTitle) ?></strong>
                        <p><?= h($assignmentContext) ?></p>
                        <small><?= h($assignment['notes'] ?: 'No booking notes saved.') ?></small>
                      </div>
                      <span class="status-badge"><?= h($assignment['status']) ?></span>
                    </div>
                    <div class="coverage-mini-grid" style="margin-top:12px">
                      <div class="mini-stat"><span>Guard ID</span><strong><?= (int)$assignment['guard_id'] ?></strong><small>Guard ID: <?= (int)$assignment['guard_id'] ?></small></div>
                      <div class="mini-stat"><span>Site ID</span><strong><?= (int)$assignment['customer_location_id'] ?></strong><small>Site ID: <?= (int)$assignment['customer_location_id'] ?></small></div>
                      <div class="mini-stat"><span>Assigned</span><strong><?= h(relative_time($assignment['updated_at'] ?? $assignment['created_at'] ?? null)) ?></strong><small>Assigned at</small></div>
                    </div>
                    <div class="guard-card__meta" style="margin-top:12px;">
                      <span><?= h($assignment['notes'] ?: 'No booking notes saved.') ?></span>
                    </div>
                  </form>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($adminSection === 'admin-map'): ?>
    <section class="panel card" id="admin-map">
      <div class="panel-head">
        <div>
          <h2>Operations Live Map</h2>
          <p>GuardPositionMap with latest saved lat/lng positions and quick refresh.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="?page=admin&section=admin-map">Refresh locations</a>
      </div>
      <div class="guard-map-shell">
        <div class="guard-map-canvas" id="guard-position-map" data-map-lat="<?= h((string)($latestLat ?? '')) ?>" data-map-lng="<?= h((string)($latestLng ?? '')) ?>">
          <div class="map-placeholder guard-map-fallback">
            <div>
              <strong>Map library not wired</strong>
              <p>Showing the latest guard locations as a live list.</p>
            </div>
          </div>
        </div>

        <div class="guard-map-sidebar">
          <div class="panel-head">
            <div>
              <h2>Guard Selector</h2>
              <p>Click a guard to highlight their latest saved position.</p>
            </div>
          </div>
          <div class="guard-chip-row guard-chip-row--map" id="guard-map-selector">
            <?php foreach ($guards as $guard): ?>
              <button type="button" class="guard-chip" data-guard-target="<?= (int)$guard['id'] ?>"><?= h($guard['full_name']) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="guard-map-list" id="guard-map-list">
            <?php if ($latestLocationRows): ?>
              <?php foreach ($latestLocationRows as $locationRow): ?>
                <article class="guard-map-row" data-guard-row="<?= (int)$locationRow['user_id'] ?>" data-lat="<?= h((string)$locationRow['latitude']) ?>" data-lng="<?= h((string)$locationRow['longitude']) ?>">
                  <div class="guard-map-row__head">
                    <strong><?= h($locationRow['full_name'] ?? ('Guard #' . (int)$locationRow['user_id'])) ?></strong>
                    <span class="status-badge"><?= h($locationRow['guard_status'] ?? 'active') ?></span>
                  </div>
                  <div class="guard-map-row__meta">
                    <span><?= h(number_format((float)$locationRow['latitude'], 6)) ?>, <?= h(number_format((float)$locationRow['longitude'], 6)) ?></span>
                    <span><?= h(date('M j, Y g:i A', strtotime($locationRow['tracked_at']))) ?></span>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="map-placeholder" style="min-height:220px">
                <div>No guard location pings saved yet.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <script>
      (function () {
        const mapEl = document.getElementById('guard-position-map');
        const selector = document.getElementById('guard-map-selector');
        const rows = Array.from(document.querySelectorAll('[data-guard-row]'));
        if (!mapEl || !rows.length) return;

        const fallback = mapEl.querySelector('.guard-map-fallback');

        // Collect all guard markers with valid coordinates
        const markers = rows
          .map(row => ({
            id: row.dataset.guardRow,
            lat: parseFloat(row.dataset.lat),
            lng: parseFloat(row.dataset.lng),
            name: row.querySelector('strong')?.textContent || 'Guard',
            el: row
          }))
          .filter(m => !isNaN(m.lat) && !isNaN(m.lng));

        if (!markers.length) {
          if (fallback) fallback.querySelector('p').textContent = 'No guard GPS coordinates saved yet.';
          return;
        }

        if (fallback) fallback.remove();

        // Center on first marker
        const center = [markers[0].lat, markers[0].lng];
        const map = window.L.map(mapEl).setView(center, 14);

        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const leafletMarkers = {};
        markers.forEach(m => {
          const marker = window.L.marker([m.lat, m.lng])
            .addTo(map)
            .bindPopup('<strong>' + m.name + '</strong><br>' + m.lat.toFixed(5) + ', ' + m.lng.toFixed(5));
          leafletMarkers[m.id] = marker;
        });

        let activeId = null;
        function setActive(id) {
          activeId = String(id);
          rows.forEach(row => row.classList.toggle('is-active', row.dataset.guardRow === activeId));
          if (selector) {
            selector.querySelectorAll('[data-guard-target]').forEach(chip => {
              chip.classList.toggle('is-active', chip.dataset.guardTarget === activeId);
            });
          }
          const activeRow = rows.find(row => row.dataset.guardRow === activeId);
          if (activeRow) activeRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          const m = leafletMarkers[activeId];
          if (m) { map.setView(m.getLatLng(), 15); m.openPopup(); }
        }

        if (selector) {
          selector.addEventListener('click', e => {
            const btn = e.target.closest('[data-guard-target]');
            if (btn) setActive(btn.dataset.guardTarget);
          });
        }

        rows.forEach(row => row.addEventListener('click', () => setActive(row.dataset.guardRow)));

        setActive(markers[0].id);
      })();
      </script>
    </section>
  <?php endif; ?>

  <?php if ($adminSection === 'admin-guard-detail'): ?>
    <section class="panel card" id="admin-guard-detail">
      <div class="panel-head">
        <div>
          <h2>Guard Lookup</h2>
          <p>Find guards, view details, selfie verification and shift records.</p>
        </div>
      </div>

      <?php
      $forceNoSelectedGuard = !empty($_GET['clear_guard']);
      $selectedGuard = $forceNoSelectedGuard ? null : $detailGuard;
      if (!$selectedGuard && $filteredGuards && !$forceNoSelectedGuard) {
          $selectedGuard = $filteredGuards[0];
      }
        $selectedGuardId = (int)($selectedGuard['id'] ?? 0);
        $selectedDutySite = null;
        if (!empty($selectedGuard['duty_site_id'])) {
            foreach ($dutySites as $site) {
                if ((int)$site['id'] === (int)$selectedGuard['duty_site_id']) {
                    $selectedDutySite = $site;
                    break;
                }
            }
        }
        $selectedGuardOpenAttendance = $activeAttendanceByGuardId[$selectedGuardId] ?? null;
        $selectedDutySiteLabel = !empty($selectedGuardOpenAttendance['location_label'])
            ? (string)$selectedGuardOpenAttendance['location_label']
            : ($selectedDutySite
                ? trim((string)($selectedDutySite['name'] ?? '') . (!empty($selectedDutySite['area']) ? ' · ' . $selectedDutySite['area'] : ''))
                : ($selectedGuard['shift_label'] ?: 'Unassigned duty'));
        $isSelectedGuardOnDuty = $selectedGuardOpenAttendance !== null;
        $selectedGuardAvatar = !empty($selectedGuard['identity_photo_path'])
            ? $selectedGuard['identity_photo_path']
            : (!empty($selectedGuard['identity_selfie_path']) ? $selectedGuard['identity_selfie_path'] : '');
      ?>

      <form method="get" class="guard-detail-toolbar" style="display:flex;gap:12px;align-items:stretch;flex-wrap:wrap;margin-bottom:20px;">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="section" value="admin-guard-detail">
        <input
          name="guard_search"
          value="<?= h($guardSearch) ?>"
          placeholder="Search guard name, employee code, duty site, or location..."
          style="flex:1;min-width:220px;padding:12px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-soft);font-size:14px;color:var(--body);"
        >
        <div class="guard-detail-toolbar__stats" style="display:flex;gap:8px;flex-wrap:wrap;">
          <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-soft);min-width:72px;text-align:center;">
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--muted);">Results</span>
            <strong style="font-size:20px;"><?= count($filteredGuards) ?></strong>
          </div>
          <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-soft);min-width:72px;text-align:center;">
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--muted);">On Duty</span>
            <strong style="font-size:20px;"><?= $onDutyFiltered ?></strong>
          </div>
          <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 16px;border-radius:14px;border:1px solid var(--line);background:var(--surface-soft);min-width:72px;text-align:center;">
            <span style="font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--muted);">Tracked</span>
            <strong style="font-size:20px;"><?= $trackedFiltered ?></strong>
          </div>
        </div>
        <button class="btn btn-outline" type="submit" style="padding:12px 20px;border-radius:14px;">Search</button>
      </form>

      <?php if ($selectedGuard): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:20px;border:1px solid rgba(71,216,162,.20);background:rgba(71,216,162,.10);margin-bottom:16px;flex-wrap:wrap;">
          <div style="width:48px;height:48px;border-radius:14px;flex-shrink:0;overflow:hidden;background:linear-gradient(135deg,var(--jade),var(--line));display:grid;place-items:center;color:#0f172a;font-weight:800;">
            <?php if ($selectedGuardAvatar): ?>
              <img src="<?= h(asset_url($selectedGuardAvatar)) ?>" alt="<?= h($selectedGuard['full_name'] ?? 'Guard') ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php else: ?>
              <?= h(guard_initials($selectedGuard['full_name'] ?? 'G')) ?>
            <?php endif; ?>
          </div>
          <div style="min-width:0;flex:1;">
            <strong style="display:block;font-size:15px;line-height:1.2;"><?= h($selectedGuard['full_name'] ?? 'Selected guard') ?></strong>
            <span style="display:block;font-size:13px;color:var(--muted);margin-top:3px;"><?= h($selectedDutySiteLabel) ?></span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
            <span class="status-badge"><?= h(ucfirst((string)($selectedGuard['status'] ?? 'active'))) ?></span>
            <span class="status-badge"><?= $isSelectedGuardOnDuty ? 'On Duty' : 'Off Duty' ?></span>
          </div>
        </div>
      <?php endif; ?>
 
      <div class="guard-detail-directory" style="margin-bottom:20px;border:1px solid var(--line);background:var(--surface-soft);border-radius:22px;padding:16px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
          <div>
            <p style="margin:0;font-size:11px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Guard Directory</p>
            <h3 style="margin:4px 0 0;font-size:18px;line-height:1.15;color:var(--body);">Roster, access, and location</h3>
          </div>
          <span class="status-badge"><?= count($filteredGuards) ?> guards</span>
        </div>
        <?php if ($filteredGuards): ?>
          <div class="guard-detail-quick-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">
            <?php foreach (array_slice($filteredGuards, 0, 4) as $guard):
              $gId = (int)$guard['id'];
              $gSite = null;
              if (!empty($guard['duty_site_id'])) {
                  foreach ($dutySites as $site) {
                      if ((int)$site['id'] === (int)$guard['duty_site_id']) {
                          $gSite = $site;
                          break;
                      }
                  }
              }
              $gLoc = $latestLocByGuard[$gId] ?? null;
              $gSelected = $gId === $selectedGuardId;
              $gTracked = !empty($gLoc);
              $gEnrolled = !empty($guard['identity_photo_path']) || !empty($guard['identity_selfie_path']);
              $guardAvatar = !empty($guard['identity_photo_path']) ? $guard['identity_photo_path'] : '';
              $guardDutyLabel = $gSite
                  ? trim((string)($gSite['name'] ?? '') . (!empty($gSite['area']) ? ' · ' . $gSite['area'] : ''))
                  : ($guard['shift_label'] ?: 'Unassigned duty');
              $viewUrl = '?page=admin&section=admin-guard-detail&edit_guard=' . $gId . '&guard_search=' . urlencode($guardSearch);
              $editUrl = '?page=admin&section=admin-create-guard&edit_guard=' . $gId;
            ?>
              <article class="guard-quick-card" style="border-radius:20px;border:1px solid <?= $gSelected ? 'rgba(71,216,162,.45)' : 'rgba(255,255,255,.10)' ?>;background:<?= $gSelected ? 'rgba(71,216,162,.10)' : 'rgba(255,255,255,.05)' ?>;box-shadow:<?= $gSelected ? '0 18px 48px rgba(45,212,191,.14)' : 'none' ?>;padding:14px;">
                <a href="<?= h($viewUrl) ?>" style="display:block;text-decoration:none;color:inherit;">
                  <div style="display:flex;align-items:flex-start;gap:10px;">
                    <div style="width:56px;height:56px;border-radius:20px;flex-shrink:0;overflow:hidden;background:linear-gradient(135deg,#f9d02c,#47d8a2);display:grid;place-items:center;color:#020617;font-weight:800;font-size:15px;">
                      <?php if ($guardAvatar): ?>
                        <img src="<?= h(asset_url($guardAvatar)) ?>" alt="<?= h($guard['full_name']) ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                      <?php else: ?>
                        <?= h(guard_initials($guard['full_name'] ?? 'G')) ?>
                      <?php endif; ?>
                    </div>
                    <div style="min-width:0;flex:1;">
                      <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                        <div style="min-width:0;">
                          <strong style="display:block;font-size:14px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"><?= h($guard['full_name'] ?? 'Unknown') ?></strong>
                          <small style="display:block;margin-top:3px;color:var(--muted);font-size:11px;"><?= h($guard['employee_code'] ?: 'No employee code') ?></small>
                        </div>
                        <span class="status-badge"><?= h(ucfirst((string)($guard['status'] ?? 'active'))) ?></span>
                      </div>
                      <p style="font-size:13px;margin:6px 0 0;color:var(--body);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($guardDutyLabel) ?></p>
                      <p style="font-size:12px;margin:3px 0 0;color:var(--muted);"><?= $gTracked ? 'Tracked today' : 'No live location yet' ?></p>
                    </div>
                  </div>
                </a>
                <div class="guard-quick-meta-grid" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0;">
                  <div style="border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);border-radius:16px;padding:8px 12px;">
                    <span style="display:block;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--muted);">Location</span>
                    <strong style="display:block;margin-top:4px;font-size:12px;"><?= $gTracked ? 'Synced today' : 'Awaiting sync' ?></strong>
                  </div>
                  <div style="border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.05);border-radius:16px;padding:8px 12px;">
                    <span style="display:block;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--muted);">Identity</span>
                    <strong style="display:block;margin-top:4px;font-size:12px;"><?= $gEnrolled ? 'Enrolled' : 'Missing' ?></strong>
                  </div>
                </div>
                <div class="guard-quick-actions" style="display:grid;gap:8px;">
                  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                    <a class="btn btn-primary btn-sm" href="<?= h($viewUrl) ?>" style="width:100%;text-align:center;padding:8px 10px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">View details</a>
                    <a class="btn btn-outline btn-sm" href="<?= h($editUrl) ?>" style="width:100%;text-align:center;padding:8px 10px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Edit</a>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="action" value="update_guard">
                      <input type="hidden" name="guard_id" value="<?= $gId ?>">
                      <input type="hidden" name="full_name" value="<?= h($guard['full_name'] ?? '') ?>">
                      <input type="hidden" name="email" value="<?= h($guard['email'] ?? '') ?>">
                      <input type="hidden" name="phone" value="<?= h($guard['phone'] ?? '') ?>">
                      <input type="hidden" name="employee_code" value="<?= h($guard['employee_code'] ?? '') ?>">
                      <input type="hidden" name="shift_label" value="<?= h($guard['shift_label'] ?? '') ?>">
                      <input type="hidden" name="status" value="<?= (string)($guard['status'] ?? '') === 'active' ? 'inactive' : 'active' ?>">
                      <button class="btn btn-outline btn-sm btn-full" type="submit" style="width:100%;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= (string)($guard['status'] ?? '') === 'active' ? 'Suspend access' : 'Reactivate access' ?></button>
                    </form>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Delete this guard permanently?');">
                      <input type="hidden" name="action" value="delete_guard">
                      <input type="hidden" name="guard_id" value="<?= $gId ?>">
                      <button class="btn btn-outline btn-sm btn-full" type="submit" style="width:100%;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-color:#ef4444;color:#ef4444;">Delete guard</button>
                    </form>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="border:1px dashed var(--line);border-radius:18px;padding:24px;text-align:center;color:var(--muted);font-size:14px;margin-bottom:20px;">
            No guard matches this search. Try name, employee code, duty site, or area.
          </div>
        <?php endif; ?>
      </div>
 
  <!-- ── GUARD FULL DETAIL PANEL ── -->
  <?php if ($selectedGuard): ?>
    <?php
      $detailSite = $detailSite ?? null;
      if (!$detailSite && !empty($selectedGuard['duty_site_id'])) {
          foreach ($dutySites as $site) {
              if ((int)$site['id'] === (int)$selectedGuard['duty_site_id']) {
                  $detailSite = $site;
                  break;
              }
          }
      }
      $guardAttendanceToday = $guardAttendanceToday ?? [];
      $detailLocation = $detailLocation ?? ($detailGuardLoc ?? null);
      $guardHoursMins = 0;
      foreach ($guardAttendanceToday as $att) {
          $inTs = strtotime($att['check_in_at'] ?? '');
          $outTs = !empty($att['check_out_at']) ? strtotime($att['check_out_at']) : time();
          if ($inTs) {
              $guardHoursMins += max(0, (int)(($outTs - $inTs) / 60));
          }
      }
      $detailAvatar = !empty($selectedGuard['identity_photo_path'])
          ? $selectedGuard['identity_photo_path']
          : (!empty($selectedGuard['identity_selfie_path']) ? $selectedGuard['identity_selfie_path'] : '');
      $detailOnDuty = !empty($selectedGuard['duty_site_id']);
      $detailLastSeen = $detailLocation['tracked_at'] ?? null;
      $detailDutySiteName = $detailSite['name'] ?? 'Unassigned';
      $detailDutySiteArea = $detailSite['area'] ?? '';
    ?>

    <div style="border:1px solid var(--line);background:linear-gradient(135deg,rgba(255,255,255,0.10),rgba(255,255,255,0.05));border-radius:26px;padding:20px;margin-top:16px;">
      <div style="display:flex;gap:16px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
        <div style="display:flex;gap:16px;align-items:flex-start;min-width:0;flex:1;">
          <div style="width:80px;height:80px;border-radius:26px;flex-shrink:0;overflow:hidden;background:linear-gradient(135deg,#f9d02c,#47d8a2);box-shadow:0 0 32px rgba(249,208,44,0.25);display:grid;place-items:center;color:#020617;font-size:24px;font-weight:600;">
            <?php if (!empty($detailAvatar)): ?>
                <img src="<?= h(asset_url($detailAvatar)) ?>" alt="<?= h($selectedGuard['full_name'] ?? 'Guard') ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php else: ?>
              <?= h(guard_initials($selectedGuard['full_name'] ?? 'G')) ?>
            <?php endif; ?>
          </div>
          <div style="min-width:0;">
            <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Selected guard</p>
            <h3 style="margin:0;font-size:26px;line-height:1.15;color:var(--body);"><?= h($selectedGuard['full_name'] ?? 'Selected guard') ?></h3>
            <p style="margin:6px 0 0;font-size:13px;color:var(--muted);"><?= h($selectedGuard['email'] ?? 'No email assigned') ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
              <span class="status-badge"><?= h(ucfirst((string)($selectedGuard['status'] ?? 'active'))) ?></span>
              <span class="status-badge"><?= $detailOnDuty ? 'On Duty' : 'Off Duty' ?></span>
              <span class="status-badge"><?= !empty($selectedGuard['identity_photo_path']) || !empty($selectedGuard['identity_selfie_path']) ? 'Identity enrolled' : 'No reference' ?></span>
            </div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <a class="btn btn-primary btn-sm" href="?page=admin&section=admin-create-guard&edit_guard=<?= (int)$selectedGuard['id'] ?>" style="text-align:center;padding:8px 10px;">Edit</a>
          <a class="btn btn-outline btn-sm" href="?page=admin&section=admin-guard-detail&clear_guard=1&guard_search=<?= urlencode($guardSearch) ?>" style="text-align:center;padding:8px 10px;">Close</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;min-width:min(100%,320px);flex:0 0 320px;">
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
            <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Shift label</p>
            <p style="margin:6px 0 0;font-size:13px;color:var(--body);"><?= h($selectedGuard['shift_label'] ?: 'Unassigned') ?></p>
          </div>
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
            <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Duty site</p>
            <p style="margin:6px 0 0;font-size:13px;color:var(--body);"><?= h($detailDutySiteName . ($detailDutySiteArea ? ' · ' . $detailDutySiteArea : '')) ?></p>
          </div>
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
            <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Employee code</p>
            <p style="margin:6px 0 0;font-size:13px;color:var(--body);"><?= h($selectedGuard['employee_code'] ?: 'Not assigned') ?></p>
          </div>
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
            <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Last seen</p>
            <p style="margin:6px 0 0;font-size:13px;color:var(--body);"><?= !empty($detailLastSeen) ? h(relative_time($detailLastSeen)) : 'No recent sync' ?></p>
          </div>
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;grid-column:1 / -1;">
            <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Hours today</p>
            <p style="margin:6px 0 0;font-size:13px;color:var(--body);"><?= (int)floor($guardHoursMins / 60) ?>h <?= (int)($guardHoursMins % 60) ?>m</p>
          </div>
        </div>
      </div>
    </div>

    <div class="guard-detail-shell" style="display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:16px;margin-top:16px;align-items:start;">
      <div style="display:flex;flex-direction:column;gap:16px;">
        <div style="border:1px solid var(--line);background:var(--surface-soft);border-radius:22px;padding:16px;">
          <p style="margin:0 0 12px;font-size:13px;font-weight:500;color:var(--body);">📍 Live route view</p>
          <div id="guard-detail-map"
               data-lat="<?= h((string)($detailLocation['latitude'] ?? '')) ?>"
               data-lng="<?= h((string)($detailLocation['longitude'] ?? '')) ?>"
               data-guard-name="<?= h($selectedGuard['full_name'] ?? 'Guard') ?>"
               style="height:260px;border-radius:16px;background:linear-gradient(180deg,rgba(255,255,255,0.7),rgba(255,255,255,0.55));border:1px solid var(--line);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;overflow:hidden;">
            <?= !empty($detailLocation['latitude']) && !empty($detailLocation['longitude']) ? 'Map loading...' : 'No GPS location synced yet' ?>
          </div>
          <div style="margin-top:10px;padding:8px 12px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:11px;color:var(--muted);display:inline-flex;">
            Click My Position on the map to compare your distance.
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px;">
            <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
              <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Accuracy</p>
              <p style="margin:0;font-size:13px;"><?= !empty($detailLocation['accuracy_meters']) ? h((string)$detailLocation['accuracy_meters']) . ' m' : 'Awaiting GPS' ?></p>
            </div>
            <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;">
              <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Position freshness</p>
              <p style="margin:0;font-size:13px;"><?= !empty($detailLocation['tracked_at']) ? h(relative_time($detailLocation['tracked_at'])) : 'Assigned site fallback' ?></p>
            </div>
          </div>
          <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:14px 16px;margin-top:8px;">
            <div style="display:grid;grid-template-columns:1fr;gap:10px;">
              <div>
                <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Live zone</p>
                <p style="margin:4px 0 0;font-size:13px;"><?= h($detailLocation['address'] ?? ($detailLocation['zone_detail'] ?? 'No field zone synced yet')) ?></p>
              </div>
              <div>
                <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Duty label</p>
                <p style="margin:4px 0 0;font-size:13px;"><?= h($selectedGuard['shift_label'] ?: 'Unassigned') ?></p>
              </div>
              <div>
                <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Assigned duty site</p>
                <p style="margin:4px 0 0;font-size:13px;"><?= h($detailDutySiteName . ($detailDutySiteArea ? ' · ' . $detailDutySiteArea : '')) ?></p>
              </div>
              <div>
                <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Assigned area</p>
                <p style="margin:4px 0 0;font-size:13px;"><?= h($detailDutySiteArea ?: 'No area assigned') ?></p>
              </div>
              <div>
                <p style="margin:0;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Exact sync point</p>
                <p style="margin:4px 0 0;font-size:13px;"><?= !empty($detailLocation['latitude']) && !empty($detailLocation['longitude']) ? h(number_format((float)$detailLocation['latitude'], 6) . ', ' . number_format((float)$detailLocation['longitude'], 6)) : 'Awaiting first location sync' ?></p>
              </div>
            </div>
          </div>
          <?php if (!empty($detailLocation['tracked_at'])): ?>
            <p style="margin:0;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);">Tracked <?= h(date('M j, Y g:i A', strtotime($detailLocation['tracked_at']))) ?></p>
          <?php endif; ?>
        </div>
      <?php
      function admin_selfie_status_label(?array $selfie): string {
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

      function admin_selfie_score_text(?array $selfie): string {
          if (!$selfie) {
              return 'Pending';
          }

          $score = $selfie['verification_score'] ?? null;
          if ($score === null || $score === '') {
              return 'Not available';
          }

          return (int)round(((float)$score) * 100) . '%';
      }

      $latestCheckInSelfie = $guardCheckInSelfies[0] ?? null;
      $latestCheckOutSelfie = $guardCheckOutSelfies[0] ?? null;
          $latestSelfieReference = $selectedGuard['identity_photo_path'] ?? '';
          $latestCheckInImage = $latestCheckInSelfie['image_path'] ?? '';
          $latestCheckOutImage = $latestCheckOutSelfie['image_path'] ?? '';
          $latestCheckInStatus = (string)($latestCheckInSelfie['identity_status'] ?? '');
          $latestCheckInHasCapture = !empty($latestCheckInSelfie);
          $latestCheckInPassed = (int)($latestCheckInSelfie['verification_passed'] ?? 0) === 1 || $latestCheckInStatus === 'verified';
          $latestCheckInScore = isset($latestCheckInSelfie['verification_score']) && $latestCheckInSelfie['verification_score'] !== null
              ? (int)round(((float)$latestCheckInSelfie['verification_score']) * 100)
              : null;
          $latestCheckInStatusText = $latestCheckInHasCapture ? admin_selfie_status_label($latestCheckInSelfie) : 'Awaiting capture';
          $latestCheckInToneBorder = $latestCheckInPassed ? 'rgba(71,216,162,0.20)' : 'rgba(249,208,44,0.22)';
          $latestCheckInToneBg = $latestCheckInPassed ? 'rgba(71,216,162,0.10)' : 'rgba(249,208,44,0.10)';
          $latestCheckInDescription = $latestCheckInPassed
              ? 'Latest selfie matched the enrolled reference image.'
              : ($latestCheckInHasCapture ? 'A selfie was captured and is ready for review against the enrolled reference.' : 'Waiting for the first shift selfie capture.');
          $latestCheckOutStatus = (string)($latestCheckOutSelfie['identity_status'] ?? '');
          $latestCheckOutHasCapture = !empty($latestCheckOutSelfie);
          $latestCheckOutPassed = (int)($latestCheckOutSelfie['verification_passed'] ?? 0) === 1 || $latestCheckOutStatus === 'verified';
          $latestCheckOutScore = isset($latestCheckOutSelfie['verification_score']) && $latestCheckOutSelfie['verification_score'] !== null
              ? (int)round(((float)$latestCheckOutSelfie['verification_score']) * 100)
              : null;
          $latestCheckOutStatusText = $latestCheckOutHasCapture ? admin_selfie_status_label($latestCheckOutSelfie) : 'Awaiting capture';
          $latestCheckOutDescription = $latestCheckOutPassed
              ? 'Check-out selfie matched the registered guard photo.'
              : ($latestCheckOutHasCapture ? 'A check-out selfie was captured and is ready for review against the registered photo.' : 'Waiting for the first check-out selfie capture.');
        ?>
        <div class="guard-detail-activity-card" style="border:1px solid var(--line);background:#fff;border-radius:22px;padding:16px;">
          <p style="margin:0 0 12px;font-size:13px;font-weight:500;color:var(--body);">Latest selfie verification</p>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <?php
              $phaseCards = [
                [
                  'label' => 'Check-in verification',
                  'selfie' => $latestCheckInSelfie,
                  'image' => $latestCheckInImage,
                  'score' => $latestCheckInScore,
                  'statusText' => $latestCheckInStatusText,
                  'description' => $latestCheckInDescription,
                ],
                [
                  'label' => 'Check-out verification',
                  'selfie' => $latestCheckOutSelfie,
                  'image' => $latestCheckOutImage,
                  'score' => $latestCheckOutScore,
                  'statusText' => $latestCheckOutStatusText,
                  'description' => $latestCheckOutDescription,
                ],
              ];
            ?>
            <?php foreach ($phaseCards as $phaseCard): ?>
              <div style="border:1px solid var(--line);border-radius:18px;padding:14px;background:rgba(248,250,252,.9);display:grid;gap:12px;">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                  <div style="min-width:0;flex:1;">
                    <p style="margin:0 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);"><?= h($phaseCard['label']) ?></p>
                    <p style="margin:0;font-size:16px;font-weight:700;color:var(--body);"><?= h($phaseCard['statusText']) ?></p>
                    <p style="margin:6px 0 0;font-size:12px;color:var(--muted);"><?= h($phaseCard['description']) ?></p>
                  </div>
                  <div style="border-radius:14px;border:1px solid var(--line);background:#fff;padding:10px 14px;min-width:96px;text-align:right;">
                    <p style="margin:0 0 3px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Score</p>
                    <p style="margin:0;font-size:22px;font-weight:700;color:var(--body);"><?= h(admin_selfie_score_text($phaseCard['selfie'])) ?></p>
                  </div>
                </div>
                <div style="height:8px;border-radius:99px;background:rgba(2,6,23,0.08);overflow:hidden;">
                  <div style="height:100%;width:<?= $phaseCard['score'] !== null ? max(0, min(100, (int)$phaseCard['score'])) : 0 ?>%;border-radius:99px;background:linear-gradient(90deg,#f9d02c,#47d8a2);"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                  <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:12px 14px;">
                    <p style="margin:0 0 5px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Reference</p>
                    <p style="margin:0;font-size:12px;"><?= !empty($selectedGuard['identity_photo_path']) ? 'Registered photo' : 'None enrolled' ?></p>
                  </div>
                  <div style="border:1px solid var(--line);background:#fff;border-radius:16px;padding:12px 14px;">
                    <p style="margin:0 0 5px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Captured</p>
                    <p style="margin:0;font-size:12px;"><?= !empty($phaseCard['selfie']['captured_at']) ? h(relative_time($phaseCard['selfie']['captured_at'])) : 'No capture yet' ?></p>
                  </div>
                </div>
                <div style="border:1px solid var(--line);background:#fff;border-radius:22px;overflow:hidden;">
                  <?php if (!empty($phaseCard['image'])): ?>
                    <img src="<?= h(asset_url($phaseCard['image'])) ?>" alt="<?= h($phaseCard['label']) ?>" style="width:100%;height:160px;object-fit:cover;display:block;">
                  <?php else: ?>
                    <div style="height:160px;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;color:var(--muted);font-size:12px;">No selfie captured yet</div>
                  <?php endif; ?>
                  <div style="padding:10px 12px;border-top:1px solid var(--line);">
                    <p style="margin:0;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);"><?= h($phaseCard['label']) ?></p>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:start;margin-top:12px;">
            <div style="border:1px solid var(--line);background:#fff;border-radius:22px;overflow:hidden;">
              <?php if (!empty($latestSelfieReference)): ?>
                <img src="<?= h(asset_url($latestSelfieReference)) ?>" alt="Registered guard photo" style="width:100%;height:160px;object-fit:contain;display:block;background:#f8fafc;">
              <?php else: ?>
                <div style="height:160px;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;color:var(--muted);font-size:12px;">No registered photo yet</div>
              <?php endif; ?>
              <div style="padding:10px 12px;border-top:1px solid var(--line);">
                <p style="margin:0;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);">Registered guard photo</p>
              </div>
            </div>
            <div style="border:1px solid var(--line);background:#fff;border-radius:22px;overflow:hidden;">
              <?php if (!empty($latestCheckInImage)): ?>
                <img src="<?= h(asset_url($latestCheckInImage)) ?>" alt="Latest check-in selfie" style="width:100%;height:160px;object-fit:cover;display:block;">
              <?php else: ?>
                <div style="height:160px;display:flex;align-items:center;justify-content:center;padding:16px;text-align:center;color:var(--muted);font-size:12px;">No check-in selfie captured yet</div>
              <?php endif; ?>
              <div style="padding:10px 12px;border-top:1px solid var(--line);">
                <p style="margin:0;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);">Latest check-in selfie</p>
              </div>
            </div>
          </div>
        </div>
        <?php
          $guardTodayPings = 0;
          foreach (($todayLocationRows ?? []) as $pingRow) {
              if ((int)($pingRow['user_id'] ?? 0) === (int)($selectedGuard['id'] ?? 0)) {
                  $guardTodayPings = (int)($pingRow['ping_count'] ?? 0);
                  break;
              }
          }

          $guardActivityRows = array_values(array_filter($guardActivities ?? [], function ($row) use ($selectedGuard) {
              return (int)($row['guard_id'] ?? $row['user_id'] ?? 0) === (int)($selectedGuard['id'] ?? 0);
          }));
          $guardEventsToday = count($guardActivityRows);

          $guardVerifiedSelfies = 0;
          foreach (($guardSelfies ?? []) as $selfieRow) {
              if ((int)($selfieRow['verification_passed'] ?? 0) === 1) {
                  $guardVerifiedSelfies++;
              }
          }

          $freshnessLabel = 'No sync';
          if (!empty($detailLocation['tracked_at'])) {
              $freshnessMins = max(0, (int)floor((time() - strtotime($detailLocation['tracked_at'])) / 60));
              $freshnessLabel = $freshnessMins < 60 ? $freshnessMins . 'm' : max(1, (int)floor($freshnessMins / 60)) . 'h';
          }

          $attendanceRows = array_values(array_filter($guardAttendanceToday ?? [], function ($row) use ($selectedGuard) {
              return (int)($row['guard_id'] ?? $row['user_id'] ?? 0) === (int)($selectedGuard['id'] ?? 0);
          }));
          usort($attendanceRows, function ($a, $b) {
              return strtotime((string)($b['check_in_at'] ?? '')) <=> strtotime((string)($a['check_in_at'] ?? ''));
          });

          $activityRows = array_slice($guardActivityRows, 0, 3);
          $attendanceRows = array_slice($attendanceRows, 0, 5);
        ?>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <div style="border:1px solid var(--line);background:var(--surface-soft);border-radius:16px;padding:14px 16px;">
            <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Events today</p>
            <p style="margin:0;font-size:24px;font-weight:700;color:var(--body);"><?= (int)$guardEventsToday ?></p>
          </div>
          <div style="border:1px solid var(--line);background:var(--surface-soft);border-radius:16px;padding:14px 16px;">
            <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Location pings</p>
            <p style="margin:0;font-size:24px;font-weight:700;color:var(--body);"><?= (int)$guardTodayPings ?></p>
          </div>
          <div style="border:1px solid var(--line);background:var(--surface-soft);border-radius:16px;padding:14px 16px;">
            <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Verified selfies</p>
            <p style="margin:0;font-size:24px;font-weight:700;color:var(--body);"><?= (int)$guardVerifiedSelfies ?></p>
          </div>
          <div style="border:1px solid var(--line);background:var(--surface-soft);border-radius:16px;padding:14px 16px;">
            <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.22em;color:var(--muted);">Freshness</p>
            <p style="margin:0;font-size:24px;font-weight:700;color:var(--body);"><?= h($freshnessLabel) ?></p>
          </div>
        </div>

        <div class="guard-activity-panel" style="border:1px solid var(--line);background:var(--surface-soft);border-radius:22px;padding:16px;">
          <p style="margin:0 0 12px;font-size:13px;font-weight:500;color:var(--body);">Recent activity log</p>
          <?php if ($activityRows): ?>
            <div style="display:grid;gap:10px;">
              <?php foreach ($activityRows as $row): ?>
                <?php
                  $activityTitle = $row['title'] ?? ($row['type'] ?? 'Guard activity');
                  $activityDescription = $row['description'] ?? ($row['details'] ?? 'Activity recorded for this guard.');
                  $activityType = $row['type'] ?? 'update';
                  $activityBadge = $row['priority'] ?? $activityType;
                  $activityTime = !empty($row['created_at']) ? date('M j g:i A', strtotime($row['created_at'])) : '';
                ?>
                <div class="guard-activity-item" style="border:1px solid var(--line);background:#fff;border-radius:20px;padding:14px;">
                  <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                    <div style="min-width:0;">
                      <p style="margin:0;font-size:14px;font-weight:700;color:var(--body);"><?= h($activityTitle) ?></p>
                      <p style="margin:4px 0 0;font-size:12px;color:var(--muted);"><?= h($activityDescription) ?></p>
                    </div>
                    <span class="status-badge"><?= h($activityBadge) ?></span>
                  </div>
                  <p style="margin:10px 0 0;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--muted);">
                    <?= h($activityType) ?><?= $activityTime ? ' · ' . h($activityTime) : '' ?>
                  </p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p style="margin:0;font-size:13px;color:var(--muted);">No guard activity has been logged yet.</p>
          <?php endif; ?>
        </div>

        <div class="guard-attendance-panel" style="border:1px solid var(--line);background:var(--surface-soft);border-radius:22px;padding:16px;">
          <p style="margin:0 0 12px;font-size:13px;font-weight:500;color:var(--body);">Recent attendance trail</p>
          <?php if ($attendanceRows): ?>
            <div style="display:grid;gap:12px;">
              <?php foreach ($attendanceRows as $row): ?>
                <?php
                  $statusLabel = !empty($row['check_out_at']) ? 'Completed shift' : 'Open shift';
                  $locationLabel = $row['location_label'] ?? ($row['location_name'] ?? 'Location unavailable');
                  $shiftNote = trim((string)($row['shift_note'] ?? ''));
                  $checkInLabel = !empty($row['check_in_at']) ? date('M j g:i A', strtotime($row['check_in_at'])) : '';
                  $checkOutLabel = !empty($row['check_out_at']) ? date('M j g:i A', strtotime($row['check_out_at'])) : '';
                ?>
                <div class="guard-attendance-item" style="display:flex;gap:12px;align-items:flex-start;">
                  <div style="width:12px;height:12px;border-radius:999px;margin-top:5px;background:linear-gradient(135deg,#f9d02c,#47d8a2);box-shadow:0 0 0 4px rgba(71,216,162,0.12);flex:0 0 auto;"></div>
                  <div style="min-width:0;flex:1;">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                      <p style="margin:0;font-size:14px;font-weight:700;color:var(--body);"><?= h($statusLabel) ?></p>
                      <span class="status-badge"><?= h($statusLabel) ?></span>
    </div>
                    <p style="margin:4px 0 0;font-size:12px;color:var(--muted);"><?= h($locationLabel) ?></p>
                    <?php if ($shiftNote !== ''): ?>
                      <span style="display:inline-block;margin-top:8px;padding:6px 10px;border-radius:999px;background:rgba(71,216,162,0.10);border:1px solid rgba(71,216,162,0.18);font-size:11px;color:var(--body);"><?= h($shiftNote) ?></span>
                    <?php endif; ?>
                    <p style="margin:8px 0 0;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:var(--muted);">
                      <?= $checkInLabel ? 'Check-in ' . h($checkInLabel) : 'Check-in N/A' ?><?= $checkOutLabel ? ' · Check-out ' . h($checkOutLabel) : '' ?>
                    </p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p style="margin:0;font-size:13px;color:var(--muted);">No attendance trail available yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <script>
    (function () {
      const el = document.getElementById('guard-detail-map');
      if (!el || typeof L === 'undefined') return;

      const lat = parseFloat(el.dataset.lat || '');
      const lng = parseFloat(el.dataset.lng || '');
      if (Number.isNaN(lat) || Number.isNaN(lng)) return;

      const guardName = el.dataset.guardName || <?= json_encode($selectedGuard['full_name'] ?? 'Guard') ?>;
      el.innerHTML = '';
      const map = L.map(el, { zoomControl: true }).setView([lat, lng], 15);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
      }).addTo(map);

      L.marker([lat, lng]).addTo(map).bindPopup(guardName);

      const refresh = () => map.invalidateSize(true);
      requestAnimationFrame(refresh);
      setTimeout(refresh, 120);
      window.addEventListener('load', refresh, { once: true });
    })();
    </script>
<?php endif; ?>
<?php endif; ?>

<?php if ($adminSection === 'admin-duty-site-management'): ?>
    <section class="panel card" id="admin-duty-site-management">
      <div class="panel-head">
        <div>
          <h2>Duty Site Management</h2>
          <p>Duty sites, guard zones, and selectable labels.</p>
        </div>
      </div>

      <div class="panel card" style="margin-bottom:20px;">
        <div class="panel-head">
          <div>
            <p class="eyebrow">Duty zone labels</p>
            <h3>Guard selectable duty zones</h3>
            <p>Used in guard shift forms.</p>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin:12px 0;">
          <?php
            $defaultZones = ['Gate', 'Lobby', 'Warehouse', 'Perimeter', 'Ring'];
            $allZones = array_unique(array_merge($orgDutyLabels, $defaultZones));
            foreach ($allZones as $zone):
              $isSaved = in_array($zone, $orgDutyLabels, true);
          ?>
            <div style="display:flex;align-items:center;overflow:hidden;border-radius:14px;border:1px solid <?= $isSaved ? '#47d8a2' : '#cbd5e1' ?>;background:<?= $isSaved ? '#dcfdf3' : '#fff' ?>;">
              <form method="post" style="flex:1;margin:0;">
                <input type="hidden" name="action" value="set_zone_label_selection">
                <input type="hidden" name="zone_label" value="<?= h($zone) ?>">
                <button type="submit" style="width:100%;padding:8px 10px;background:none;border:none;cursor:pointer;font-weight:600;font-size:12px;text-align:left;"><?= h($zone) ?></button>
              </form>
              <?php if ($isSaved): ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="delete_zone_label">
                  <input type="hidden" name="zone_label" value="<?= h($zone) ?>">
                  <button type="submit" style="padding:8px 10px;background:none;border:none;cursor:pointer;color:#ef4444;" title="Remove <?= h($zone) ?>">×</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
          <input type="hidden" name="action" value="add_zone_label">
          <input name="zone_label" placeholder="Add zone label, e.g. Gate A" style="flex:1;min-width:180px;" class="app-input">
          <button class="btn btn-outline" type="submit">Add label</button>
          <button class="btn btn-primary" type="submit" name="action" value="save_zone_labels">Save labels</button>
        </form>
      </div>

      <div style="display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:16px;align-items:start;">
        <form class="form-grid two" method="post">
          <input type="hidden" name="action" value="create_duty_site">
          <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
          <label><span>Duty site name</span><input name="name" placeholder="Panjim Office" required></label>
          <label><span>Area / city</span><input name="area" placeholder="Panjim"></label>
          <label class="full-row"><span>Address / duty details</span><input name="address" placeholder="Main gate, lobby, perimeter, or client site"></label>
          <label><span>Latitude</span><input name="latitude" type="number" step="0.000001" placeholder="15.4909"></label>
          <label><span>Longitude</span><input name="longitude" type="number" step="0.000001" placeholder="73.8278"></label>
          <div class="full-row"><button class="btn btn-primary" type="submit">Create duty site</button></div>
        </form>
        <div class="guard-list">
          <?php foreach ($dutySites as $site): ?>
            <article class="guard-card">
              <div class="guard-card__head">
                <div>
                  <strong><?= h($site['name']) ?></strong>
                  <p><?= h(($site['area'] ?? 'No area') . ' • ' . ($site['address'] ?? 'No address')) ?></p>
                  <small><?= h(format_coordinates($site['latitude'] ?? null, $site['longitude'] ?? null)) ?></small>
                </div>
                <span class="status-badge"><?= h($site['status']) ?></span>
              </div>
              <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                <a class="btn btn-outline btn-sm" href="?page=admin&section=admin-duty-site-management&edit_site=<?= (int)$site['id'] ?>">Edit site</a>
                <form method="post">
                  <input type="hidden" name="action" value="update_duty_site">
                  <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                  <input type="hidden" name="status" value="<?= $site['status'] === 'active' ? 'inactive' : 'active' ?>">
                  <button class="btn btn-outline btn-sm" type="submit"><?= $site['status'] === 'active' ? 'Mark inactive' : 'Reactivate' ?></button>
                </form>
              </div>
              <?php if ($siteEdit && (int)$siteEdit['id'] === (int)$site['id']): ?>
                <form class="form-grid two" method="post" style="margin-top:14px;">
                  <input type="hidden" name="action" value="update_duty_site">
                  <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                  <label><span>Name</span><input name="name" value="<?= h($siteEdit['name']) ?>"></label>
                  <label><span>Area</span><input name="area" value="<?= h($siteEdit['area'] ?? '') ?>"></label>
                  <label class="full-row"><span>Address</span><input name="address" value="<?= h($siteEdit['address'] ?? '') ?>"></label>
                  <label><span>Latitude</span><input name="latitude" type="number" step="0.000001" value="<?= h((string)($siteEdit['latitude'] ?? '')) ?>"></label>
                  <label><span>Longitude</span><input name="longitude" type="number" step="0.000001" value="<?= h((string)($siteEdit['longitude'] ?? '')) ?>"></label>
                  <label class="full-row"><span>Status</span><select name="status"><option value="active"<?= ($siteEdit['status'] ?? '') === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= ($siteEdit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></label>
                  <div style="display:flex;gap:8px;grid-column:1 / -1;">
                    <a class="btn btn-outline" href="?page=admin&section=admin-duty-site-management">Cancel</a>
                    <button class="btn btn-primary" type="submit">Save duty site</button>
                  </div>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
          <?php if (!$dutySites): ?>
            <p style="color:var(--muted);font-size:13px;">No duty sites yet. Add the first office, branch, post, or client location.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($adminSection === 'admin-create-guard'): ?>
    <section class="panel card" id="admin-create-guard">
      <div class="panel-head">
        <div>
          <h2>Guard Setup</h2>
          <p>Create guard access, identity reference, duty site, and duty zones.</p>
        </div>
      </div>
      <?php if ($guardEdit): ?>
        <div class="panel card" style="margin-bottom:16px;">
          <p class="eyebrow">Editing</p>
          <h2><?= h($guardEdit['full_name']) ?></h2>
        </div>
      <?php endif; ?>
      <?php
        $guardFormValues = $guardEdit ?: [
            'full_name' => '',
            'email' => '',
            'phone' => '',
            'employee_code' => '',
            'shift_label' => '',
            'duty_site_id' => '',
            'status' => 'active'
        ];
        $guardFormAction = $guardEdit ? 'update_guard' : 'create_guard';
        $guardFormSubmitLabel = $guardEdit ? 'Save guard changes' : 'Create guard account';
      ?>
      <form class="form-grid two" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= h($guardFormAction) ?>">
        <input type="hidden" name="organization_id" value="<?= (int)$organizationId ?>">
        <?php if ($guardEdit): ?>
          <input type="hidden" name="guard_id" value="<?= (int)$guardEdit['id'] ?>">
        <?php endif; ?>
        <label><span>Full name</span><input name="full_name" placeholder="Nadia Brooks" value="<?= h($guardFormValues['full_name'] ?? '') ?>" required></label>
        <label><span>Email</span><input name="email" type="email" placeholder="guard@example.com" value="<?= h($guardFormValues['email'] ?? '') ?>" required></label>
        <label><span>Phone</span><input name="phone" placeholder="+1 202 555 0180" value="<?= h($guardFormValues['phone'] ?? '') ?>"></label>
        <label><span>Employee code</span><input name="employee_code" placeholder="GRD-205" value="<?= h($guardFormValues['employee_code'] ?? '') ?>"></label>
        <label class="full-row">
          <span>Duty zone label</span>
          <select name="shift_label" style="width:100%;min-height:44px;border-radius:14px;border:1px solid var(--line);background:#f8fbff;padding:10px 12px;">
            <?php foreach ($orgDutyLabels as $zone): ?>
              <option value="<?= h($zone) ?>"<?= (string)($guardFormValues['shift_label'] ?? '') === (string)$zone ? ' selected' : '' ?>><?= h($zone) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="panel card" style="margin-top:0;grid-column:1 / -1;">
          <div class="panel-head" style="align-items:flex-start">
            <div>
              <p class="eyebrow">Identity enrollment</p>
              <strong>Registered photo + enrollment selfie</strong>
              <p>Add a profile image and an enrollment selfie. Shift selfies will be compared to these during admin review.</p>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px;">
            <div>
              <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.18em;margin-bottom:8px;">Registered guard photo</label>
              <div id="photo-preview" style="width:100%;height:160px;background:var(--surface-soft);border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;margin-bottom:8px;">No image selected</div>
              <input name="identity_photo" id="identity_photo" type="file" accept="image/*" style="display:none;" onchange="previewFile(this,'photo-preview')">
              <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('identity_photo').click()">Choose image</button>
              <p style="font-size:11px;color:var(--muted);margin-top:6px;">This image appears beside shift selfies for admin review.</p>
            </div>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.18em;margin-bottom:8px;">Enrollment selfie</label>
              <div id="selfie-preview" style="width:100%;height:160px;background:var(--surface-soft);border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;margin-bottom:8px;">No image selected</div>
              <video id="selfie-video" autoplay muted playsinline style="display:none;width:100%;height:200px;background:#000;border-radius:12px;object-fit:cover;margin-bottom:8px;"></video>
              <input name="identity_selfie" id="identity_selfie" type="file" accept="image/*" style="display:none;" onchange="previewFile(this,'selfie-preview')">
              <canvas id="selfie-canvas" style="display:none;"></canvas>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm" id="selfie-camera-btn" onclick="openSelfieCamera()">Capture live selfie</button>
                <button type="button" class="btn btn-primary btn-sm" id="selfie-capture-btn" style="display:none;" onclick="captureSelfie()">Use this selfie</button>
                <button type="button" class="btn btn-outline btn-sm" id="selfie-cancel-btn" style="display:none;" onclick="stopSelfieCamera()">Cancel</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('identity_selfie').click()">Choose image</button>
              </div>
              <p style="font-size:11px;color:var(--muted);margin-top:6px;">Best captured live during onboarding. Used for shift selfie comparison.</p>
            </div>
          </div>
        </div>
        <label><span>Duty site</span>
          <select name="duty_site_id" style="width:100%;min-height:44px;border-radius:14px;border:1px solid var(--line);background:#f8fbff;padding:10px 12px;">
            <option value="">Select duty site</option>
            <?php foreach ($dutySites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"<?= (string)($guardFormValues['duty_site_id'] ?? '') === (string)$site['id'] ? ' selected' : '' ?>><?= h($site['name']) ?><?= $site['area'] ? ' · ' . h($site['area']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span>Status</span>
          <select name="status" style="width:100%;min-height:44px;border-radius:14px;border:1px solid var(--line);background:#f8fbff;padding:10px 12px;">
            <option value="active"<?= (string)($guardFormValues['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= (string)($guardFormValues['status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option>
          </select>
        </label>
        <label class="full-row"><span>New password</span><input name="password" type="password" placeholder="Leave blank to keep current" style="width:100%;min-height:44px;border-radius:14px;border:1px solid var(--line);background:#f8fbff;padding:10px 12px;"></label>
        <div class="full-row">
          <button class="btn btn-primary" type="submit" style="background:linear-gradient(90deg,#f9d02c,#47d8a2);color:#020617;font-weight:700;grid-column:1 / -1;">+ <?= h($guardFormSubmitLabel) ?></button>
        </div>
      </form>
      <script>
      function previewFile(input, previewId) {
        const preview = document.getElementById(previewId);
        const file = input.files[0];
        if (!file || !preview) return;
        const url = URL.createObjectURL(file);
        preview.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
      }

      let selfieStream = null;

      async function openSelfieCamera() {
        const video = document.getElementById('selfie-video');
        const cameraBtn = document.getElementById('selfie-camera-btn');
        const captureBtn = document.getElementById('selfie-capture-btn');
        const cancelBtn = document.getElementById('selfie-cancel-btn');
        try {
          selfieStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
          video.srcObject = selfieStream;
          video.style.display = 'block';
          cameraBtn.style.display = 'none';
          captureBtn.style.display = 'block';
          cancelBtn.style.display = 'block';
        } catch(e) {
          alert('Camera not available. Use "Choose image" instead.');
        }
      }

      function captureSelfie() {
        const video = document.getElementById('selfie-video');
        const canvas = document.getElementById('selfie-canvas');
        const preview = document.getElementById('selfie-preview');
        const input = document.getElementById('identity_selfie');
        if (!video.videoWidth) { alert('Camera preview still loading, try again.'); return; }
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob(blob => {
          if (!blob) return;
          const file = new File([blob], 'enrollment-selfie-' + Date.now() + '.jpg', { type: 'image/jpeg' });
          const dt = new DataTransfer();
          dt.items.add(file);
          input.files = dt.files;
          const url = URL.createObjectURL(blob);
          preview.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">';
          stopSelfieCamera();
        }, 'image/jpeg', 0.82);
      }

      function stopSelfieCamera() {
        const video = document.getElementById('selfie-video');
        const cameraBtn = document.getElementById('selfie-camera-btn');
        const captureBtn = document.getElementById('selfie-capture-btn');
        const cancelBtn = document.getElementById('selfie-cancel-btn');
        if (selfieStream) { selfieStream.getTracks().forEach(t => t.stop()); selfieStream = null; }
        video.style.display = 'none';
        cameraBtn.style.display = 'block';
        captureBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
      }
      </script>
    </section>
  <?php endif; ?>
</section>



