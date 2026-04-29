CREATE TABLE IF NOT EXISTS assets (
    id CHAR(36) NOT NULL,
    upload_id CHAR(36) NOT NULL,
    account_id VARCHAR(255) NOT NULL,
    file_name LONGTEXT COLLATE utf8mb4_0900_ai_ci NOT NULL,
    mime_type LONGTEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    chunk_count INT UNSIGNED NOT NULL,
    completion_proof LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_assets_account_id (account_id),
    UNIQUE KEY uq_assets_upload_id (upload_id),
    CONSTRAINT chk_assets_status CHECK (status IN ('PENDING', 'PROCESSING', 'UPLOADED', 'FAILED')),
    CONSTRAINT chk_assets_chunk_count_positive CHECK (chunk_count >= 1),
    CONSTRAINT chk_assets_completion_proof_matches_status CHECK (
        (status IN ('PROCESSING', 'UPLOADED') AND completion_proof IS NOT NULL AND completion_proof REGEXP '[^[:space:]]')
        OR (status IN ('PENDING', 'FAILED') AND completion_proof IS NULL)
    ),
    CONSTRAINT chk_assets_updated_at_not_before_created_at CHECK (updated_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;