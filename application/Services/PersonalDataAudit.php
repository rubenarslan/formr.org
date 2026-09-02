<?php

/**
 * Superadmin overview of which admins collect *likely* personal data, in
 * which runs and surveys.
 *
 * This is a heuristic, deliberately shallow: some item types collect
 * personal data by construction (email, tel, geopoint, …), and for
 * free-response types the item name / label is scanned for keywords
 * ("phone", "date of birth", "IBAN", "prolific", …). A DB query cannot
 * find every personal-data item — a plain text item labelled "Tell us
 * about yourself" is invisible to it — so the page is a starting point
 * for a conversation with the admin, not an audit result. Every hit
 * carries a `reason` so the superadmin can dismiss false positives
 * (a "phone" question about phone *ownership*) at a glance.
 *
 * Extend `KEYWORDS` / `TYPE_CATEGORIES` when an instance's studies use
 * vocabulary that isn't covered; the categories are only used for
 * badges and totals, so adding one needs no template change beyond a
 * label in `categoryLabels()`.
 */
class PersonalDataAudit {

    /** Item types that collect personal data whatever they are called. */
    const TYPE_CATEGORIES = array(
        'email' => 'contact',
        'tel' => 'contact',
        'request_phone' => 'contact',
        'geopoint' => 'location',
        'file' => 'uploads',
        'ip' => 'technical',
        'browser' => 'technical',
        'server' => 'technical',
        'referrer' => 'technical',
        'push_notification' => 'technical',
    );

    /**
     * Free-response / capture types whose name and label decide. Choice
     * items (mc, select, …) are skipped: their answers are bounded by the
     * choice list, which rarely holds personal data.
     */
    const TEXT_TYPES = array(
        'text', 'textarea', 'number', 'letters', 'url', 'date', 'datetime',
        'datetime-local', 'get', 'hidden',
    );

    /**
     * Lower-case keywords, matched at a word start against the item name
     * (camelCase and snake_case split into words) and the label. A
     * keyword matches as a *prefix* ("birth" hits "birthday"); the ones
     * listed in EXACT_KEYWORDS must be a whole word ("zip" must not hit
     * "zipper", "ssn" must not hit "lessness").
     */
    const KEYWORDS = array(
        'contact' => array(
            'phone', 'telef', 'telep', 'tel', 'mobil', 'handy', 'whatsapp',
            'signal', 'telegram', 'email', 'e-mail', 'mail',
        ),
        'identity' => array(
            'vorname', 'nachname', 'surname', 'first name', 'last name',
            'full name', 'family name', 'given name', 'your name', 'ihr name',
            'dein name', 'birth', 'geburt', 'dob',
        ),
        'address' => array(
            'address', 'adresse', 'anschrift', 'street', 'straße', 'strasse',
            'postcode', 'postal', 'zip', 'plz', 'wohnort',
        ),
        'ids' => array(
            'iban', 'bank', 'konto', 'account number', 'matrikel',
            'student id', 'student number', 'passport', 'social security',
            'ssn', 'personalausweis', 'insurance', 'versicherung', 'prolific',
            'mturk', 'worker id', 'sona id', 'sona',
        ),
    );

    const EXACT_KEYWORDS = array('tel', 'mail', 'dob', 'zip', 'plz', 'ssn', 'bank', 'sona');

    public static function categoryLabels() {
        return array(
            'contact' => 'Contact details',
            'identity' => 'Name / date of birth',
            'address' => 'Postal address',
            'ids' => 'External IDs / financial',
            'location' => 'Geolocation',
            'uploads' => 'File uploads',
            'technical' => 'IP / browser / referrer',
        );
    }

    /**
     * Classify one item. Returns null when nothing looks personal, else
     * array('category' => …, 'reason' => …).
     */
    public static function classify($type, $name, $label) {
        $type = strtolower(trim((string) $type));
        if (isset(self::TYPE_CATEGORIES[$type])) {
            return array('category' => self::TYPE_CATEGORIES[$type], 'reason' => 'item type "' . $type . '"');
        }
        if (!in_array($type, self::TEXT_TYPES, true)) {
            return null;
        }
        $name_words = self::words($name, true);
        $label_words = self::words(strip_tags((string) $label), false);
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (self::matches($keyword, $name_words)) {
                    return array('category' => $category, 'reason' => 'name contains "' . $keyword . '"');
                }
            }
        }
        // Labels are noisier ("Do you own a phone?"), so the name wins
        // above and the label is a second pass.
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (self::matches($keyword, $label_words)) {
                    return array('category' => $category, 'reason' => 'label mentions "' . $keyword . '"');
                }
            }
        }
        return null;
    }

    /**
     * Normalise text to lower-case words separated by single spaces.
     * Names get camelCase split ("firstName" → "first name") so the same
     * keywords work for names and labels.
     */
    private static function words($text, $split_camel) {
        $text = (string) $text;
        if ($split_camel) {
            $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        }
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return ' ' . trim($text) . ' ';
    }

    private static function matches($keyword, $words) {
        $kw = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $keyword);
        $exact = in_array($keyword, self::EXACT_KEYWORDS, true);
        // "first name" also as "firstname"; "e-mail" also as "email" (already
        // listed) — the joined form covers admins who write names without _.
        $variants = array_unique(array($kw, str_replace(' ', '', $kw)));
        foreach ($variants as $variant) {
            $pattern = '/ ' . preg_quote($variant, '/') . ($exact ? ' ' : '') . '/u';
            if (preg_match($pattern, $words)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The whole instance, grouped admin → run → survey → items. Surveys
     * that sit in no run are listed under their owner as orphans (they
     * still hold results). A survey used in several runs appears under
     * each. Runs the report never joins are not personal-data free —
     * they just hold nothing this heuristic can see.
     */
    public static function report() {
        $db = DB::getInstance();
        $items = self::flaggedItems($db);
        $studies = array();
        foreach ($items as $item) {
            $sid = (int) $item['study_id'];
            if (!isset($studies[$sid])) {
                $studies[$sid] = array('id' => $sid, 'name' => $item['study_name'], 'owner_id' => (int) $item['study_owner'], 'real_users' => null, 'finished' => null, 'items' => array());
            }
            $studies[$sid]['items'][] = array('name' => $item['name'], 'type' => $item['type'], 'category' => $item['category'], 'reason' => $item['reason']);
        }
        $study_ids = array_keys($studies);
        foreach (self::studyMetrics($db, $study_ids) as $sid => $m) {
            $studies[$sid]['real_users'] = (int) $m['real_users'];
            $studies[$sid]['finished'] = (int) $m['finished'];
        }

        $admins = array();
        $placed = array();
        $runs_seen = array();
        $run_rows = self::rows($db, 'SELECT ru.unit_id AS study_id, r.id AS run_id, r.name AS run_name, r.public, r.user_id, u.email, u.admin '
                . 'FROM survey_run_units ru JOIN survey_runs r ON r.id = ru.run_id JOIN survey_users u ON u.id = r.user_id '
                . 'WHERE ru.unit_id IN (%s) ORDER BY u.email, r.name, ru.position', $study_ids);
        $run_ids = array_values(array_unique(array_map(function ($r) { return (int) $r['run_id']; }, $run_rows)));
        $sessions = self::countsByRun($db, 'SELECT run_id, COUNT(*) AS n FROM survey_run_sessions WHERE COALESCE(testing, 0) = 0 AND run_id IN (%s) GROUP BY run_id', $run_ids);
        $email_units = self::countsByRun($db, 'SELECT ru.run_id, COUNT(*) AS n FROM survey_run_units ru JOIN survey_units un ON un.id = ru.unit_id '
                . "WHERE un.type = 'Email' AND ru.run_id IN (%s) GROUP BY ru.run_id", $run_ids);
        foreach ($run_rows as $row) {
            $uid = (int) $row['user_id'];
            $rid = (int) $row['run_id'];
            $sid = (int) $row['study_id'];
            if (!isset($admins[$uid])) {
                $admins[$uid] = array('id' => $uid, 'email' => $row['email'], 'admin' => (int) $row['admin'], 'runs' => array(), 'orphan_surveys' => array());
            }
            if (!isset($admins[$uid]['runs'][$rid])) {
                $admins[$uid]['runs'][$rid] = array('id' => $rid, 'name' => $row['run_name'], 'public' => (int) $row['public'],
                    'sessions' => isset($sessions[$rid]) ? $sessions[$rid] : 0, 'email_units' => isset($email_units[$rid]) ? $email_units[$rid] : 0, 'surveys' => array());
            }
            $admins[$uid]['runs'][$rid]['surveys'][$sid] = $studies[$sid];
            $placed[$sid] = true;
            $runs_seen[$rid] = true;
        }

        $orphans = array_diff_key($studies, $placed);
        $owner_ids = array_values(array_unique(array_map(function ($s) { return $s['owner_id']; }, $orphans)));
        $owners = array();
        foreach (self::rows($db, 'SELECT id, email, admin FROM survey_users WHERE id IN (%s)', $owner_ids) as $o) {
            $owners[(int) $o['id']] = $o;
        }
        foreach ($orphans as $sid => $study) {
            $uid = $study['owner_id'];
            if (!isset($admins[$uid])) {
                $o = isset($owners[$uid]) ? $owners[$uid] : array('email' => '(deleted user #' . $uid . ')', 'admin' => 0);
                $admins[$uid] = array('id' => $uid, 'email' => $o['email'], 'admin' => (int) $o['admin'], 'runs' => array(), 'orphan_surveys' => array());
            }
            $admins[$uid]['orphan_surveys'][$sid] = $study;
        }
        uasort($admins, function ($a, $b) { return strcmp((string) $a['email'], (string) $b['email']); });

        $by_category = array();
        foreach ($items as $item) {
            $by_category[$item['category']] = (isset($by_category[$item['category']]) ? $by_category[$item['category']] : 0) + 1;
        }
        arsort($by_category);
        return array(
            'totals' => array('admins' => count($admins), 'runs' => count($runs_seen), 'surveys' => count($studies), 'items' => count($items), 'by_category' => $by_category),
            'admins' => $admins,
        );
    }

    /** Broad SQL prefilter (type list + LIKE on name/label), then classify() decides. */
    private static function flaggedItems($db) {
        $params = array();
        $types = array();
        foreach (array_keys(self::TYPE_CATEGORIES) as $i => $t) {
            $types[] = ':t' . $i;
            $params[':t' . $i] = $t;
        }
        $text_types = array();
        foreach (self::TEXT_TYPES as $i => $t) {
            $text_types[] = ':x' . $i;
            $params[':x' . $i] = $t;
        }
        $likes = array();
        $n = 0;
        foreach (self::KEYWORDS as $keywords) {
            foreach ($keywords as $keyword) {
                $pattern = '%' . str_replace(array(' ', '-'), '%', $keyword) . '%';
                $likes[] = "i.name LIKE :n{$n} OR i.label LIKE :l{$n}";
                $params[":n{$n}"] = $pattern;
                $params[":l{$n}"] = $pattern;
                $n++;
            }
        }
        $sql = 'SELECT i.id, i.study_id, i.name, i.type, i.label, s.name AS study_name, s.user_id AS study_owner '
                . 'FROM survey_items i JOIN survey_studies s ON s.id = i.study_id '
                . 'WHERE i.type IN (' . implode(', ', $types) . ') OR (i.type IN (' . implode(', ', $text_types) . ') AND (' . implode(' OR ', $likes) . ')) '
                . 'ORDER BY i.study_id, i.`order`, i.id';
        $flagged = array();
        foreach ($db->execute($sql, $params) as $row) {
            $hit = self::classify($row['type'], $row['name'], $row['label']);
            if ($hit) {
                $flagged[] = $row + $hit;
            }
        }
        return $flagged;
    }

    private static function studyMetrics($db, array $study_ids) {
        try {
            $out = array();
            foreach (self::rows($db, 'SELECT study_id, real_users, finished FROM survey_study_metrics WHERE study_id IN (%s)', $study_ids) as $m) {
                $out[(int) $m['study_id']] = $m;
            }
            return $out;
        } catch (Exception $e) {
            // Rollup table absent (pre-069 host): counts stay unknown rather than failing the page.
            return array();
        }
    }

    private static function countsByRun($db, $sql, array $run_ids) {
        $out = array();
        foreach (self::rows($db, $sql, $run_ids) as $r) {
            $out[(int) $r['run_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** Run $sql with its `%s` replaced by an IN-list of $ids; no ids → no rows, no query. */
    private static function rows($db, $sql, array $ids) {
        if (!$ids) {
            return array();
        }
        $placeholders = array();
        $params = array();
        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':i' . $i;
            $params[':i' . $i] = (int) $id;
        }
        return $db->execute(sprintf($sql, implode(', ', $placeholders)), $params);
    }

}
