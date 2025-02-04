-- Modify the survey_runs table to store VAPID keys
ALTER TABLE survey_runs 
    ADD COLUMN vapid_public_key TEXT NOT NULL,
    ADD COLUMN vapid_private_key TEXT NOT NULL;

-- Create the push_logs table for logging notifications
CREATE TABLE push_logs (
    id SERIAL PRIMARY KEY,
    session_id INT NOT NULL,
    run_id INT NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(10) CHECK (status IN ('success', 'failed')) NOT NULL,
    error_message TEXT NULL,
    attempt INT DEFAULT 1 NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES survey_run_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (run_id) REFERENCES survey_runs(id) ON DELETE CASCADE
);