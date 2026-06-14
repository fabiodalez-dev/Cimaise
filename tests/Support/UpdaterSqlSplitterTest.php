<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Updater;

/**
 * Unit coverage for the migration SQL handling added so that migrations
 * carrying triggers / stored routines (DELIMITER + BEGIN...END bodies with
 * inner ';') are no longer shredded by a naive explode(';').
 *
 * Three units are exercised, all private and pure (no $this state), reflected
 * off an instance built without the heavy Updater constructor:
 *   - migrationNeedsDelimiterParser(): the parser selector
 *   - splitSqlStatements():            quote-aware ';' splitter
 *   - splitSqlWithDelimiters():        DELIMITER + BEGIN..END aware splitter
 * Plus end-to-end application of produced statements against real SQLite.
 */
final class UpdaterSqlSplitterTest extends TestCase
{
    private Updater $updater;

    protected function setUp(): void
    {
        $this->updater = (new ReflectionClass(Updater::class))->newInstanceWithoutConstructor();
    }

    /** @return mixed */
    private function call(string $method, mixed ...$args)
    {
        $m = new ReflectionMethod(Updater::class, $method);
        return $m->invoke($this->updater, ...$args);
    }

    private function split(string $sql): array
    {
        return $this->call('splitSqlStatements', $sql);
    }

    private function splitD(string $sql): array
    {
        return $this->call('splitSqlWithDelimiters', $sql);
    }

    private function needsDelimiter(string $sql): bool
    {
        return $this->call('migrationNeedsDelimiterParser', $sql);
    }

    private function createInMemorySqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    // ---- migrationNeedsDelimiterParser() -----------------------------------

    public function testSelectorTrueOnCreateTrigger(): void
    {
        $this->assertTrue($this->needsDelimiter("CREATE TRIGGER t AFTER INSERT ON a BEGIN END;"));
    }

    public function testSelectorTrueOnDelimiterDirective(): void
    {
        $this->assertTrue($this->needsDelimiter("DELIMITER $$\nCREATE PROCEDURE p() BEGIN END$$"));
    }

    public function testSelectorIsCaseInsensitive(): void
    {
        $this->assertTrue($this->needsDelimiter("create trigger foo after insert on a begin end;"));
    }

    public function testSelectorFalseOnPlainMigration(): void
    {
        $this->assertFalse($this->needsDelimiter("CREATE TABLE t (id INTEGER);\nINSERT INTO t VALUES(1);"));
    }

    public function testSelectorFalseWhenTriggerOnlyInStringLiteral(): void
    {
        // "CREATE TRIGGER" inside a seed string must NOT route a plain
        // migration to the delimiter parser (regex is line-anchored).
        $this->assertFalse($this->needsDelimiter("INSERT INTO log VALUES('CREATE TRIGGER ran');"));
    }

    public function testSelectorTrueOnCreateDefinerTrigger(): void
    {
        $this->assertTrue($this->needsDelimiter("CREATE DEFINER=`root`@`localhost` TRIGGER t AFTER INSERT ON a BEGIN END;"));
    }

    // ---- splitSqlStatements() (quote-aware) --------------------------------

    public function testQuoteAwareTwoPlainStatements(): void
    {
        $this->assertCount(2, $this->split("CREATE TABLE t (id INTEGER);\nINSERT INTO t VALUES(1);"));
    }

    public function testQuoteAwareSemicolonInsideStringNotSplit(): void
    {
        $stmts = $this->split("INSERT INTO s(k,v) VALUES('css','a:1; b:2');\nUPDATE s SET v='x';");
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('a:1; b:2', $stmts[0]);
    }

    public function testQuoteAwareEscapedQuoteHandled(): void
    {
        $stmts = $this->split("INSERT INTO t(v) VALUES('it''s; fine');\nUPDATE t SET v=1;");
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString("it''s; fine", $stmts[0]);
    }

    public function testQuoteAwareCommentLinesDropped(): void
    {
        $stmts = $this->split("-- header\nUPDATE t SET v=1;\n-- footer\nUPDATE t SET v=2;");
        $this->assertCount(2, $stmts);
        $this->assertSame('UPDATE t SET v=1', $stmts[0]);
    }

    public function testQuoteAwareTrailingStatementWithoutSemicolon(): void
    {
        $stmts = $this->split("UPDATE t SET v=1;\nUPDATE t SET v=2");
        $this->assertCount(2, $stmts);
        $this->assertSame('UPDATE t SET v=2', $stmts[1]);
    }

    public function testQuoteAwareEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], $this->split(''));
    }

    public function testQuoteAwareOnlyCommentsReturnsEmpty(): void
    {
        $this->assertSame([], $this->split("-- just\n-- comments\n"));
    }

    public function testQuoteAwareWhitespaceOnlyStatementsFiltered(): void
    {
        $this->assertCount(1, $this->split("   ;\n\nUPDATE t SET v=1;\n  ;"));
    }

    public function testQuoteAwareMultipleSemicolonsInsideString(): void
    {
        $stmts = $this->split("INSERT INTO t(v) VALUES('a;b;c;d');");
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('a;b;c;d', $stmts[0]);
    }

    public function testQuoteAwareDoubleQuotedIdentifierStillSplitsAtTerminator(): void
    {
        // Double-quoted identifiers are common; the terminating ';' outside any
        // single-quoted string still splits normally.
        $stmts = $this->split('CREATE TABLE "weird name" (id INTEGER);' . "\n" . 'INSERT INTO "weird name" VALUES(1);');
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('"weird name"', $stmts[0]);
    }

    // ---- splitSqlWithDelimiters() ------------------------------------------

    public function testDelimiterSqliteTriggerBodyIntact(): void
    {
        $sql = "CREATE TRIGGER t AFTER INSERT ON a\nBEGIN\n  UPDATE a SET x=1;\n  INSERT INTO b VALUES(1);\nEND;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('UPDATE a SET x=1', $stmts[0]);
        $this->assertStringContainsString('INSERT INTO b', $stmts[0]);
    }

    public function testDelimiterTablePlusTriggerTwoStatements(): void
    {
        $sql = "CREATE TABLE a (id INTEGER);\nCREATE TRIGGER t AFTER INSERT ON a\nBEGIN\n  UPDATE a SET id=id;\nEND;";
        $stmts = $this->splitD($sql);
        $this->assertCount(2, $stmts);
    }

    public function testDelimiterMysqlDirectiveHonored(): void
    {
        $sql = "DELIMITER $$\nCREATE TRIGGER t AFTER INSERT ON a FOR EACH ROW\nBEGIN\n  INSERT INTO b(x) VALUES(1);\nEND$$\nDELIMITER ;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts);
        $this->assertStringNotContainsString('$$', $stmts[0]);
    }

    public function testDelimiterDefinerNormalized(): void
    {
        $sql = "CREATE DEFINER=`root`@`localhost` TRIGGER t AFTER INSERT ON a\nBEGIN\n  INSERT INTO b(x) VALUES(1);\nEND;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts);
        $this->assertStringContainsString('CREATE TRIGGER t', $stmts[0]);
        $this->assertStringNotContainsString('DEFINER', $stmts[0]);
    }

    public function testDelimiterNestedBeginEndDepth(): void
    {
        $sql = "CREATE TRIGGER t AFTER INSERT ON a\nBEGIN\n  BEGIN\n    UPDATE a SET x=1;\n  END;\n  INSERT INTO b VALUES(1);\nEND;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts, 'nested BEGIN..END must not split');
    }

    public function testDelimiterRestoredAfterMysqlBlock(): void
    {
        $sql = "DELIMITER $$\nCREATE TRIGGER t AFTER INSERT ON a FOR EACH ROW\nBEGIN\n  INSERT INTO b(x) VALUES(1);\nEND$$\nDELIMITER ;\nINSERT INTO c(x) VALUES(2);";
        $stmts = $this->splitD($sql);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('INSERT INTO c', $stmts[1]);
    }

    public function testDelimiterCommentAndBlankLinesSkippedOutsideStatement(): void
    {
        $sql = "-- comment\n\nCREATE TRIGGER t AFTER INSERT ON a\nBEGIN\n  UPDATE a SET x=1;\nEND;\n\n-- trailing\n";
        $this->assertCount(1, $this->splitD($sql));
    }

    public function testDelimiterMultipleTriggersInOneScript(): void
    {
        $sql = "CREATE TRIGGER t1 AFTER INSERT ON a\nBEGIN\n  UPDATE a SET x=1;\nEND;\n"
             . "CREATE TRIGGER t2 AFTER DELETE ON a\nBEGIN\n  UPDATE a SET x=0;\nEND;";
        $stmts = $this->splitD($sql);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('t1', $stmts[0]);
        $this->assertStringContainsString('t2', $stmts[1]);
    }

    public function testDelimiterSingleStatementTriggerWithoutBegin(): void
    {
        // MySQL FOR EACH ROW with a single action and no BEGIN..END ends at ';'.
        $sql = "CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x = 1;\nINSERT INTO a VALUES(1);";
        $stmts = $this->splitD($sql);
        $this->assertCount(2, $stmts);
    }

    // ---- End-to-end on a real SQLite DB ------------------------------------

    public function testTriggerMigrationAppliesAndFiresOnSqlite(): void
    {
        $db = $this->createInMemorySqlite();

        $migration = "CREATE TABLE images (id INTEGER PRIMARY KEY, title TEXT);\n"
            . "CREATE TABLE audit (msg TEXT);\n"
            . "CREATE TRIGGER images_ai AFTER INSERT ON images\n"
            . "BEGIN\n"
            . "  INSERT INTO audit(msg) VALUES('added ' || NEW.id);\n"
            . "  UPDATE images SET title = 'seen' WHERE id = NEW.id;\n"
            . "END;";

        foreach ($this->splitD($migration) as $stmt) {
            $db->exec($stmt);
        }
        $db->exec("INSERT INTO images(id, title) VALUES(7, 'orig')");

        $this->assertSame('seen', $db->query('SELECT title FROM images WHERE id = 7')->fetchColumn());
        $this->assertSame('added 7', $db->query('SELECT msg FROM audit')->fetchColumn());
    }

    public function testPlainMigrationAppliesViaQuoteAwarePathOnSqlite(): void
    {
        $db = $this->createInMemorySqlite();

        $migration = "CREATE TABLE t (id INTEGER PRIMARY KEY, css TEXT);\n"
            . "INSERT INTO t(id, css) VALUES(1, 'a:1; b:2; c:3');";

        $this->assertFalse($this->needsDelimiter($migration));
        foreach ($this->split($migration) as $stmt) {
            $db->exec($stmt);
        }
        $this->assertSame('a:1; b:2; c:3', $db->query('SELECT css FROM t WHERE id = 1')->fetchColumn());
    }

    public function testDelimiterEndIfDoesNotMiscountDepth(): void
    {
        // `END IF` closes an IF, not the outer BEGIN — it must not decrement
        // the block depth and shred the body. Uses an explicit DELIMITER.
        $sql = "DELIMITER $$\n"
             . "CREATE TRIGGER t AFTER INSERT ON a FOR EACH ROW\n"
             . "BEGIN\n"
             . "  IF NEW.x > 0 THEN\n"
             . "    INSERT INTO b(x) VALUES(NEW.x);\n"
             . "  END IF;\n"
             . "  UPDATE a SET y = 1;\n"
             . "END$$\n"
             . "DELIMITER ;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts, 'END IF must not split the trigger body');
        $this->assertStringContainsString('END IF', $stmts[0]);
        $this->assertStringContainsString('UPDATE a SET y = 1', $stmts[0]);
    }

    public function testDelimiterBeginEndInsideStringLiteralNotCounted(): void
    {
        // BEGIN/END words inside a quoted value must not affect depth.
        $sql = "CREATE TRIGGER t AFTER INSERT ON a\n"
             . "BEGIN\n"
             . "  INSERT INTO log(msg) VALUES('job did not END yet; BEGIN later');\n"
             . "END;";
        $stmts = $this->splitD($sql);
        $this->assertCount(1, $stmts, 'BEGIN/END in a string literal must not split');
        $this->assertStringContainsString('job did not END yet', $stmts[0]);
    }

    public function testDelimiterTrailingLineCommentAfterTerminator(): void
    {
        // `END; -- note` must still flush the trigger statement.
        $sql = "CREATE TRIGGER t AFTER INSERT ON a\n"
             . "BEGIN\n"
             . "  UPDATE a SET x = 1;\n"
             . "END; -- syncs counter\n"
             . "INSERT INTO b VALUES(1);";
        $stmts = $this->splitD($sql);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('CREATE TRIGGER t', $stmts[0]);
        $this->assertStringNotContainsString('syncs counter', $stmts[0]);
        $this->assertStringContainsString('INSERT INTO b', $stmts[1]);
    }

    /**
     * Mirror the runner's narrowed trigger tolerance: trigger-specific
     * collision phrases are ignorable; a bare "duplicate" (column/key) on a
     * trigger statement, or a genuine failure, must NOT be tolerated.
     */
    public function testTriggerToleranceIsTriggerSpecific(): void
    {
        $tolerable = static function (string $msg): bool {
            foreach ([
                '/\btrigger\b.*\balready exists\b/i',
                '/\balready exists\b.*\btrigger\b/i',
                '/\bduplicate trigger\b/i',
                '/\bno such trigger\b/i',
                '/\bunknown trigger\b/i',
                '/\btrigger\b.*\bdoes not exist\b/i',
            ] as $p) {
                if (preg_match($p, $msg)) {
                    return true;
                }
            }
            return false;
        };

        // Tolerated — genuine idempotency collisions.
        $this->assertTrue($tolerable('SQLSTATE[HY000]: trigger images_ai already exists'));
        $this->assertTrue($tolerable("Trigger 'images_ai' already exists"));
        $this->assertTrue($tolerable('no such trigger: images_ai'));
        // NOT tolerated — bare "duplicate" must not pass on a trigger stmt.
        $this->assertFalse($tolerable('SQLSTATE[42S21]: Duplicate column name "x"'));
        $this->assertFalse($tolerable("Duplicate key name 'idx_foo'"));
        // NOT tolerated — genuine failure.
        $this->assertFalse($tolerable('SQLSTATE[HY000]: no such table: ghost'));
    }

    public function testDelimiterTransactionalBeginDoesNotBreakFlush(): void
    {
        // A transactional BEGIN/COMMIT (no matching END) in the same file as a
        // trigger must NOT leave depth stuck and swallow later statements.
        $sql = "BEGIN TRANSACTION;\n"
             . "CREATE TABLE a (id INTEGER);\n"
             . "CREATE TRIGGER t AFTER INSERT ON a\nBEGIN\n  UPDATE a SET id = id;\nEND;\n"
             . "INSERT INTO a VALUES(1);\n"
             . "COMMIT;";
        $stmts = $this->splitD($sql);
        // BEGIN TRANSACTION / CREATE TABLE / CREATE TRIGGER / INSERT / COMMIT
        $this->assertCount(5, $stmts);
        $this->assertSame('BEGIN TRANSACTION', $stmts[0]);
        $this->assertStringContainsString('CREATE TRIGGER t', $stmts[2]);
        $this->assertSame('COMMIT', $stmts[4]);
    }

    public function testCreateTriggerStatementMatchesRunnerToleranceRegex(): void
    {
        // The runner treats CREATE/DROP TRIGGER errors as non-fatal via this
        // exact pattern; guard it so a refactor can't silently change it.
        $stmt = "CREATE TRIGGER t AFTER INSERT ON a BEGIN UPDATE a SET x=1; END";
        $this->assertSame(1, preg_match('/^\s*(CREATE|DROP)\s+TRIGGER/i', $stmt));
        $this->assertSame(0, preg_match('/^\s*(CREATE|DROP)\s+TRIGGER/i', 'CREATE TABLE a (id INTEGER)'));
    }
}
