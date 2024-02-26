ALTER TABLE survey_runs
ADD watermark_method ENUM('none', 'only_visible', 'only_sift', 'only_blind', 'visible_and_sift', 'visible_and_blind') NOT NULL DEFAULT 'none',
ADD watermark_content varchar(255) NOT NULL DEFAULT "formr.org";
ADD watermark_path varchar(255) DEFAULT 'non';