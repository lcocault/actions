-- 0004 : projets configurables; la catégorie d'une tâche découle de son projet
-- (docs/architecture.md, « Projets configurables et catégorie portée par le projet »).
-- Pas de BEGIN/COMMIT : la transaction est portée par bin/migrate. Une garde refuse la migration (sans état
-- partiel) si des tâches ont une catégorie différente de celle de leur projet : lancer d'abord
-- `php bin/report-project-conflicts`, arbitrer à la main, puis migrer.

CREATE TABLE projects (
  id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name     text NOT NULL,
  category text NOT NULL,
  CONSTRAINT projects_name_not_blank CHECK (btrim(name) <> '' AND char_length(name) <= 255),
  CONSTRAINT projects_category_valid CHECK (category IN ('Pro', 'Perso'))
);
CREATE UNIQUE INDEX projects_name_lower_key ON projects (lower(name));

INSERT INTO projects (name, category) VALUES
  ('MS-HR', 'Pro'), ('Jardin/Maison', 'Perso'), ('OPF', 'Perso'), ('Erendil', 'Perso'),
  ('Divers Pro', 'Pro'), ('Divers Perso', 'Perso');

ALTER TABLE tasks ADD COLUMN project_id bigint;

-- 1. Garde : une tâche avec projet dont la catégorie diffère de celle de ce projet est un conflit.
--    Même règle que bin/report-project-conflicts (un test vérifie l'identité des deux ensembles).
DO $$
DECLARE
  n      bigint;
  detail text;
BEGIN
  SELECT count(*) INTO n
    FROM tasks t JOIN projects p ON p.name = t.project
   WHERE t.category <> p.category;
  IF n > 0 THEN
    SELECT string_agg(
             format('#%s « %s » (catégorie %s, projet %s, attendue %s)', c.id, left(c.title, 80), c.category, c.project, c.expected),
             '; ' ORDER BY c.id)
      INTO detail
      FROM (SELECT t.id, t.title, t.category, t.project, p.category AS expected
              FROM tasks t JOIN projects p ON p.name = t.project
             WHERE t.category <> p.category
             ORDER BY t.id
             LIMIT 50) c;
    RAISE EXCEPTION 'Migration 0004 refusée : % tâche(s) dont la catégorie diffère de celle de leur projet (50 premières) : %. Arbitrer à la main (php bin/report-project-conflicts) puis relancer.', n, detail;
  END IF;
END
$$;

-- 2. Rattachement : n'écrit que project_id (updated_at et état conservés); sans projet -> accueil de la catégorie.
UPDATE tasks t SET project_id = p.id
  FROM projects p
 WHERE p.name = COALESCE(t.project, CASE t.category WHEN 'Pro' THEN 'Divers Pro' ELSE 'Divers Perso' END);

-- 3. Verrouillage du modèle : la catégorie ne vit plus que dans projects (DROP COLUMN emporte
--    tasks_category_valid et tasks_project_valid).
ALTER TABLE tasks
  ALTER COLUMN project_id SET NOT NULL,
  ADD CONSTRAINT tasks_project_id_fkey FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
  DROP COLUMN category,
  DROP COLUMN project;

CREATE INDEX tasks_project_id_idx ON tasks (project_id);
