<?php
ob_start();
$currentUser = $user ?? null;
$suggestedTitle = $suggestedTitle ?? $listing['title'];
$attrs = $attrs ?? [];
$fromDraft = $fromDraft ?? false;
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">Review & edit description</h1>
  <p style="color: var(--color-text-muted); margin-bottom: 24px;">We generated a description from your photo. Edit as you like—only this final text will be shown on your listing.</p>
  <div class="card" style="max-width: 640px;">
    <div class="card-body">
      <div class="review-box">
        <h4>AI suggested title (optional)</h4>
        <p><?= htmlspecialchars($suggestedTitle) ?></p>
      </div>
      <?php if (!empty($attrs)): ?>
        <div class="review-box">
          <h4>Detected attributes</h4>
          <p><?= htmlspecialchars(implode(', ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys($attrs), $attrs))) ?></p>
        </div>
      <?php endif; ?>
      <form method="post" action="/listings/create/review">
        <?= \App\Security::csrfField() ?>
        <input type="hidden" name="listing_id" value="<?= (int)$listing['id'] ?>">
        <div class="form-group">
          <label for="title">Title</label>
          <input type="text" id="title" name="title" required value="<?= htmlspecialchars($listing['title']) ?>" placeholder="Use suggestion or write your own">
        </div>
        <div class="form-group">
          <label for="final_description">Description (required)</label>
          <textarea id="final_description" name="final_description" required placeholder="Use the suggestion below or edit manually"><?= htmlspecialchars($listing['ai_suggested_description'] ?? '') ?></textarea>
          <p class="form-hint">This is the description visitors will see. Stored as final description; we also keep the AI suggestion for reference.</p>
        </div>
        <button type="submit" class="btn btn-primary"><?= !empty($fromDraft) ? 'Continue to add details' : 'Save listing' ?></button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Review description';
include dirname(__DIR__) . '/layout.php';
