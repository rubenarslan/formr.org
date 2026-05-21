<?php
use PHPUnit\Framework\TestCase;

/**
 * Pin the contract of Model::isOwnedBy — the canonical primitive for
 * FK-relink ownership checks. Survey::create, Email::create, and any
 * future caller that re-points a *_id from request input to another row
 * delegate here; the security guarantee is that this method returns
 * false on missing row, NULL stored owner, mismatched owner, or
 * zero/empty inputs, and never throws.
 *
 * Worth a unit test independent of the call sites: a silent failure
 * here (e.g. loose comparison letting "1" match int 1 against a wrong
 * user_id of 11) would compromise every caller at once.
 */
class IsOwnedByTest extends TestCase
{
    /** @var \PDO */
    private $pdo;
    /** @var Model */
    private $model;

    protected function setUp(): void
    {
        // Reset the DB singleton so this test owns the SQLite handle —
        // tests/bootstrap.php pre-creates survey_studies / survey_users
        // on its own connection; we want a clean one.
        $ref = new ReflectionProperty(DB::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        Config::initialize([
            'database' => (object) [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);

        $db = DB::getInstance();
        $this->pdo = $db->pdo();
        $this->pdo->exec('CREATE TABLE owned (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            account_id INTEGER
        )');
        $this->pdo->exec('INSERT INTO owned (id, user_id, account_id) VALUES
            (1, 42, 7),
            (2, 99, NULL),
            (3, NULL, 7)
        ');

        // isOwnedBy is a public method on the base Model — we just need
        // any concrete instance to invoke it. Avoid Model directly since
        // its constructor calls boot(); use newInstanceWithoutConstructor
        // and inject db ourselves.
        $modelRef = new ReflectionClass(Model::class);
        $this->model = $modelRef->newInstanceWithoutConstructor();
        $dbProp = $modelRef->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($this->model, $db);
    }

    public function testMatchingOwnerReturnsTrue(): void
    {
        $this->assertTrue($this->model->isOwnedBy('owned', 1, 42));
    }

    public function testMismatchedOwnerReturnsFalse(): void
    {
        $this->assertFalse($this->model->isOwnedBy('owned', 1, 7));
        // The classic "11 matches 1 by string-prefix" attack: with loose
        // comparison this would slip through. isOwnedBy casts both sides
        // to int, so 11 != 42 as ints.
        $this->assertFalse($this->model->isOwnedBy('owned', 1, 421));
    }

    public function testMissingRowReturnsFalse(): void
    {
        $this->assertFalse($this->model->isOwnedBy('owned', 999, 42));
    }

    public function testNullStoredOwnerReturnsFalse(): void
    {
        // Row 3 has user_id IS NULL — must never match any caller-supplied
        // user_id, including 0 (which would otherwise sneak through a
        // (int)null === (int)0 comparison).
        $this->assertFalse($this->model->isOwnedBy('owned', 3, 0));
        $this->assertFalse($this->model->isOwnedBy('owned', 3, 42));
    }

    public function testZeroIdReturnsFalse(): void
    {
        // Empty / zero id means "no FK" — must short-circuit to false
        // without querying. Same for user_id.
        $this->assertFalse($this->model->isOwnedBy('owned', 0, 42));
        $this->assertFalse($this->model->isOwnedBy('owned', 1, 0));
        $this->assertFalse($this->model->isOwnedBy('owned', null, 42));
        $this->assertFalse($this->model->isOwnedBy('owned', 1, null));
    }

    public function testCustomOwnerColumn(): void
    {
        // Email::create uses owner_col='user_id' (the default), but
        // future callers may legitimately need a different column name —
        // e.g. a study owned by an account. Make sure the parameter
        // actually plumbs through.
        $this->assertTrue($this->model->isOwnedBy('owned', 1, 7, 'account_id'));
        $this->assertFalse($this->model->isOwnedBy('owned', 1, 99, 'account_id'));
    }

    public function testStringIdsAreCoercedNumerically(): void
    {
        // Callers occasionally pass id as string (from $_POST). The
        // contract is that we cast to int, not that we do loose ==.
        $this->assertTrue($this->model->isOwnedBy('owned', '1', '42'));
        $this->assertFalse($this->model->isOwnedBy('owned', '1', '7'));
    }
}
