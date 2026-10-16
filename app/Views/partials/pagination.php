<?php
/** expects: $result = ['rows','total','page','per_page'] */
$totalPages = max(1, (int) ceil(($result['total'] ?? 0) / max(1, $result['per_page'] ?? 15)));
$page = (int) ($result['page'] ?? 1);
if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($page > 1): ?><a class="page-btn" href="<?= e(page_url($page - 1)) ?>"><?= partial('partials/icon', ['name' => 'chevron-left']) ?> Prev</a><?php endif; ?>
  <span class="page-info">Page <?= $page ?> of <?= $totalPages ?> · <?= (int) $result['total'] ?> total</span>
  <?php if ($page < $totalPages): ?><a class="page-btn" href="<?= e(page_url($page + 1)) ?>">Next <?= partial('partials/icon', ['name' => 'chevron-right']) ?></a><?php endif; ?>
</nav>
<?php endif; ?>
