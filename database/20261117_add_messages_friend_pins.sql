ALTER TABLE friends
    ADD COLUMN messages_pinned_at TIMESTAMP(6) NULL DEFAULT NULL AFTER dashboard_pinned_at,
    ADD KEY friends_messages_pins (user_id, messages_pinned_at);
