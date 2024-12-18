<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB class
 */
class DBTest extends TestCase
{
    /**
     * @var DB
     */
    protected $db;

    /**
     * Set up the testing environment
     */
    protected function setUp(): void
    {
        // Mock the Config class to return SQLite in-memory database settings
		$config = [
			'driver' => 'sqlite',
			'database' => ':memory:',
		];
	
        // Simulate Config::get('database') method
        Config::initialize(array('database' => (object) $config));

        // Initialize the DB singleton instance
        $this->db = DB::getInstance();

        // Create a test table
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                age INTEGER NOT NULL
            )
        ");
    }

    /**
     * Test the execute method for fetching results
     */
    public function testExecuteFetchAll()
    {
        // Insert test data
        $this->db->exec("INSERT INTO users (username, email, age) VALUES ('john_doe', 'john@example.com', 30)");
        $this->db->exec("INSERT INTO users (username, email, age) VALUES ('jane_doe', 'jane@example.com', 25)");

        // Fetch all users
        $results = $this->db->execute("SELECT * FROM users");

        $this->assertCount(2, $results);
        $this->assertEquals('john_doe', $results[0]['username']);
        $this->assertEquals('jane_doe', $results[1]['username']);
    }

    /**
     * Test the execute method for fetching a single column
     */
    public function testExecuteFetchColumn()
    {
        // Insert test data
        $this->db->exec("INSERT INTO users (username, email, age) VALUES ('john_doe', 'john@example.com', 30)");

        // Fetch a single column
        $email = $this->db->execute("SELECT email FROM users WHERE username = ?", ['john_doe'], true);

        $this->assertEquals('john@example.com', $email);
    }

    /**
     * Test the insert method
     */
    public function testInsert()
    {
        // Insert data
        $lastInsertId = $this->db->insert('users', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'age' => 28,
        ]);

        $this->assertEquals(1, $lastInsertId);

        // Verify the data was inserted
        $user = $this->db->findRow('users', ['id' => $lastInsertId]);

        $this->assertEquals('alice', $user['username']);
        $this->assertEquals('alice@example.com', $user['email']);
        $this->assertEquals(28, $user['age']);
    }

    /**
     * Test the update method
     */
    public function testUpdate()
    {
        // Insert test data
        $this->db->insert('users', [
            'username' => 'bob',
            'email' => 'bob@example.com',
            'age' => 35,
        ]);

        // Update the user's age
        $affectedRows = $this->db->update('users', ['age' => 36], ['username' => 'bob']);

        $this->assertEquals(1, $affectedRows);

        // Verify the data was updated
        $user = $this->db->findRow('users', ['username' => 'bob']);

        $this->assertEquals(36, $user['age']);
    }

    /**
     * Test the delete method
     */
    public function testDelete()
    {
        // Insert test data
        $this->db->insert('users', [
            'username' => 'charlie',
            'email' => 'charlie@example.com',
            'age' => 40,
        ]);

        // Delete the user
        $affectedRows = $this->db->delete('users', ['username' => 'charlie']);

        $this->assertEquals(1, $affectedRows);

        // Verify the user was deleted
        $exists = $this->db->entry_exists('users', ['username' => 'charlie']);

        $this->assertFalse($exists);
    }

    /**
     * Test for SQL injection prevention in execute method
     */
    public function testExecuteSqlInjectionPrevention()
    {
        // Insert test data
        $this->db->insert('users', [
            'username' => 'dave',
            'email' => 'dave@example.com',
            'age' => 22,
        ]);

        // Attempt SQL injection via parameters
        $maliciousUsername = "dave'; DROP TABLE users; --";
        $results = $this->db->execute("SELECT * FROM users WHERE username = ?", [$maliciousUsername]);

        // Verify that the table still exists and no data was returned
        $tableExists = $this->db->table_exists('users');
        $this->assertTrue($tableExists);
        $this->assertCount(0, $results);
    }

    /**
     * Test for SQL injection prevention in insert method
     */
    public function testInsertSqlInjectionPrevention()
    {
        $maliciousEmail = "hacker@example.com'); DROP TABLE users; --";

        // Insert data with malicious input
        $this->db->insert('users', [
            'username' => 'eve',
            'email' => $maliciousEmail,
            'age' => 29,
        ]);

        // Verify that the table still exists and data was inserted safely
        $tableExists = $this->db->table_exists('users');
        $this->assertTrue($tableExists);

        $user = $this->db->findRow('users', ['username' => 'eve']);

        $this->assertEquals($maliciousEmail, $user['email']);
    }

    /**
     * Test the table_exists method
     */
    public function testTableExists()
    {
        $exists = $this->db->table_exists('users');
        $this->assertTrue($exists);

        $notExists = $this->db->table_exists('nonexistent_table');
        $this->assertFalse($notExists);
    }

    /**
     * Test that invalid table names are rejected
     */
    public function testTableExistsInvalidName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table_exists('users; DROP TABLE users;');
    }

    /**
     * Test the count method
     */
    public function testCount()
    {
        // Insert test data
        $this->db->insert('users', ['username' => 'user1', 'email' => 'user1@example.com', 'age' => 20]);
        $this->db->insert('users', ['username' => 'user2', 'email' => 'user2@example.com', 'age' => 25]);

        $count = $this->db->count('users');
        $this->assertEquals(2, $count);

        $countWhere = $this->db->count('users', ['age >' => 21]);
        $this->assertEquals(1, $countWhere);
    }

    /**
     * Test the transaction methods
     */
    public function testTransactions()
    {
        $this->db->beginTransaction();

        // Insert data within transaction
        $this->db->insert('users', ['username' => 'transact_user', 'email' => 'transact@example.com', 'age' => 33]);

        // Verify data is present before commit
        $user = $this->db->findRow('users', ['username' => 'transact_user']);
        $this->assertEquals('transact_user', $user['username']);

        $this->db->rollBack();

        // Verify data was rolled back
        $user = $this->db->findRow('users', ['username' => 'transact_user']);
        $this->assertNull($user);
    }

    /**
     * Test the select method with whereIn
     */
    public function testSelectWhereIn()
    {
        // Insert test data
        $this->db->insert('users', ['username' => 'userA', 'email' => 'userA@example.com', 'age' => 20]);
        $this->db->insert('users', ['username' => 'userB', 'email' => 'userB@example.com', 'age' => 25]);
        $this->db->insert('users', ['username' => 'userC', 'email' => 'userC@example.com', 'age' => 30]);

        $select = $this->db->select()->from('users')->whereIn('username', ['userA', 'userC']);
        $results = $select->fetchAll();

        $this->assertCount(2, $results);
        $usernames = array_column($results, 'username');
        $this->assertContains('userA', $usernames);
        $this->assertContains('userC', $usernames);
    }

    /**
     * Test the getTableDefinition method
     */
    public function testGetTableDefinition()
    {
        $definition = $this->db->getTableDefinition('users');

        $this->assertNotEmpty($definition);
        $this->assertEquals('id', $definition[0]['Field']);
        $this->assertEquals('username', $definition[1]['Field']);
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void
    {
        // Drop the test table
        $this->db->exec("DROP TABLE IF EXISTS users");
        $this->db = null;
    }
}
