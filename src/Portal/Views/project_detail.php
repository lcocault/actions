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

<?= $render('matrix', ['overview' => $overview, 'project' => $project]) ?>
