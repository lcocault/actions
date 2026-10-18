<?php
$project = $overview['project'];
$summary = $overview['summary'];
$none = $summary['total'] === 0;
?>
<h1><?= $e($project['name']) ?></h1>

<p class="actions">
  <a class="button" href="<?= $url('/projects/' . $project['id'] . '/edit') ?>">Modifier</a>
  <a class="button danger" href="<?= $url('/projects/' . $project['id'] . '/delete') ?>">Supprimer</a>
  <a href="<?= $url('/tasks', ['project' => $project['name']]) ?>">Voir les tâches du projet</a>
  <a href="<?= $url('/projects') ?>">Retour aux projets</a>
</p>

<dl>
  <dt>Catégorie</dt>
  <dd><?= $e($project['category']) ?></dd>
  <dt>Situation au</dt>
  <dd><?= $e(\Actions\Portal\Labels::date($overview['today'])) ?></dd>
</dl>

<section aria-labelledby="h-synthese">
  <h2 id="h-synthese">Synthèse par état</h2>
<?php if ($none): ?>
  <p class="empty">Ce projet ne contient aucune tâche.</p>
<?php endif; ?>
  <table class="summary">
    <caption class="visually-hidden">Nombre d'actions du projet par état</caption>
    <thead>
      <tr><th scope="col">État</th><th scope="col">Actions</th></tr>
    </thead>
    <tbody>
<?php foreach (\Actions\Portal\Labels::statuses() as $code => $label): ?>
      <tr><th scope="row"><?= $e($label) ?></th><td><?= $e($summary[$code]) ?></td></tr>
<?php endforeach; ?>
      <tr><th scope="row">Total</th><td><?= $e($summary['total']) ?></td></tr>
    </tbody>
  </table>
</section>

<section aria-labelledby="h-matrice">
  <h2 id="h-matrice">Matrice d'Eisenhower</h2>
  <p class="hint">Actions à faire ou en cours ayant une date butée, selon leur importance (lignes) et le délai avant leur date butée (colonnes). Une action en retard figure dans la colonne « Moins d'une semaine ».</p>
  <table class="matrix">
    <caption>Actions du projet par importance et par urgence, situation au <?= $e(\Actions\Portal\Labels::date($overview['today'])) ?></caption>
    <thead>
      <tr>
        <th scope="col">Importance</th>
<?php foreach ($overview['matrix']['columns'] as $column): ?>
        <th scope="col"><?= $e(\Actions\Portal\Labels::urgency($column)) ?></th>
<?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($overview['matrix']['rows'] as $row): ?>
      <tr>
        <th scope="row"><?= $e($row['importance']) ?></th>
<?php foreach ($overview['matrix']['columns'] as $column): ?>
<?php $cell = $row['cells'][$column]; ?>
        <td>
          <p class="count"><?= $e(\Actions\Portal\Labels::actionCount($cell['count'])) ?></p>
<?php if ($cell['tasks'] !== []): ?>
          <ul>
<?php foreach ($cell['tasks'] as $task): ?>
            <li><?php if ($task['late']): ?><strong>en retard</strong> : <?php endif; ?><a href="<?= $url('/tasks/' . $task['id']) ?>"><?= $e($task['title']) ?></a> (<?= $e(\Actions\Portal\Labels::date($task['due_date'])) ?>)</li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </td>
<?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <h3>Hors matrice</h3>
  <ul>
    <li>Terminées (hors matrice) : <?= $e($overview['outside']['done']['count']) ?></li>
    <li>Sans date butée : <?= $e($overview['outside']['no_due_date']['count']) ?>
<?php if ($overview['outside']['no_due_date']['tasks'] !== []): ?>
      <ul>
<?php foreach ($overview['outside']['no_due_date']['tasks'] as $task): ?>
        <li><a href="<?= $url('/tasks/' . $task['id']) ?>"><?= $e($task['title']) ?></a> (<?= $e(\Actions\Portal\Labels::status($task['status'])) ?>)</li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
  </ul>
</section>
