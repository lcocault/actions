<section aria-labelledby="h-status">
  <h2 id="h-status">État</h2>
<?php if ($stateError !== null): ?>
  <div class="error-summary" role="alert">
    <p><?= $e($stateError['message']) ?></p>
<?php if ($stateError['items'] !== []): ?>
    <ul>
<?php foreach ($stateError['items'] as $item): ?>
      <li><a href="<?= $url('/tasks/' . $item['id']) ?>"><?= $e($item['title']) ?></a> — <?= $e(\Actions\Portal\Labels::status($item['status'])) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </div>
<?php endif; ?>
  <p>
    <span class="status status-<?= $e($task['status']) ?>"><?= $e(\Actions\Portal\Labels::status($task['status'])) ?></span>
<?php if ($task['completed_at'] !== null): ?>
    — terminée le <?= $e(\Actions\Portal\Labels::dateTime($task['completed_at'])) ?>
<?php endif; ?>
  </p>
<?php if ($transitions !== []): ?>
  <div class="actions">
<?php foreach ($transitions as $transition): ?>
<?php if ($transition['reopenPage']): ?>
    <a class="button" href="<?= $url('/tasks/' . $task['id'] . '/reopen') ?>"><?= $e($transition['label']) ?></a>
<?php else: ?>
    <form method="post" action="<?= $url('/tasks/' . $task['id'] . '/status') ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="status" value="<?= $e($transition['target']) ?>">
      <button type="submit"><?= $e($transition['label']) ?></button>
    </form>
<?php endif; ?>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
