<h1>Projets</h1>

<p class="actions">
  <a class="button" href="<?= $url('/projects/new') ?>">Nouveau projet</a>
</p>

<?php if ($projects === []): ?>
<p class="empty">Aucun projet.</p>
<?php else: ?>
<table>
  <caption class="visually-hidden">Liste des projets</caption>
  <thead>
    <tr>
      <th scope="col">Projet</th>
      <th scope="col">Catégorie</th>
      <th scope="col">Tâches</th>
      <th scope="col">Actions</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($projects as $project): ?>
    <tr>
      <td><a href="<?= $url('/projects/' . $project['id']) ?>"><?= $e($project['name']) ?></a></td>
      <td><?= $e($project['category']) ?></td>
      <td><?= $e($project['task_count']) ?></td>
      <td>
        <a href="<?= $url('/projects/' . $project['id'] . '/edit') ?>">Modifier<span class="visually-hidden"> le projet <?= $e($project['name']) ?></span></a>
        <a href="<?= $url('/projects/' . $project['id'] . '/delete') ?>">Supprimer<span class="visually-hidden"> le projet <?= $e($project['name']) ?></span></a>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
