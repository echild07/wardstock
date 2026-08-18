-- Lucius — add ha_sync_log table (Home Assistant sync logging)
-- Safe, additive.

CREATE TABLE IF NOT EXISTS ha_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(30) NOT NULL,
    called_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code VARCHAR(30) NOT NULL,
    detail TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
