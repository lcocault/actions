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
  <p class="hint">Actions à faire ou en cours ayant une date butée, selon leur importance (lignes) et leur échéance (colonnes). Un nombre ouvre la liste de ces actions.</p>
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
<?php $count = $row['cells'][$column]['count']; ?>
<?php $what = \Actions\Portal\Labels::actionWord($count) . ', importance ' . $row['importance'] . ', échéance ' . \Actions\Portal\Labels::urgencyLower($column); ?>
        <td class="num">
<?php if ($count > 0): ?>
          <a href="<?= $url('/tasks', ['project' => $project['name'], 'importance' => $row['importance'], 'urgency' => $column]) ?>"><?= $e($count) ?><span class="visually-hidden"> <?= $e($what) ?></span></a>
<?php else: ?>
          <span><?= $e($count) ?><span class="visually-hidden"> <?= $e($what) ?></span></span>
<?php endif; ?>
        </td>
<?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <h3>Hors matrice</h3>
  <ul class="outside">
<?php $done = $overview['outside']['done']['count']; $noDate = $overview['outside']['no_due_date']['count']; ?>
    <li>Terminées :
<?php if ($done > 0): ?>
      <a class="num" href="<?= $url('/tasks', ['project' => $project['name'], 'status' => 'done']) ?>"><?= $e($done) ?><span class="visually-hidden"> <?= $e(\Actions\Portal\Labels::actionWord($done)) ?> du projet, terminées</span></a>
<?php else: ?>
      <span class="num"><?= $e($done) ?><span class="visually-hidden"> action du projet, terminées</span></span>
<?php endif; ?>
    </li>
    <li>Sans date butée :
<?php if ($noDate > 0): ?>
      <a class="num" href="<?= $url('/tasks', ['project' => $project['name'], 'no_due_date' => '1']) ?>"><?= $e($noDate) ?><span class="visually-hidden"> <?= $e(\Actions\Portal\Labels::actionWord($noDate)) ?> du projet sans date butée, non terminées</span></a>
<?php else: ?>
      <span class="num"><?= $e($noDate) ?><span class="visually-hidden"> action du projet sans date butée, non terminées</span></span>
<?php endif; ?>
    </li>
  </ul>
</section>
