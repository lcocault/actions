-- Cycle de vie d'une tâche : état et date de clôture.
-- Additive : les tâches existantes reçoivent 'todo' et aucune clôture (défaut constant, sans UPDATE).
ALTER TABLE tasks
  ADD COLUMN status       text        NOT NULL DEFAULT 'todo',
  ADD COLUMN completed_at timestamptz,
  ADD CONSTRAINT tasks_status_valid CHECK (status IN ('todo', 'in_progress', 'done')),
  ADD CONSTRAINT tasks_completed_at_consistent CHECK ((status = 'done') = (completed_at IS NOT NULL));
