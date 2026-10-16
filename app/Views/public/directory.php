<section class="directory">
  <h1>Find care</h1>
  <p class="muted">Public MediLink directory — bookable providers across approved Medboat organizations.</p>

  <form method="get" action="/directory" class="directory-search">
    <label class="sr-only" for="q">Search</label>
    <input type="search" id="q" name="q" placeholder="Doctor or specialty…" value="<?= e($q) ?>">
    <label class="sr-only" for="specialty">Specialty</label>
    <select id="specialty" name="specialty">
      <option value="">All specialties</option>
      <?php foreach ($specialties as $s): ?>
        <option value="<?= e($s) ?>" <?= $specialty === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="sr-only" for="location">Location</label>
    <input type="search" id="location" name="location" placeholder="City / area" value="<?= e($location) ?>">
    <button class="btn btn-primary" type="submit"><?= partial('partials/icon', ['name' => 'search']) ?> Search</button>
  </form>

  <?php if ($providers === []): ?>
    <?= partial('partials/empty', ['message' => 'No providers match your search. Try a different specialty or location.', 'icon' => 'stethoscope']) ?>
  <?php else: ?>
    <div class="provider-grid">
      <?php foreach ($providers as $p): ?>
        <article class="provider-card">
          <div class="provider-head">
            <span class="avatar lg"><?= e(strtoupper(mb_substr($p['full_name'], 0, 1))) ?></span>
            <div>
              <h3><?= e($p['full_name']) ?></h3>
              <span class="provider-spec"><?= e($p['specialty']) ?></span>
            </div>
          </div>
          <p class="provider-org"><?= partial('partials/icon', ['name' => 'building']) ?> <?= e($p['organization_name'] ?? 'Independent') ?><?= $p['location'] ? ' · ' . e($p['location']) : '' ?></p>
          <?php if (!empty($p['bio'])): ?><p class="provider-bio"><?= e($p['bio']) ?></p><?php endif; ?>
          <a class="btn btn-secondary btn-sm" href="/login">Sign in to book</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
