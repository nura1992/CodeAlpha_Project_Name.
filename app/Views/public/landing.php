<section class="hero">
  <div class="hero-inner">
    <span class="hero-kicker">One account · the whole Medboat ecosystem</span>
    <h1>Your health, connected.</h1>
    <p class="hero-lead">
      MedCore brings identity (MediID), care discovery (MediLink), financing (MediFund),
      integrations (MediBridge) and assisted insights (MedIntellect AI) into one secure
      platform — starting with rock-solid identity, roles and auditability.
    </p>
    <div class="hero-actions">
      <a class="btn btn-primary btn-lg" href="/register">Create your account</a>
      <a class="btn btn-secondary btn-lg" href="/directory">Find a provider</a>
    </div>
  </div>
</section>

<section class="features">
  <h2>The Medboat ecosystem</h2>
  <div class="feature-grid">
    <article class="feature-card">
      <div class="feature-icon live"><?= partial('partials/icon', ['name' => 'shield']) ?></div>
      <h3>MediID</h3>
      <p>One verified health identity with consent-first records. Your history follows you, securely.</p>
      <span class="tag tag-live">Live in Phase 1</span>
    </article>
    <article class="feature-card">
      <div class="feature-icon live"><?= partial('partials/icon', ['name' => 'stethoscope']) ?></div>
      <h3>MediLink</h3>
      <p>Find providers, book appointments, and keep your care team in sync across organizations.</p>
      <span class="tag tag-live">Live in Phase 1</span>
    </article>
    <article class="feature-card">
      <div class="feature-icon future"><?= partial('partials/icon', ['name' => 'heart']) ?></div>
      <h3>MediFund</h3>
      <p>Transparent billing, payments and health financing with an auditable ledger.</p>
      <span class="tag">Phase 3</span>
    </article>
    <article class="feature-card">
      <div class="feature-icon future"><?= partial('partials/icon', ['name' => 'activity']) ?></div>
      <h3>MediBridge</h3>
      <p>Versioned integrations that exchange only permitted data with authorized systems.</p>
      <span class="tag">Phase 4</span>
    </article>
    <article class="feature-card">
      <div class="feature-icon future"><?= partial('partials/icon', ['name' => 'activity']) ?></div>
      <h3>MedIntellect AI</h3>
      <p>Clearly-labelled, human-reviewed assistance across approved workflows — never auto-diagnosis.</p>
      <span class="tag">Phase 5</span>
    </article>
    <article class="feature-card">
      <div class="feature-icon live"><?= partial('partials/icon', ['name' => 'key']) ?></div>
      <h3>MedCore Core</h3>
      <p>Authentication, permissions, API, audit logging and notifications — the platform backbone.</p>
      <span class="tag tag-live">Live in Phase 1</span>
    </article>
  </div>
</section>

<section class="cta-band">
  <h2>Care providers' directory</h2>
  <p>Browse active Medboat care providers by specialty and location (synthetic demo data).</p>
  <a class="btn btn-light" href="/directory">Open directory</a>
</section>
