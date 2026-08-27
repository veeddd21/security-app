<?php
$features = [
  ['title' => 'Secure identity flow', 'copy' => 'Session-based access, protected routes, and hashed credentials keep every shift entry controlled.'],
  ['title' => 'Attendance with proof', 'copy' => 'Start each patrol with a selfie capture, a location stamp, and a durable attendance trail.'],
  ['title' => 'Live activity pulse', 'copy' => 'Track patrol notes, alerts, and check-in events in a clean command feed for managers.'],
  ['title' => 'Mobile location tracking', 'copy' => 'Field devices can sync geolocation updates to show where guards are operating and when they last moved.'],
  ['title' => 'Local selfie storage', 'copy' => 'Selfie proof can be stored locally for development or permanently in uploads for live deployments.'],
];

$pillars = [
  ['1', 'Landing page and premium auth flow'],
  ['2', 'Guard dashboard for attendance, patrol logs, and live location'],
  ['3', 'Admin dashboard for roster control and live operations'],
];
?>

<main class="landing-shell">
  <div class="landing-aurora landing-aurora-gold"></div>
  <div class="landing-aurora landing-aurora-jade"></div>
  <div class="landing-aurora landing-aurora-blue"></div>

  <header class="topbar card">
    <a class="brand" href="?page=landing">
      <img src="../public/brand-shield.svg" alt="Infipre Security">
      <div>
        <h1>Infipre Security</h1>
        <p>Security Guard Tracking System</p>
      </div>
    </a>
    <div class="topbar-actions">
      <a class="btn btn-outline btn-sm" href="?page=auth">Sign In</a>
      <a class="btn btn-primary btn-sm" href="?page=auth">Launch System</a>
    </div>
  </header>

  <section class="hero-grid">
    <div class="hero-copy">
      <span class="pill">Premium field operations</span>
      <h2>Modern guard tracking built for reliable field execution.</h2>
      <p class="hero-lead">
        Manage secure sign-in, shift attendance, selfie verification, live patrol activity,
        and location telemetry from one polished Core PHP platform.
      </p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="?page=auth">Enter Mission Control</a>
        <a class="btn btn-outline" href="#features">Explore features</a>
      </div>
      <div class="mini-grid">
        <div class="mini-card">
          <strong>Attendance proof</strong>
          <span>Selfie + location check-in</span>
        </div>
        <div class="mini-card">
          <strong>Session security</strong>
          <span>PHP auth and route locking</span>
        </div>
        <div class="mini-card">
          <strong>Admin command</strong>
          <span>Guard roster and live feed</span>
        </div>
      </div>
    </div>

    <aside class="status-card card">
      <div class="status-card__head">
        <div>
          <p class="eyebrow">Operations view</p>
          <h3>Live Security Pulse</h3>
        </div>
        <span class="status-pill"><i></i>All systems armed</span>
      </div>

      <div class="metric-grid">
        <div class="metric metric-gold">
          <p>Guards on duty</p>
          <strong>12</strong>
          <span>3 zones actively patrolled</span>
        </div>
        <div class="metric metric-jade">
          <p>Selfie verification</p>
          <strong>98%</strong>
          <span>All active shifts verified</span>
        </div>
      </div>

      <div class="activity-card">
        <div class="activity-head">
          <p>Recent activity stream</p>
          <span>Live</span>
        </div>
        <div class="activity-list">
          <div class="activity-item"><span></span><div><strong>North Gate</strong><p>Shift check-in verified with selfie capture</p><small>1 min ago</small></div></div>
          <div class="activity-item"><span></span><div><strong>Warehouse Ring</strong><p>Location sync received from field device</p><small>4 min ago</small></div></div>
          <div class="activity-item"><span></span><div><strong>Command Center</strong><p>Roster updated for overnight coverage</p><small>11 min ago</small></div></div>
        </div>
      </div>
    </aside>
  </section>

  <section id="features" class="section-block">
    <div class="section-head">
      <p class="eyebrow">System pillars</p>
      <h3>Everything needed for trustworthy field accountability.</h3>
    </div>
    <div class="feature-grid">
      <?php foreach ($features as $feature): ?>
        <article class="feature-card card">
          <div class="feature-icon"></div>
          <h4><?= h($feature['title']) ?></h4>
          <p><?= h($feature['copy']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section-block">
    <div class="pillar-grid">
      <?php foreach ($pillars as $pillar): ?>
        <div class="pillar-card card">
          <div class="pillar-number"><?= h($pillar[0]) ?></div>
          <p><?= h($pillar[1]) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>
