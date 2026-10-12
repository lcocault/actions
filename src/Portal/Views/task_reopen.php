<h1>Rouvrir la tâche</h1>

<p>Vous allez rouvrir la tâche « <strong><?= $e($task['title']) ?></strong> ».</p>
<p class="warning">Cette tâche est périodique. Une occurrence suivante a pu être créée quand vous l'avez terminée; la rouvrir peut créer un doublon.</p>

<form method="post" action="<?= $url('/tasks/' . $task['id'] . '/status') ?>" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="status" value="todo">
  <button type="submit">Rouvrir quand même</button>
  <a href="<?= $url('/tasks/' . $task['id']) ?>">Annuler</a>
</form>
