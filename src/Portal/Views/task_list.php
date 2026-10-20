<h1>Tâches</h1>

<details class="filters-panel">
<summary>Filtres</summary>
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
  <div class="field">
    <label for="f-project">Projet</label>
    <select id="f-project" name="project">
      <option value="">Tous</option>
<?php foreach ($projects as $project): ?>
      <option value="<?= $e($project) ?>"<?= $e($filters['project'] === $project ? ' selected' : '') ?>><?= $e($project) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-importance">Importance</label>
    <select id="f-importance" name="importance">
      <option value="">Toutes</option>
<?php foreach ($importances as $importance): ?>
      <option value="<?= $e($importance) ?>"<?= $e($filters['importance'] === $importance ? ' selected' : '') ?>><?= $e($importance) ?></option>
<?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-urgency">Échéance</label>
    <select id="f-urgency" name="urgency" aria-describedby="h-urgency">
      <option value="">Toutes</option>
<?php foreach ($urgencies as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?= $e($filters['urgency'] === $code ? ' selected' : '') ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
    <p class="hint" id="h-urgency">Ne concerne que les tâches non terminées ayant une date butée.</p>
  </div>
  <div class="field check">
    <input type="checkbox" id="f-no-due-date" name="no_due_date" value="1"<?= $e($filters['no_due_date'] ? ' checked' : '') ?>>
    <label for="f-no-due-date">Sans date butée seulement (tâches non terminées)</label>
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
</details>

<?php if ($isFiltered): ?>
<p class="filter-summary">Filtres actifs :
  état <strong><?= $e($filters['status'] === '' ? 'tous' : \Actions\Portal\Labels::status($filters['status'])) ?></strong>,
  catégorie <strong><?= $e($filters['category'] === '' ? 'toutes' : $filters['category']) ?></strong>,
  projet <strong><?= $e($filters['project'] === '' ? 'tous' : $filters['project']) ?></strong>,
  importance <strong><?= $e($filters['importance'] === '' ? 'toutes' : $filters['importance']) ?></strong><?php if ($filters['urgency'] !== ''): ?>, échéance <strong><?= $e(\Actions\Portal\Labels::urgencyLower($filters['urgency'])) ?></strong> (situation au <?= $e(\Actions\Portal\Labels::date($today)) ?>)<?php endif; ?>
<?= $e($filters['no_due_date'] ? ', sans date butée seulement' : '') ?><?= $e($filters['hide_done'] ? ', tâches terminées masquées' : '') ?>.
<?php if ($filters['project'] !== '' && isset($projectIds[$filters['project']])): ?>
  <a href="<?= $url('/projects/' . $projectIds[$filters['project']]) ?>">Retour à la vue du projet</a>
<?php endif; ?></p>
<?php endif; ?>

<?php if ($tasks === []): ?>
<p class="empty"><?= $e($isFiltered ? 'Aucune tâche ne correspond aux filtres.' : 'Aucune tâche.') ?></p>
<?php else: ?>
<table>
  <caption class="visually-hidden">Liste des tâches</caption>
  <thead>
    <tr>
      <th scope="col">Titre</th>
      <th scope="col" class="col-extra">Catégorie (selon le projet)</th>
      <th scope="col" class="col-extra">Projet</th>
      <th scope="col" class="col-extra">Importance</th>
      <th scope="col" class="col-extra">État</th>
      <th scope="col" class="col-extra">Date butée</th>
      <th scope="col" class="col-extra">Périodicité</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($tasks as $task): ?>
    <tr>
      <td class="title"><a href="<?= $url('/tasks/' . $task['id']) ?>"><?= $e($task['title']) ?></a></td>
      <td class="col-extra"><?= $e($task['category']) ?></td>
      <td class="col-extra"><?php if (isset($projectIds[$task['project']])): ?><a href="<?= $url('/projects/' . $projectIds[$task['project']]) ?>"><?= $e($task['project']) ?></a><?php else: ?><?= $e($task['project']) ?><?php endif; ?></td>
      <td class="col-extra"><?= $e($task['importance']) ?></td>
      <td class="col-extra"><span class="status status-<?= $e($task['status']) ?>"><?= $e(\Actions\Portal\Labels::status($task['status'])) ?></span></td>
      <td class="col-extra"><?= $e(\Actions\Portal\Labels::date($task['due_date'])) ?></td>
      <td class="col-extra"><?= $e(\Actions\Portal\Labels::recurrence($task['recurrence'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
