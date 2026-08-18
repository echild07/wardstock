-- WardStock — add Oura Ring integration
-- Safe, additive change. Run once in phpMyAdmin's SQL tab.

CREATE TABLE IF NOT EXISTS oura_tokens (
    id INT PRIMARY KEY DEFAULT 1,          -- always exactly one row, single-user app
    access_token TEXT,
    refresh_token TEXT,
    expires_at DATETIME,
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
