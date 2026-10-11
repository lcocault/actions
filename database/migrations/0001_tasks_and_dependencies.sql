-- 0001 : tâches, dépendances entre tâches, refus des cycles (docs/architecture.md, « Données et migrations »).
-- Pas de BEGIN/COMMIT : la transaction est portée par bin/migrate.

CREATE TABLE tasks (
  id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  title               text        NOT NULL,
  category            text        NOT NULL,
  due_date            date,
  recurrence_interval integer,
  recurrence_unit     text,
  estimated_minutes   integer,
  description         text,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT tasks_title_not_blank CHECK (btrim(title) <> '' AND char_length(title) <= 255),
  CONSTRAINT tasks_category_valid CHECK (category IN ('Pro', 'Perso')),
  CONSTRAINT tasks_recurrence_valid CHECK (
    (recurrence_interval IS NULL AND recurrence_unit IS NULL)
    OR (recurrence_interval IS NOT NULL AND recurrence_unit IS NOT NULL
        AND recurrence_interval > 0 AND recurrence_unit IN ('day', 'week', 'month', 'year'))
  ),
  CONSTRAINT tasks_recurrence_needs_due_date CHECK (recurrence_interval IS NULL OR due_date IS NOT NULL),
  CONSTRAINT tasks_estimated_minutes_positive CHECK (estimated_minutes IS NULL OR estimated_minutes > 0)
);

CREATE INDEX tasks_due_date_idx ON tasks (due_date) WHERE due_date IS NOT NULL;

-- La ligne (task_id, required_task_id) signifie : task_id requiert required_task_id.
CREATE TABLE task_dependencies (
  task_id          bigint NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
  required_task_id bigint NOT NULL REFERENCES tasks (id) ON DELETE RESTRICT,
  PRIMARY KEY (task_id, required_task_id),
  CONSTRAINT task_dependencies_no_self CHECK (task_id <> required_task_id)
);

CREATE INDEX task_dependencies_required_idx ON task_dependencies (required_task_id);

CREATE FUNCTION task_dependencies_check_cycle() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  -- L'auto-dépendance est refusée par le CHECK task_dependencies_no_self (les triggers BEFORE passent avant les CHECK).
  IF NEW.task_id = NEW.required_task_id THEN
    RETURN NEW;
  END IF;

  -- Sérialise toute écriture de lien; relâché au COMMIT/ROLLBACK. Ne pas écrire de liens en REPEATABLE READ/SERIALIZABLE.
  PERFORM pg_advisory_xact_lock(hashtext('actions.task_dependencies'));

  -- Le nouveau lien ferme un cycle si task_id est atteignable depuis required_task_id en suivant « requiert ».
  -- UPDATE : l'ancienne version de la ligne est encore visible, elle est exclue du parcours.
  IF EXISTS (
    WITH RECURSIVE reach(id) AS (
      SELECT NEW.required_task_id
      UNION
      SELECT d.required_task_id
      FROM task_dependencies d
      JOIN reach ON d.task_id = reach.id
      WHERE NOT (TG_OP = 'UPDATE' AND d.task_id = OLD.task_id AND d.required_task_id = OLD.required_task_id)
    )
    SELECT 1 FROM reach WHERE id = NEW.task_id
  ) THEN
    RAISE EXCEPTION 'cycle de dépendances refusé (contrainte task_dependencies_no_cycle)'
      USING ERRCODE = '23514',
            CONSTRAINT = 'task_dependencies_no_cycle',
            SCHEMA = 'public',
            TABLE = 'task_dependencies';
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER task_dependencies_no_cycle
  BEFORE INSERT OR UPDATE OF task_id, required_task_id ON task_dependencies
  FOR EACH ROW EXECUTE FUNCTION task_dependencies_check_cycle();
