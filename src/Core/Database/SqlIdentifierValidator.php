<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\SchemaDefinitions;
use InvalidArgumentException;

/**
 * Allow-list for identifiers interpolated into SQL statements.
 * Values are always bound parameters; this class only handles identifiers.
 */
final class SqlIdentifierValidator
{
    private const LEGACY_COMPAT_COLUMNS = [
        'id_gruppo', 'id_studente', 'id_studente_gc', 'id_classe_cv', 'id_materia_cv',
        'id_studente_cv', 'external_user_id', 'repository_url', 'status', 'grade',
        'id_assignment', 'github_username', 'student_repository_url', 'nome_studente',
        'voto_numerico', 'pubblicato_cv', 'dati_json', 'link_origine', 'provider',
        'external_context_id', 'external_resource_id', 'tipo_risorsa', 'external_url',
    ];
    public static function assertSheet(string $sheetName): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $sheetName)) {
            throw new InvalidArgumentException('Identificatore database non consentito');
        }

        // Legacy/provider gateways may expose a table not present in the canonical
        // schema, but they still have to use a plain identifier.
    }

    public static function assertColumn(string $sheetName, string $column): void
    {
        self::assertSheet($sheetName);
        $columns = SchemaDefinitions::getSheetColumns($sheetName) ?? [];
        if ($columns !== [] && !in_array($column, $columns, true)
            && !in_array($column, self::LEGACY_COMPAT_COLUMNS, true)) {
            throw new InvalidArgumentException('Identificatore database non consentito');
        }
        if ($columns === [] && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Identificatore database non consentito: {$sheetName}.{$column}");
        }
    }

    public static function assertColumns(string $sheetName, array $columns): void
    {
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Identificatore database non consentito');
            }
            self::assertColumn($sheetName, $column);
        }
    }
}
