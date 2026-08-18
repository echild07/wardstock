-- WardStock — track last Oura connection success/attempt
-- Run this after alter_add_oura_tokens.sql (if you haven't deployed the Oura
-- feature yet at all, run that one first, then this one).
-- Safe, additive change.

ALTER TABLE oura_tokens
  ADD COLUMN last_success_at DATETIME NULL AFTER expires_at,
  ADD COLUMN last_attempt_at DATETIME NULL AFTER last_success_at,
  ADD COLUMN last_attempt_ok TINYINT(1) NULL AFTER last_attempt_at;
