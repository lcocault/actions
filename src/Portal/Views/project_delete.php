<h1>Supprimer le projet</h1>

<?php if ($blockedBy !== null): ?>
<div class="error-summary" role="alert">
  <p>Suppression impossible : ce projet contient <?= $e($blockedBy) ?> tâche(s).</p>
  <p><a href="<?= $url('/tasks', ['project' => $project['name']]) ?>">Voir les tâches du projet</a> pour les déplacer vers un autre projet ou les supprimer.</p>
</div>
<?php endif; ?>

<p>Vous allez supprimer définitivement le projet « <strong><?= $e($project['name']) ?></strong> » (<?= $e($project['task_count']) ?> tâche(s)). Un projet qui contient des tâches ne peut pas être supprimé. Cette action est irréversible.</p>

<form method="post" action="<?= $url('/projects/' . $project['id'] . '/delete') ?>" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button type="submit" class="danger">Supprimer définitivement</button>
  <a href="<?= $url('/projects') ?>">Annuler</a>
</form>
