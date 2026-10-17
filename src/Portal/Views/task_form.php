<h1><?= $e($heading) ?></h1>

<?php if ($summary !== []): ?>
<div class="error-summary" role="alert">
  <p>La tâche n'a pas été enregistrée. Corrigez l'erreur suivante :</p>
  <ul>
<?php foreach ($summary as $item): ?>
<?php if ($item['anchor'] !== null): ?>
    <li><a href="#<?= $e($item['anchor']) ?>"><?= $e($item['message']) ?></a></li>
<?php else: ?>
    <li><?= $e($item['message']) ?></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= $url($action) ?>" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <div class="field">
    <label for="f-title">Titre <span class="hint">(obligatoire)</span></label>
    <input type="text" id="f-title" name="title" maxlength="255" required value="<?= $e($values['title']) ?>"
      aria-describedby="h-title<?= $e(isset($errors['title']) ? ' e-title' : '') ?>"<?php if (isset($errors['title'])): ?> aria-invalid="true"<?php endif; ?>>
    <p class="hint" id="h-title">255 caractères au plus.</p>
<?php foreach ($errors['title'] ?? [] as $message): ?>
    <p class="error" id="e-title"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-project">Projet <span class="hint">(obligatoire)</span></label>
    <select id="f-project" name="project" required aria-describedby="h-project<?= $e(isset($errors['project']) ? ' e-project' : '') ?>"<?php if (isset($errors['project'])): ?> aria-invalid="true"<?php endif; ?>>
      <option value=""<?= $e($values['project'] === '' ? ' selected' : '') ?>>Choisir…</option>
<?php foreach ($projects as $project): ?>
      <option value="<?= $e($project) ?>"<?= $e($values['project'] === $project ? ' selected' : '') ?>><?= $e($project) ?></option>
<?php endforeach; ?>
    </select>
    <p class="hint" id="h-project">La catégorie (Pro ou Perso) est celle du projet.</p>
<?php foreach ($errors['project'] ?? [] as $message): ?>
    <p class="error" id="e-project"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-importance">Importance</label>
    <select id="f-importance" name="importance" aria-describedby="h-importance<?= $e(isset($errors['importance']) ? ' e-importance' : '') ?>"<?php if (isset($errors['importance'])): ?> aria-invalid="true"<?php endif; ?>>
<?php foreach ($importances as $importance): ?>
      <option value="<?= $e($importance) ?>"<?= $e($values['importance'] === $importance ? ' selected' : '') ?>><?= $e($importance) ?></option>
<?php endforeach; ?>
    </select>
    <p class="hint" id="h-importance">Mineure par défaut.</p>
<?php foreach ($errors['importance'] ?? [] as $message): ?>
    <p class="error" id="e-importance"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-due_date">Date butée</label>
    <input type="date" id="f-due_date" name="due_date" value="<?= $e($values['due_date']) ?>"<?php if (isset($errors['due_date'])): ?> aria-describedby="e-due_date" aria-invalid="true"<?php endif; ?>>
<?php foreach ($errors['due_date'] ?? [] as $message): ?>
    <p class="error" id="e-due_date"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <fieldset<?php if (isset($errors['recurrence'])): ?> aria-describedby="e-recurrence"<?php endif; ?>>
    <legend>Périodicité</legend>
    <p class="hint">Facultative; exige une date butée. Répéter tous les :</p>
    <div class="inline">
      <label for="f-recurrence_interval">Nombre</label>
      <input type="number" id="f-recurrence_interval" name="recurrence_interval" min="1" step="1" value="<?= $e($values['recurrence_interval']) ?>"<?php if (isset($errors['recurrence'])): ?> aria-invalid="true"<?php endif; ?>>
      <label for="f-recurrence_unit">Unité</label>
      <select id="f-recurrence_unit" name="recurrence_unit"<?php if (isset($errors['recurrence'])): ?> aria-invalid="true"<?php endif; ?>>
        <option value=""<?= $e($values['recurrence_unit'] === '' ? ' selected' : '') ?>>—</option>
<?php foreach ($units as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?= $e($values['recurrence_unit'] === $code ? ' selected' : '') ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
<?php foreach ($errors['recurrence'] ?? [] as $message): ?>
    <p class="error" id="e-recurrence"><?= $e($message) ?></p>
<?php endforeach; ?>
  </fieldset>

  <div class="field">
    <label for="f-estimated_minutes">Durée estimée (minutes)</label>
    <input type="number" id="f-estimated_minutes" name="estimated_minutes" min="1" step="1" value="<?= $e($values['estimated_minutes']) ?>"<?php if (isset($errors['estimated_minutes'])): ?> aria-describedby="e-estimated_minutes" aria-invalid="true"<?php endif; ?>>
<?php foreach ($errors['estimated_minutes'] ?? [] as $message): ?>
    <p class="error" id="e-estimated_minutes"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-description">Description / contexte</label>
    <textarea id="f-description" name="description" rows="6"<?php if (isset($errors['description'])): ?> aria-describedby="e-description" aria-invalid="true"<?php endif; ?>><?= $e($values['description']) ?></textarea>
<?php foreach ($errors['description'] ?? [] as $message): ?>
    <p class="error" id="e-description"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-conclusions">Conclusions</label>
    <textarea id="f-conclusions" name="conclusions" rows="6" aria-describedby="h-conclusions<?= $e(isset($errors['conclusions']) ? ' e-conclusions' : '') ?>"<?php if (isset($errors['conclusions'])): ?> aria-invalid="true"<?php endif; ?>><?= $e($values['conclusions']) ?></textarea>
    <p class="hint" id="h-conclusions">Ce qui a été appris ou décidé; modifiable à tout moment.</p>
<?php foreach ($errors['conclusions'] ?? [] as $message): ?>
    <p class="error" id="e-conclusions"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <p class="actions">
    <button type="submit"><?= $e($submit) ?></button>
    <a href="<?= $url($cancel) ?>">Annuler</a>
  </p>
</form>
