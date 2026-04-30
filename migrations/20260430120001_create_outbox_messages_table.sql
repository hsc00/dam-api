-- Create outbox_messages table for DB outbox pattern
CREATE TABLE IF NOT EXISTS outbox_messages (
  id CHAR(36) NOT NULL PRIMARY KEY,
  `queue` VARCHAR(255) NOT NULL,
  payload JSON NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  published_at DATETIME(6) NULL,
  INDEX idx_queue_published (`queue`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
