<h1>Matrice globale d'Eisenhower</h1>
<p>Actions de tous les projets, Pro et Perso. Situation au <?= $e(\Actions\Portal\Labels::date($overview['today'])) ?>.</p>

<?= $render('matrix', ['overview' => $overview, 'project' => null]) ?>
