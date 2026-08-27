<?php
$superSection = $_GET['section'] ?? 'platform-overview';
$organizations = db()->query("SELECT * FROM organizations ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$admins = db()->query("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$guardCount = (int)(db()->query("SELECT COUNT(*) AS c FROM users WHERE role = 'guard'")->fetch_assoc()['c'] ?? 0);
$tempPasswords = $_SESSION['super_admin_temp_passwords'] ?? [];
$editOrgId = (int)($_GET['edit_org'] ?? 0);
$orgEdit = null;
foreach ($organizations as $organization) {
  if ((int)$organization['id'] === $editOrgId) {
    $orgEdit = $organization;
    break;
  }
}
?>

<section class="page-grid page-grid--super">
  <?php if ($superSection === 'platform-overview'): ?>
    <div class="stats-row">
      <div class="stat-card card"><span>Organizations</span><strong><?= count($organizations) ?></strong><small>Active workspaces</small></div>
      <div class="stat-card card"><span>Subscriptions</span><strong><?= count($organizations) ?></strong><small>Manual active subscription records</small></div>
      <div class="stat-card card"><span>Guards</span><strong><?= $guardCount ?></strong><small>Across all organizations</small></div>
      <div class="stat-card card"><span>Owner role</span><strong>Super</strong><small>Platform-level access</small></div>
    </div>

    <div class="quick-actions">
      <a class="quick-card card" href="?page=super-admin&section=organizations"><strong>Review companies</strong><small><?= count($organizations) ?> workspaces</small></a>
      <a class="quick-card card" href="?page=super-admin&section=new-organization"><strong>Add company</strong><small>Create workspace and first admin</small></a>
      <a class="quick-card card" href="?page=super-admin&section=organization-admins"><strong>Add admin</strong><small>Give access to an existing company</small></a>
    </div>
  <?php endif; ?>

  <?php if ($superSection === 'new-organization' || $superSection === 'organization-admins'): ?>
    <div class="content-grid">
      <?php if ($superSection === 'new-organization'): ?>
        <section class="panel card" id="new-organization">
          <div class="panel-head">
            <div>
              <h2>Add Company Workspace</h2>
              <p>Create workspace and first admin.</p>
            </div>
          </div>
          <form class="form-grid two" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_organization">
            <label><span>Company / organization name</span><input name="name" placeholder="North Star Security" required></label>
            <label><span>Workspace code</span><input name="code" placeholder="NORTH-STAR" required></label>
            <label><span>Contact email</span><input name="contact_email" type="email" placeholder="ops@example.com"></label>
            <label><span>Phone</span><input name="phone" placeholder="+91 90000 00000"></label>
            <label class="full-row"><span>Company logo</span><input data-preview-target="create-org-logo-preview" name="organization_logo" type="file" accept="image/*"></label>
            <div id="create-org-logo-preview" class="full-row" style="height:140px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.58);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:12px;">No image selected</div>
            <label><span>Subscription plan</span>
              <select name="plan">
                <option value="starter">Starter</option>
                <option value="professional">Professional</option>
                <option value="enterprise">Enterprise</option>
              </select>
            </label>
            <label><span>Guard limit</span><input name="guard_limit" type="number" value="50"></label>
            <label><span>First admin name</span><input name="admin_full_name" placeholder="Amit Rao"></label>
            <label><span>First admin email</span><input name="admin_email" type="email" placeholder="admin@client.com"></label>
            <label><span>First admin password</span><input name="admin_password" type="password" placeholder="Temporary password"></label>
            <label><span>First admin phone</span><input name="admin_phone" placeholder="+91 90000 00000"></label>
            <label class="full-row"><span>First admin profile photo</span><input data-preview-target="create-admin-photo-preview" name="admin_photo" type="file" accept="image/*"></label>
            <div id="create-admin-photo-preview" class="full-row" style="height:140px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.58);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:12px;">No image selected</div>
            <div class="full-row"><button class="btn btn-primary" type="submit">Create Workspace</button></div>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($superSection === 'organization-admins'): ?>
        <section class="panel card" id="organization-admins">
          <div class="panel-head">
            <div>
              <h2>Add Organization Admin</h2>
              <p>Add admin access.</p>
            </div>
          </div>
          <form class="form-grid two" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_admin">
            <label class="full-row"><span>Organization</span>
              <select name="organization_id" required>
                <option value="">Select organization</option>
                <?php foreach ($organizations as $organization): ?>
                  <option value="<?= (int)$organization['id'] ?>"><?= h($organization['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><span>Admin name</span><input name="full_name" placeholder="Amit Rao" required></label>
            <label><span>Admin email</span><input name="email" type="email" placeholder="admin@client.com" required></label>
            <label><span>Temporary password</span><input name="password" type="password" placeholder="Minimum 8 characters" required></label>
            <label><span>Phone</span><input name="phone" placeholder="+91 90000 00000"></label>
            <label class="full-row"><span>Employee code</span><input name="employee_code" placeholder="ADM-001"></label>
            <label class="full-row"><span>Profile photo</span><input data-preview-target="new-admin-photo-preview" name="admin_photo" type="file" accept="image/*"></label>
            <div id="new-admin-photo-preview" class="full-row" style="height:140px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.58);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:12px;">No image selected</div>
            <div class="full-row"><button class="btn btn-outline" type="submit">Create Admin</button></div>
          </form>
        </section>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($superSection === 'organizations'): ?>
    <section class="panel card" id="organizations">
      <div class="panel-head">
        <div>
          <h2>Company Workspaces</h2>
          <p>Workspaces, admins, plans, and guard limits.</p>
        </div>
      </div>
      <div class="organization-list">
        <?php foreach ($organizations as $organization): ?>
          <article class="organization-card">
            <div class="organization-card__head">
              <div style="display:flex;gap:12px;align-items:flex-start;min-width:0;">
                <div style="width:56px;height:56px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:rgba(255,255,255,.58);display:grid;place-items:center;flex:0 0 auto;">
                  <?php if (!empty($organization['logo_url'])): ?>
                    <img src="<?= h(asset_url($organization['logo_url'])) ?>" alt="<?= h($organization['name']) ?> logo" style="width:100%;height:100%;object-fit:cover;display:block;">
                  <?php else: ?>
                    <span style="font-weight:700;color:var(--muted);font-size:14px;"><?= h(strtoupper(substr((string)$organization['name'], 0, 2))) ?></span>
                  <?php endif; ?>
                </div>
                <div style="min-width:0;">
                  <p class="eyebrow">Company workspace</p>
                  <h3><?= h($organization['name']) ?></h3>
                  <small><?= h($organization['code']) ?></small>
                </div>
              </div>
              <span class="status-badge"><?= h(ucfirst((string)$organization['status'])) ?></span>
            </div>
            <div class="organization-metrics">
              <div><span>Guards</span><strong>2 / <?= h((string)$organization['guard_limit']) ?></strong></div>
              <div><span>Admins</span><strong>1</strong></div>
              <div><span>Plan</span><strong><?= h(ucfirst($organization['plan'])) ?></strong></div>
            </div>
            <div class="organization-meta">
              <p><?= h($organization['contact_email'] ?? 'No contact email') ?></p>
              <p><?= h($organization['phone'] ?? 'No phone') ?></p>
              <p>Subscription: <?= h(ucfirst($organization['subscription_status'])) ?></p>
            </div>

            <?php if ($orgEdit && (int)$orgEdit['id'] === (int)$organization['id']): ?>
              <form method="post" class="form-grid two mt-4" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_organization">
                <input type="hidden" name="organization_id" value="<?= (int)$organization['id'] ?>">
                <label><span>Name</span><input name="name" value="<?= h($organization['name']) ?>"></label>
                <label><span>Code</span><input name="code" value="<?= h($organization['code']) ?>"></label>
                <label><span>Contact email</span><input name="contact_email" value="<?= h($organization['contact_email'] ?? '') ?>"></label>
                <label><span>Phone</span><input name="phone" value="<?= h($organization['phone'] ?? '') ?>"></label>
                <label class="full-row"><span>Logo</span><input data-preview-target="org-logo-preview-<?= (int)$organization['id'] ?>" name="organization_logo" type="file" accept="image/*"></label>
                <div id="org-logo-preview-<?= (int)$organization['id'] ?>" class="full-row" style="height:140px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.58);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:12px;">
                  <?php if (!empty($organization['logo_url'])): ?>
                    <img src="<?= h(asset_url($organization['logo_url'])) ?>" alt="<?= h($organization['name']) ?> logo" style="width:100%;height:100%;object-fit:cover;display:block;">
                  <?php else: ?>
                    No image selected
                  <?php endif; ?>
                </div>
                <label><span>Plan</span>
                  <select name="plan">
                    <option value="starter" <?= ($organization['plan'] ?? '') === 'starter' ? 'selected' : '' ?>>Starter</option>
                    <option value="professional" <?= ($organization['plan'] ?? '') === 'professional' ? 'selected' : '' ?>>Professional</option>
                    <option value="enterprise" <?= ($organization['plan'] ?? '') === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                  </select>
                </label>
                <label><span>Guard limit</span><input name="guard_limit" type="number" value="<?= h((string)$organization['guard_limit']) ?>"></label>
                <label><span>Status</span>
                  <select name="status">
                    <option value="active" <?= ($organization['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="suspended" <?= ($organization['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                  </select>
                </label>
                <label><span>Subscription</span>
                  <select name="subscription_status">
                    <option value="trial" <?= ($organization['subscription_status'] ?? '') === 'trial' ? 'selected' : '' ?>>Trial</option>
                    <option value="active" <?= ($organization['subscription_status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="past_due" <?= ($organization['subscription_status'] ?? '') === 'past_due' ? 'selected' : '' ?>>Past due</option>
                    <option value="cancelled" <?= ($organization['subscription_status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                  </select>
                </label>
                <div class="full-row shift-actions">
                  <button class="btn btn-primary" type="submit">Save changes</button>
                  <a class="btn btn-outline" href="?page=super-admin&section=organizations">Cancel</a>
                </div>
              </form>
            <?php else: ?>
              <div class="shift-actions mt-4" style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn btn-outline" href="?page=super-admin&section=organizations&edit_org=<?= (int)$organization['id'] ?>">Edit</a>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="update_organization">
                  <input type="hidden" name="organization_id" value="<?= (int)$organization['id'] ?>">
                  <input type="hidden" name="name" value="<?= h($organization['name']) ?>">
                  <input type="hidden" name="code" value="<?= h($organization['code']) ?>">
                  <input type="hidden" name="contact_email" value="<?= h($organization['contact_email'] ?? '') ?>">
                  <input type="hidden" name="phone" value="<?= h($organization['phone'] ?? '') ?>">
                  <input type="hidden" name="plan" value="<?= h($organization['plan']) ?>">
                  <input type="hidden" name="guard_limit" value="<?= h((string)$organization['guard_limit']) ?>">
                  <input type="hidden" name="subscription_status" value="<?= h($organization['subscription_status']) ?>">
                  <input type="hidden" name="status" value="<?= (string)($organization['status'] ?? '') === 'active' ? 'suspended' : 'active' ?>">
                  <button class="btn btn-outline" type="submit"><?= (string)($organization['status'] ?? '') === 'active' ? 'Suspend Organization' : 'Reactivate Organization' ?></button>
                </form>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($superSection === 'organization-admins'): ?>
    <section class="panel card" id="organization-admins-list">
      <div class="panel-head">
        <div>
          <h2>Organization Admins</h2>
          <p>Reset passwords and manage profiles.</p>
        </div>
      </div>
        <div class="guard-list">
          <?php foreach ($admins as $admin): ?>
            <article class="guard-card">
              <div class="guard-card__head">
                <div style="display:flex;gap:12px;align-items:flex-start;min-width:0;">
                  <div style="width:56px;height:56px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:rgba(255,255,255,.58);display:grid;place-items:center;flex:0 0 auto;">
                    <?php if (!empty($admin['avatar_url'])): ?>
                      <img src="<?= h(asset_url($admin['avatar_url'])) ?>" alt="<?= h($admin['full_name']) ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                    <?php else: ?>
                      <span style="font-weight:700;color:var(--muted);font-size:14px;"><?= h(strtoupper(substr((string)$admin['full_name'], 0, 2))) ?></span>
                    <?php endif; ?>
                  </div>
                  <div style="min-width:0;">
                    <strong><?= h($admin['full_name']) ?></strong>
                    <p><?= h($admin['email']) ?></p>
                  </div>
                </div>
                <span class="status-badge"><?= h(ucfirst((string)$admin['status'])) ?></span>
              </div>

              <form method="post" class="form-grid two mt-4" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_admin_profile">
                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                <label><span>Full name</span><input name="full_name" value="<?= h($admin['full_name']) ?>"></label>
                <label><span>Email</span><input name="email" type="email" value="<?= h($admin['email']) ?>"></label>
                <label><span>Phone</span><input name="phone" value="<?= h($admin['phone'] ?? '') ?>"></label>
                <label class="full-row"><span>Profile photo</span><input data-preview-target="admin-photo-preview-<?= (int)$admin['id'] ?>" name="admin_photo" type="file" accept="image/*"></label>
                <div id="admin-photo-preview-<?= (int)$admin['id'] ?>" class="full-row" style="height:140px;border:1px solid var(--line);border-radius:18px;background:rgba(255,255,255,.58);display:flex;align-items:center;justify-content:center;overflow:hidden;color:var(--muted);font-size:12px;">
                  <?php if (!empty($admin['avatar_url'])): ?>
                    <img src="<?= h(asset_url($admin['avatar_url'])) ?>" alt="<?= h($admin['full_name']) ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                  <?php else: ?>
                    No image selected
                  <?php endif; ?>
                </div>
                <div class="full-row"><button class="btn btn-outline" type="submit">Save profile</button></div>
              </form>

              <div class="shift-actions mt-4">
                <form method="post">
                  <input type="hidden" name="action" value="reset_admin_password">
                  <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                  <button class="btn btn-outline" type="submit">Reset password</button>
                </form>
              </div>
              <?php if (!empty($tempPasswords[(int)$admin['id']])): ?>
                <div class="mt-3 rounded-2xl border border-jade-300/20 bg-jade-300/10 p-3">
                  <p class="text-xs uppercase tracking-[0.2em] text-jade-200">Temporary password</p>
                  <code class="mt-2 block rounded-xl bg-slate-950/40 px-3 py-2 text-sm app-copy-primary"><?= h($tempPasswords[(int)$admin['id']]) ?></code>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
  <?php endif; ?>
</section>
<script>
(function () {
  function previewFile(input) {
    const targetId = input.dataset.previewTarget;
    const target = targetId ? document.getElementById(targetId) : null;
    const file = input.files && input.files[0];
    if (!target) return;
    if (!file) {
      target.innerHTML = 'No image selected';
      return;
    }
    const url = URL.createObjectURL(file);
    target.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;display:block;">';
  }

  document.querySelectorAll('input[type="file"][data-preview-target]').forEach((input) => {
    input.addEventListener('change', () => previewFile(input));
  });
})();
</script>
