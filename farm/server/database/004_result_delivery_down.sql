-- Only for a disposable database or a version that has never issued a v2 attempt.
-- The enforced constraint prevents loss of delivery policy and timing evidence.
ALTER TABLE device_task_attempts ADD CONSTRAINT rollback_no_v2 CHECK (deliver_until IS NULL);
ALTER TABLE device_task_attempts DROP CHECK rollback_no_v2,
    DROP COLUMN reported_finish_ms, DROP COLUMN reported_start_ms, DROP COLUMN deliver_until;
