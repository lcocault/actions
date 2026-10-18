<h1><?= $e($task['title']) ?></h1>

<p class="actions">
  <a class="button" href="<?= $url('/tasks/' . $task['id'] . '/edit') ?>">Modifier</a>
  <a class="button danger" href="<?= $url('/tasks/' . $task['id'] . '/delete') ?>">Supprimer</a>
  <a href="<?= $url('/tasks') ?>">Retour à la liste</a>
</p>

<section aria-labelledby="h-infos">
  <h2 id="h-infos">Informations</h2>
  <dl>
    <dt>Catégorie</dt>
    <dd><?= $e($task['category']) ?> (selon le projet)</dd>
    <dt>Projet</dt>
    <dd><?php if ($projectId !== null): ?><a href="<?= $url('/projects/' . $projectId) ?>"><?= $e($task['project']) ?></a><?php else: ?><?= $e($task['project']) ?><?php endif; ?></dd>
    <dt>Importance</dt>
    <dd><?= $e($task['importance']) ?></dd>
    <dt>Date butée</dt>
    <dd><?= $e($task['due_date'] === null ? 'Aucune' : \Actions\Portal\Labels::date($task['due_date'])) ?></dd>
    <dt>Périodicité</dt>
    <dd><?= $e($task['recurrence'] === null ? 'Aucune' : \Actions\Portal\Labels::recurrence($task['recurrence'])) ?></dd>
    <dt>Durée estimée</dt>
    <dd><?= $e($task['estimated_minutes'] === null ? 'Non renseignée' : \Actions\Portal\Labels::duration($task['estimated_minutes'])) ?></dd>
    <dt>Description</dt>
    <dd class="multiline"><?= $e($task['description'] ?? 'Aucune') ?></dd>
    <dt>Conclusions</dt>
    <dd class="multiline"><?= $e($task['conclusions'] ?? 'Aucune') ?></dd>
    <dt>Créée le</dt>
    <dd><?= $e(\Actions\Portal\Labels::dateTime($task['created_at'])) ?></dd>
    <dt>Modifiée le</dt>
    <dd><?= $e(\Actions\Portal\Labels::dateTime($task['updated_at'])) ?></dd>
  </dl>
</section>

<?= $render('task_status', ['task' => $task, 'stateError' => $stateError, 'transitions' => $transitions, 'csrf' => $csrf]) ?>

<?= $render('task_required_actions', ['task' => $task, 'statusById' => $statusById, 'candidates' => $candidates, 'requiredError' => $requiredError, 'requiredInput' => $requiredInput, 'csrf' => $csrf]) ?>
