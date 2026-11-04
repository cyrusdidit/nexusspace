-- Allow one Steam account to be linked to multiple NexusSpace users.
ALTER TABLE steam_connections
    DROP INDEX uq_steam_connections_steam_id,
    ADD INDEX idx_steam_connections_steam_id (steam_id);
