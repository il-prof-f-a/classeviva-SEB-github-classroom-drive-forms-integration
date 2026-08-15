<?php

declare(strict_types=1);

namespace App\Core\Database;

final class SchemaIndexDefinitions
{
    /** @return list<array{name:string,table:string,columns:list<string>,unique:bool}> */
    public static function all(): array
    {
        return [
            ['name' => 'uq_gruppi_didattici_owner_id', 'table' => 'GRUPPI_DIDATTICI', 'columns' => ['id_utente', 'id_gruppo'], 'unique' => true],
            ['name' => 'uq_gruppi_integrazioni_external', 'table' => 'GRUPPI_INTEGRAZIONI', 'columns' => ['id_utente', 'provider', 'external_context_id', 'external_subject_id'], 'unique' => true],
            ['name' => 'uq_uda_gruppi_assignment', 'table' => 'UDA_GRUPPI', 'columns' => ['id_utente', 'id_uda', 'id_gruppo'], 'unique' => true],
            ['name' => 'uq_studenti_owner_id', 'table' => 'STUDENTI', 'columns' => ['id_utente', 'id_studente'], 'unique' => true],
            ['name' => 'uq_studenti_identita_external', 'table' => 'STUDENTI_IDENTITA_ESTERNE', 'columns' => ['id_utente', 'provider', 'external_user_id'], 'unique' => true],
            ['name' => 'uq_gruppi_studenti_membership', 'table' => 'GRUPPI_STUDENTI', 'columns' => ['id_utente', 'id_gruppo', 'id_studente'], 'unique' => true],
            ['name' => 'uq_studenti_risorse_external', 'table' => 'STUDENTI_RISORSE_ESTERNE', 'columns' => ['id_utente', 'provider', 'external_context_id', 'external_resource_id'], 'unique' => true],
            ['name' => 'uq_github_assignment_student_link', 'table' => 'GITHUB_ASSIGNMENT_STUDENT_LINKS', 'columns' => ['id_utente', 'id_assignment', 'id_studente'], 'unique' => true],
        ];
    }
}
