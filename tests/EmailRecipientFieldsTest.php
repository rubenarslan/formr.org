<?php
use PHPUnit\Framework\TestCase;

/**
 * The Email unit offers the run's surveys' email items as recipients
 * (survey$item). An item removed from its survey (soft-deleted, patch 112)
 * is not in the data R reads, so as a recipient it is always NULL and the
 * email is never sent: it must not be offered.
 */
class EmailRecipientFieldsTest extends TestCase
{
    /** @var DB */
    private $db;

    protected function setUp(): void
    {
        $this->db = DB::getInstance();
        $this->drop();
        $this->db->exec('CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY, run_id INTEGER, unit_id INTEGER, position INTEGER)');
        $this->db->exec('CREATE TABLE survey_studies (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('CREATE TABLE survey_items (id INTEGER PRIMARY KEY, study_id INTEGER, name TEXT, type TEXT, deleted TEXT)');
        $this->db->exec("INSERT INTO survey_studies (id, name) VALUES (5, 'intake')");
        $this->db->exec('INSERT INTO survey_run_units (run_id, unit_id, position) VALUES (1, 5, 10)');
        $this->db->exec("INSERT INTO survey_items (study_id, name, type, deleted) VALUES
            (5, 'mail', 'email', NULL), (5, 'email', 'email', '2026-10-01 10:00:00'), (5, 'age', 'number', NULL)");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop()
    {
        foreach (array('survey_run_units', 'survey_studies', 'survey_items') as $t) {
            $this->db->exec("DROP TABLE IF EXISTS $t");
        }
    }

    public function testARemovedEmailItemIsNotOffered()
    {
        $email = (new ReflectionClass(Email::class))->newInstanceWithoutConstructor();
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $run->id = 1;
        foreach (array('db' => $this->db, 'run' => $run) as $prop => $value) {
            $p = new ReflectionProperty(Email::class, $prop);
            $p->setAccessible(true);
            $p->setValue($email, $value);
        }
        $fields = new ReflectionMethod(Email::class, 'getPotentialRecipientFields');
        $fields->setAccessible(true);
        $ids = array_column($fields->invoke($email), 'id');
        $this->assertContains('intake$mail', $ids);
        $this->assertNotContains('intake$email', $ids);
    }
}
