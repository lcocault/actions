-- Importance d'une tâche : Mineure (défaut), Majeure ou Critique.
-- Additive : les tâches existantes reçoivent 'Mineure' (défaut constant, sans UPDATE).
ALTER TABLE tasks
  ADD COLUMN importance text NOT NULL DEFAULT 'Mineure',
  ADD CONSTRAINT tasks_importance_valid CHECK (importance IN ('Mineure', 'Majeure', 'Critique'));
