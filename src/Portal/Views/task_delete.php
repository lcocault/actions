<h1>Supprimer la tâche</h1>

<?php if ($dependents !== []): ?>
<div class="error-summary" role="alert">
  <p>Suppression impossible : cette tâche est requise par :</p>
  <ul>
<?php foreach ($dependents as $dependent): ?>
    <li><a href="<?= $url('/tasks/' . $dependent['id']) ?>"><?= $e($dependent['title']) ?></a></li>
<?php endforeach; ?>
  </ul>
  <p>Retirez d'abord ce lien dans chacune de ces tâches.</p>
</div>
<?php endif; ?>

<p>Vous allez supprimer définitivement la tâche « <strong><?= $e($task['title']) ?></strong> ». Cette action est irréversible.</p>

<form method="post" action="<?= $url('/tasks/' . $task['id'] . '/delete') ?>" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button type="submit" class="danger">Supprimer définitivement</button>
  <a href="<?= $url('/tasks/' . $task['id']) ?>">Annuler</a>
</form>
