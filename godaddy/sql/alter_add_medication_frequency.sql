-- WardStock — add real due-date computation for medications
-- Safe, additive change. Run once in phpMyAdmin's SQL tab.
--
-- Why: the daily checklist and dashboard only checked whether a medication's
-- start_date/end_date made it "currently active" — they never checked
-- whether TODAY was actually one of its due days. A weekly medication like
-- Wegovy or a biweekly one like Repatha was showing up (and could be marked
-- "taken" via All Taken) on every single day, not just the days it's
-- actually scheduled. This adds a frequency_days column and backfills it
-- from each medication's existing cadence label, so due-date math can use
-- (day - start_date) % frequency_days == 0 — the same approach already
-- used for therapy_schedules.

ALTER TABLE medications
  ADD COLUMN frequency_days INT NOT NULL DEFAULT 1 AFTER cadence;

UPDATE medications SET frequency_days = CASE
    WHEN cadence LIKE '%biweekly%' OR cadence LIKE '%every 2 week%' THEN 14
    WHEN cadence LIKE '%weekly%' THEN 7
    WHEN cadence LIKE '%every other day%' THEN 2
    WHEN cadence LIKE '%monthly%' THEN 30
    ELSE 1
END
WHERE med_type = 'scheduled';
