<?php
$projectFilter = $project === null ? [] : ['project' => $project['name']];
$scope = $project === null ? 'de tous les projets' : 'du projet';
?>
<section aria-labelledby="h-matrice">
  <h2 id="h-matrice">Matrice d'Eisenhower</h2>
  <p class="hint">Actions à faire ou en cours ayant une date butée, selon leur importance (lignes) et leur échéance (colonnes). Un nombre ouvre la liste de ces actions.</p>
  <table class="matrix">
    <caption>Actions <?= $project === null ? 'de tous les projets' : 'du projet' ?> par importance et par urgence, situation au <?= $e(\Actions\Portal\Labels::date($overview['today'])) ?></caption>
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
<?php $cell = $row['cells'][$column]; $count = $cell['count']; $effort = $cell['estimated_minutes']; ?>
<?php $what = \Actions\Portal\Labels::actionWord($count) . ', importance ' . $row['importance'] . ', échéance ' . \Actions\Portal\Labels::urgencyLower($column); ?>
<?php if ($count > 0): ?>
<?php $what .= ', effort estimé ' . ($effort === null ? 'non renseigné' : \Actions\Portal\Labels::duration($effort)); ?>
<?php endif; ?>
        <td class="num">
<?php if ($count > 0): ?>
          <a href="<?= $url('/tasks', $projectFilter + ['importance' => $row['importance'], 'urgency' => $column]) ?>"><?= $e($count) ?><span class="visually-hidden"> <?= $e($what) ?></span><span class="effort" aria-hidden="true"> / <?= $e(\Actions\Portal\Labels::durationCompact($effort)) ?></span></a>
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
      <a class="num" href="<?= $url('/tasks', $projectFilter + ['status' => 'done']) ?>"><?= $e($done) ?><span class="visually-hidden"> <?= $e(\Actions\Portal\Labels::actionWord($done)) ?> <?= $e($scope) ?>, terminées</span></a>
<?php else: ?>
      <span class="num"><?= $e($done) ?><span class="visually-hidden"> action <?= $e($scope) ?>, terminées</span></span>
<?php endif; ?>
    </li>
    <li>Sans date butée :
<?php if ($noDate > 0): ?>
      <a class="num" href="<?= $url('/tasks', $projectFilter + ['no_due_date' => '1']) ?>"><?= $e($noDate) ?><span class="visually-hidden"> <?= $e(\Actions\Portal\Labels::actionWord($noDate)) ?> <?= $e($scope) ?> sans date butée, non terminées</span></a>
<?php else: ?>
      <span class="num"><?= $e($noDate) ?><span class="visually-hidden"> action <?= $e($scope) ?> sans date butée, non terminées</span></span>
<?php endif; ?>
    </li>
  </ul>
</section>
