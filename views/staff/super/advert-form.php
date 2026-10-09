<?php $title='New advert'; ?>
<div class="card"><h1>New advert</h1><p class="muted" style="font-size:12px">Paid content is always labelled as an advertisement. Advertisers never get access to verification data.</p>
<form method="post" action="/staff/super/advertisements" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="form-group"><label>Advertiser name</label><input name="advertiser_name" required></div>
<div class="grid grid-2"><div class="form-group"><label>Type</label><select name="type"><option value="text">Text</option><option value="image">Image</option><option value="video">Video</option></select></div><div class="form-group"><label>Placement</label><select name="placement"><option value="community_feed">Community feed</option><option value="discovery">Discovery</option></select></div></div>
<div class="form-group"><label>Headline</label><input name="headline"></div>
<div class="form-group"><label>Body</label><textarea name="body" rows="3"></textarea></div>
<div class="form-group"><label>Media (for image/video)</label><input type="file" name="media" accept="image/*,video/*"></div>
<div class="grid grid-2"><div class="form-group"><label>CTA text</label><input name="cta_text" placeholder="Learn more"></div><div class="form-group"><label>CTA URL</label><input name="cta_url" placeholder="https://…"></div></div>
<div class="grid grid-2"><div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach($campaigns as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Frequency cap (per user/day)</label><input type="number" name="frequency_cap" value="3" min="1"></div></div>
<button class="btn btn-primary">Create advert</button>
</form>
</div>
