-- Projet (liste fermée) et conclusions (texte libre), tous deux facultatifs.
-- Additive : les tâches existantes reçoivent NULL, aucune donnée existante modifiée.
ALTER TABLE tasks
  ADD COLUMN project     text,
  ADD COLUMN conclusions text,
  ADD CONSTRAINT tasks_project_valid
    CHECK (project IS NULL OR project IN ('MS-HR', 'Jardin/Maison', 'OPF', 'Erendil'));
