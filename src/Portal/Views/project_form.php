<h1><?= $e($heading) ?></h1>

<?php if ($summary !== []): ?>
<div class="error-summary" role="alert">
  <p>Le projet n'a pas été enregistré. Corrigez l'erreur suivante :</p>
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
    <label for="f-name">Nom <span class="hint">(obligatoire)</span></label>
    <input type="text" id="f-name" name="name" maxlength="255" required value="<?= $e($values['name']) ?>"
      aria-describedby="h-name<?= $e(isset($errors['name']) ? ' e-name' : '') ?>"<?php if (isset($errors['name'])): ?> aria-invalid="true"<?php endif; ?>>
    <p class="hint" id="h-name">255 caractères au plus. Deux projets ne peuvent pas porter le même nom, majuscules et minuscules confondues.</p>
<?php foreach ($errors['name'] ?? [] as $message): ?>
    <p class="error" id="e-name"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="field">
    <label for="f-category">Catégorie <span class="hint">(obligatoire)</span></label>
    <select id="f-category" name="category" required aria-describedby="h-category<?= $e(isset($errors['category']) ? ' e-category' : '') ?>"<?php if (isset($errors['category'])): ?> aria-invalid="true"<?php endif; ?>>
      <option value=""<?= $e($values['category'] === '' ? ' selected' : '') ?>>Choisir…</option>
<?php foreach ($categories as $category): ?>
      <option value="<?= $e($category) ?>"<?= $e($values['category'] === $category ? ' selected' : '') ?>><?= $e($category) ?></option>
<?php endforeach; ?>
    </select>
    <p class="hint" id="h-category">Les tâches du projet ont la catégorie du projet.<?php if ($taskCount !== null): ?> Ce projet contient <?= $e($taskCount) ?> tâche(s) : changer sa catégorie change celle de ces tâches.<?php endif; ?></p>
<?php foreach ($errors['category'] ?? [] as $message): ?>
    <p class="error" id="e-category"><?= $e($message) ?></p>
<?php endforeach; ?>
  </div>

  <div class="actions">
    <button type="submit"><?= $e($submit) ?></button>
    <a href="<?= $url($cancel) ?>">Annuler</a>
  </div>
</form>
