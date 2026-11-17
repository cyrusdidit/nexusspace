ALTER TABLE friends
    ADD COLUMN dashboard_pinned_at TIMESTAMP(6) NULL DEFAULT NULL AFTER top_eight_position;
