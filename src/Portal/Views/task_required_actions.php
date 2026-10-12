<section aria-labelledby="h-required">
  <h2 id="h-required">Actions requises</h2>
<?php if ($requiredError !== null): ?>
  <div class="error-summary" role="alert">
    <p><?= $e($requiredError) ?></p>
  </div>
<?php endif; ?>
<?php if ($task['required_actions'] === []): ?>
  <p>Aucune action requise.</p>
<?php else: ?>
  <ul class="required">
<?php foreach ($task['required_actions'] as $required): ?>
    <li>
      <a href="<?= $url('/tasks/' . $required['id']) ?>"><?= $e($required['title']) ?></a>
<?php if (isset($statusById[$required['id']])): ?>
      — <span class="status status-<?= $e($statusById[$required['id']]) ?>"><?= $e(\Actions\Portal\Labels::status($statusById[$required['id']])) ?></span>
<?php endif; ?>
      <form method="post" action="<?= $url('/tasks/' . $task['id'] . '/required-actions/' . $required['id'] . '/delete') ?>" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button type="submit">Retirer<span class="visually-hidden"> <?= $e($required['title']) ?></span></button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <form method="post" action="<?= $url('/tasks/' . $task['id'] . '/required-actions') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="field">
      <label for="f-required_id">Ajouter une action requise</label>
      <select id="f-required_id" name="required_id"<?php if ($requiredError !== null): ?> aria-invalid="true"<?php endif; ?>>
        <option value=""<?= $e($requiredInput === '' ? ' selected' : '') ?>>Choisir une tâche…</option>
<?php foreach ($candidates as $candidate): ?>
        <option value="<?= $e($candidate['id']) ?>"<?= $e($requiredInput === (string) $candidate['id'] ? ' selected' : '') ?>><?= $e($candidate['title'] . ' — ' . \Actions\Portal\Labels::status($candidate['status']) . ($candidate['id'] === $task['id'] ? ' (cette tâche)' : '')) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <p class="actions"><button type="submit">Ajouter</button></p>
  </form>
</section>
