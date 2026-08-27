<?php
$demoCredentials = [
  ['title' => 'Super Admin', 'email' => 'richard.infipre@gmail.com', 'password' => 'Super@123', 'accent' => 'gold'],
  ['title' => 'Admin', 'email' => 'admin@infipre.local', 'password' => 'Admin@123', 'accent' => 'jade'],
  ['title' => 'Guard', 'email' => 'guard@infipre.local', 'password' => 'Guard@123', 'accent' => 'blue'],
];
?>

<main class="auth-shell auth-shell--react">
  <div class="auth-orb auth-orb--gold"></div>
  <div class="auth-orb auth-orb--jade"></div>
  <div class="auth-orb auth-orb--blue"></div>
  <section class="card auth-card auth-card--react">
    <div class="auth-head">
      <div class="auth-pill">Welcome back</div>
      <h1>Sign in to your <span>security</span> workspace.</h1>
      <p>Credentials unlock your workspace.</p>
    </div>

    <?php if (!empty($flash) && $flash['type'] === 'error'): ?>
      <div class="flash error"><?= h($flash['message']) ?></div>
    <?php elseif (!empty($flash) && $flash['type'] === 'success'): ?>
      <div class="flash success"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="?page=auth" class="auth-form">
      <input type="hidden" name="action" value="login">
      <label>
        <span>Email address</span>
        <input type="email" name="email" placeholder="guard@example.com" required>
      </label>
      <label class="password-field">
        <span>Password</span>
        <input id="password-input" type="password" name="password" placeholder="Enter your password" required>
        <button type="button" class="password-toggle" onclick="(function(){var i=document.getElementById('password-input');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';})()">
          👁
        </button>
      </label>
      <button class="auth-submit" type="submit">Enter dashboard</button>
    </form>

    <div class="demo-strip">
      <div class="demo-label">Quick sign in</div>
      <div class="demo-grid">
        <?php foreach ($demoCredentials as $demo): ?>
          <button
            type="button"
            class="demo-card demo-card--<?= h($demo['accent']) ?>"
            onclick="document.querySelector('input[name=email]').value='<?= h($demo['email']) ?>';document.querySelector('input[name=password]').value='<?= h($demo['password']) ?>';"
          >
            <strong><?= h($demo['title']) ?></strong>
            <small><?= h($demo['email']) ?></small>
            <small><?= h($demo['password']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="auth-footnote">Encrypted workspace access for verified security operations.</p>
  </section>
</main>
