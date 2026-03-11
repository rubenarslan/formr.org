<?php

/**
 * Monitors survey-related resources: survey count and survey items database size.
 */
class SurveyMonitor {

    /**
     * @var DB
     */
    protected $db;

    public function __construct(DB $db) {
        $this->db = $db;
    }

    /**
     * Get the number of surveys created by a user.
     *
     * @param int $userId
     * @return int
     */
    public function getSurveyCount($userId) {
        return (int) $this->db->count('survey_studies', ['user_id' => $userId]);
    }

    /**
     * Get the size in KB of survey items (survey_items + survey_item_choices) for each survey belonging to a user.
     *
     * @param int $userId
     * @return array Array of ['study_id' => int, 'items_size_kb' => float]
     */
    public function getSurveyItemsSizes($userId) {
        $query = "
            SELECT
                s.id AS study_id,
                ROUND(
                    (
                        (SELECT COALESCE(SUM(
                            LENGTH(COALESCE(si.label, '')) +
                            LENGTH(COALESCE(si.label_parsed, '')) +
                            LENGTH(COALESCE(si.showif, '')) +
                            LENGTH(COALESCE(si.value, '')) +
                            LENGTH(COALESCE(si.type_options, '')) +
                            LENGTH(COALESCE(si.post_process, '')) +
                            LENGTH(COALESCE(si.name, '')) +
                            LENGTH(COALESCE(si.type, '')) +
                            LENGTH(COALESCE(si.choice_list, ''))
                        ), 0) FROM survey_items si WHERE si.study_id = s.id) +
                        (SELECT COALESCE(SUM(
                            LENGTH(COALESCE(sic.label, '')) +
                            LENGTH(COALESCE(sic.label_parsed, '')) +
                            LENGTH(COALESCE(sic.name, '')) +
                            LENGTH(COALESCE(sic.list_name, ''))
                        ), 0) FROM survey_item_choices sic WHERE sic.study_id = s.id)
                    ) / 1024, 2
                ) AS items_size_kb
            FROM survey_studies s
            WHERE s.user_id = :user_id
        ";
        $rows = $this->db->execute($query, ['user_id' => $userId]);
        return array_map(function ($row) {
            return [
                'study_id' => (int) $row['study_id'],
                'items_size_kb' => (float) $row['items_size_kb'],
            ];
        }, $rows);
    }
}
