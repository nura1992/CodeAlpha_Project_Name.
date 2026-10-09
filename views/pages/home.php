<?php $title='Welcome'; ?>
<div class="card" style="text-align:center;padding:32px">
<h1 style="font-size:32px;margin-bottom:8px"><?= e(config('app.name','CodeAlpha')) ?></h1>
<p class="muted" style="font-size:16px">Match with intention. Connect with community.</p>
<p style="margin-top:20px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
<a href="/register" class="btn btn-primary" style="padding:12px 28px;font-size:16px">Create account</a>
<a href="/login" class="btn btn-secondary" style="padding:12px 28px">Log in</a>
</p>
<div class="grid grid-3" style="margin-top:32px;text-align:left">
<div class="card"><strong>Verified profiles</strong><p class="muted" style="font-size:13px">Video verification reviewed by staff. A verified badge means the platform completed its checks — not a guarantee of identity or intentions. Profiles default to hidden until approval.</p></div>
<div class="card"><strong>Matchmaking</strong><p class="muted" style="font-size:13px">Male profiles discoverable only by female members and vice versa — enforced server-side, not in JavaScript.</p></div>
<div class="card"><strong>Community</strong><p class="muted" style="font-size:13px">Facebook-inspired posts, comments, reactions, follows and sharing. Available even when matchmaking is paused.</p></div>
</div>
</div>
