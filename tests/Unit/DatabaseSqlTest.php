<?php

namespace Tests\Unit;

use App\Services\DatabaseDumper;
use App\Services\DatabaseSql;
use Tests\TestCase;

class DatabaseSqlTest extends TestCase
{
    public function test_statements_split_on_semicolons_outside_quotes_and_comments(): void
    {
        $sql = new DatabaseSql;

        $this->assertSame(['SELECT 1', 'SELECT 2'], $sql->split("SELECT 1; SELECT 2;"));
        $this->assertSame(["SELECT ';'", 'SELECT 2'], $sql->split("SELECT ';'; SELECT 2"));
        $this->assertSame(['SELECT "a;b"', 'SELECT 2'], $sql->split('SELECT "a;b"; SELECT 2'));
        $this->assertSame(['SELECT `a;b`', 'SELECT 2'], $sql->split('SELECT `a;b`; SELECT 2'));
        $this->assertSame(['SELECT 1 /* ; */', 'SELECT 2'], $sql->split("SELECT 1 /* ; */; SELECT 2"));
        $this->assertSame(["SELECT 1 -- ;\nSELECT 2"], $sql->split("SELECT 1 -- ;\nSELECT 2"));
        $this->assertSame(['SELECT 1', "-- note\nSELECT 2"], $sql->split("SELECT 1; -- note\nSELECT 2"));
        $this->assertSame([], $sql->split('   ;   '));
    }

    public function test_file_statements_and_hidden_databases_are_refused(): void
    {
        config(['database.connections.mysql.database' => 'laravelify']);
        $sql = new DatabaseSql;

        $this->assertSame('Writing a file on the server is not allowed.', $sql->rejection("SELECT * INTO OUTFILE '/tmp/a' FROM people"));
        $this->assertSame('Writing a file on the server is not allowed.', $sql->rejection("SELECT * INTO\nDUMPFILE '/tmp/a' FROM people"));
        $this->assertSame('Reading a file on the server is not allowed.', $sql->rejection("LOAD DATA INFILE '/tmp/a' INTO TABLE people"));
        $this->assertSame('Reading a file on the server is not allowed.', $sql->rejection("SELECT LOAD_FILE('/etc/passwd')"));
        $this->assertSame('Stay in this database. Open the other one to run SQL there.', $sql->rejection('USE other_db'));
        $this->assertSame('That database is not available here.', $sql->rejection('SELECT * FROM mysql.user'));
        $this->assertSame('That database is not available here.', $sql->rejection('SELECT * FROM `information_schema`.tables'));
        $this->assertSame('That database is not available here.', $sql->rejection('SELECT * FROM laravelify.users'));
        $this->assertSame('That database is not available here.', $sql->rejection('SELECT * FROM performance_schema.users'));
        $this->assertNull($sql->rejection("SELECT 'mysql' FROM people WHERE note = 'INTO OUTFILE'"));
        $this->assertNull($sql->rejection('SELECT sysdate()'));
        $this->assertNull($sql->rejection('SELECT * FROM people'));
    }

    public function test_visible_rows_become_insert_statements(): void
    {
        $body = (new DatabaseSql)->inserts('people', ['firstname', 'city'], [
            ['firstname' => "O'Brien", 'city' => null],
            ['firstname' => "line\n2", 'city' => 'Baku'],
        ]);

        $this->assertSame(
            "INSERT INTO `people` (`firstname`, `city`) VALUES ('O\\'Brien', NULL);\nINSERT INTO `people` (`firstname`, `city`) VALUES ('line\\n2', 'Baku');\n",
            $body,
        );
        $this->assertSame("INSERT INTO `rows` (`n`) VALUES ('1');\n", (new DatabaseSql)->inserts('bad name', ['n'], [['n' => '1']]));
    }

    public function test_export_flags_follow_the_contents_and_the_routines_choice(): void
    {
        $both = DatabaseDumper::exportFlags('both', true);
        $this->assertContains('--routines', $both);
        $this->assertContains('--events', $both);
        $this->assertNotContains('--no-data', $both);
        $this->assertNotContains('--skip-triggers', $both);

        $plain = DatabaseDumper::exportFlags('both', false);
        $this->assertContains('--skip-triggers', $plain);
        $this->assertContains('--skip-routines', $plain);
        $this->assertNotContains('--routines', $plain);

        $structure = DatabaseDumper::exportFlags('structure', true);
        $this->assertContains('--no-data', $structure);
        $this->assertContains('--routines', $structure);

        $data = DatabaseDumper::exportFlags('data', true);
        $this->assertContains('--no-create-info', $data);
        $this->assertContains('--skip-routines', $data);
        $this->assertNotContains('--routines', $data);
    }
}
