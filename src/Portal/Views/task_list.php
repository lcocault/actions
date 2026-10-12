<h1>Tâches</h1>

<form method="get" action="<?= $url('/tasks') ?>" class="filters" aria-label="Filtres de la liste">
  <div class="field">
    <label for="f-status">État</label>
    <select id="f-status" name="status">
      <option value="">Tous</option>
<?php foreach ($statuses as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?= $e($filters['status'] === $code ? ' selected' : '') ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-category">Catégorie</label>
    <select id="f-category" name="category">
      <option value="">Toutes</option>
<?php foreach (['Pro', 'Perso'] as $category): ?>
      <option value="<?= $e($category) ?>"<?= $e($filters['category'] === $category ? ' selected' : '') ?>><?= $e($category) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field check">
    <input type="checkbox" id="f-hide-done" name="hide_done" value="1"<?= $e($filters['hide_done'] ? ' checked' : '') ?>>
    <label for="f-hide-done">Masquer les tâches terminées</label>
  </div>
  <div class="actions">
    <button type="submit">Filtrer</button>
<?php if ($isFiltered): ?>
    <a href="<?= $url('/tasks') ?>">Réinitialiser les filtres</a>
<?php endif; ?>
  </div>
</form>

<?php if ($isFiltered): ?>
<p class="filter-summary">Filtres actifs :
  état <strong><?= $e($filters['status'] === '' ? 'tous' : \Actions\Portal\Labels::status($filters['status'])) ?></strong>,
  catégorie <strong><?= $e($filters['category'] === '' ? 'toutes' : $filters['category']) ?></strong><?= $e($filters['hide_done'] ? ', tâches terminées masquées' : '') ?>.</p>
<?php endif; ?>

<?php if ($tasks === []): ?>
<p class="empty"><?= $e($isFiltered ? 'Aucune tâche ne correspond aux filtres.' : 'Aucune tâche.') ?></p>
<?php else: ?>
<table>
  <caption class="visually-hidden">Liste des tâches</caption>
  <thead>
    <tr>
      <th scope="col">Titre</th>
      <th scope="col">Catégorie</th>
      <th scope="col">État</th>
      <th scope="col">Date butée</th>
      <th scope="col">Périodicité</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($tasks as $task): ?>
    <tr>
      <td><a href="<?= $url('/tasks/' . $task['id']) ?>"><?= $e($task['title']) ?></a></td>
      <td><?= $e($task['category']) ?></td>
      <td><span class="status status-<?= $e($task['status']) ?>"><?= $e(\Actions\Portal\Labels::status($task['status'])) ?></span></td>
      <td><?= $e(\Actions\Portal\Labels::date($task['due_date'])) ?></td>
      <td><?= $e(\Actions\Portal\Labels::recurrence($task['recurrence'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
