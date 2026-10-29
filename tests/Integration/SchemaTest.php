<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/DatabaseTestCase.php';

final class SchemaTest extends DatabaseTestCase
{
    public function testRepeatedImportPreservesExistingData(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();
        $this->pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn());
        $stmt = $this->pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId]);
        self::assertSame('Regression fixture', $stmt->fetchColumn());
    }
}
