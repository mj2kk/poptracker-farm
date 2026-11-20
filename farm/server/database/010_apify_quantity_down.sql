DROP TABLE production_apify_feed_batches;
ALTER TABLE production_apify_feed_runs DROP COLUMN import_cursor, DROP COLUMN batch_number, DROP COLUMN requested_count;
