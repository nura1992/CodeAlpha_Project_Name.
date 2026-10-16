<div class="page-head">
  <div>
    <h1>Platform settings</h1>
    <p class="muted">Cross-module configuration (super administrator only).</p>
  </div>
</div>

<section class="card narrow">
  <div class="card-body">
    <form method="post" action="/admin/settings" class="form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Platform name</span>
        <input type="text" name="platform_name" value="<?= e($settings['platform_name'] ?? '') ?>" maxlength="120">
      </label>
      <div class="form-row">
        <label class="field">
          <span>Support email</span>
          <input type="email" name="support_email" value="<?= e($settings['support_email'] ?? '') ?>" maxlength="190">
        </label>
        <label class="field">
          <span>Support phone</span>
          <input type="text" name="support_phone" value="<?= e($settings['support_phone'] ?? '') ?>" maxlength="20">
        </label>
      </div>
      <label class="field">
        <span>Maintenance message <em class="opt">shown to users when set</em></span>
        <input type="text" name="maintenance_message" value="<?= e($settings['maintenance_message'] ?? '') ?>" maxlength="255">
      </label>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save settings</button>
      </div>
    </form>
  </div>
</section>
