<?php

namespace App\Core\Database;

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\SchemaDefinitions;

/**
 * UserScopedDatabaseAdapter
 *
 * Wrapper per DatabaseAdapterInterface che applica automaticamente
 * il filtro per l'utente corrente sui fogli/tabelle "user-scoped".
 *
 * L'obiettivo è rendere trasparente al resto dell'applicazione la gestione
 * multi-utente: il chiamante continua ad usare il database come prima,
 * mentre questo adapter inietta l'id dell'utente nelle query e negli insert
 * dove previsto.
 */
class UserScopedDatabaseAdapter implements DatabaseAdapterInterface
{
    private DatabaseAdapterInterface $inner;
    private string $userId;

    /**
     * Mappa foglio -> colonna che identifica l'utente proprietario.
     *
     * NOTA: questa mappa può essere estesa in futuro man mano che
     * vengono aggiunte nuove colonne di ownership nelle tabelle.
     */
    private const OWNER_COLUMNS = [
        // Proprietario dell'UDA
        'UDA_ANAGRAFICA'      => 'id_utente_owner',

        // Integrazioni per utente (token OAuth, config ClasseViva, ecc.)
        'INTEGRAZIONI_UTENTE' => 'id_utente',

        // Condivisioni UDA con altri utenti
        'UDA_CONDIVISIONI'    => 'id_utente',
    ];

    public function __construct(DatabaseAdapterInterface $inner, string $userId)
    {
        $this->inner  = $inner;
        $this->userId = $userId;
    }

    /**
     * Verifica se un foglio è soggetto a filtro per utente.
     */
    private function isUserScoped(string $sheetName): bool
    {
        return $this->getOwnerColumn($sheetName) !== null;
    }

    /**
     * Restituisce il nome della colonna di ownership per il foglio (se esiste).
     *
     * Regole:
     * - se il foglio è UTENTI -> nessun owner (null)
     * - se lo schema definisce una colonna id_utente -> quella è la colonna utente standard
     * - altrimenti usa l'eventuale mappatura custom in OWNER_COLUMNS
     */
    private function getOwnerColumn(string $sheetName): ?string
    {
        // Non applichiamo lo scoping alla tabella UTENTI
        if ($sheetName === 'UTENTI') {
            return null;
        }

        $columns = SchemaDefinitions::getSheetColumns($sheetName) ?? [];

        if (in_array('id_utente', $columns, true)) {
            return 'id_utente';
        }

        return self::OWNER_COLUMNS[$sheetName] ?? null;
    }

    /**
     * Applica il filtro utente ai criteri di ricerca, se necessario.
     */
    private function applyUserFilter(string $sheetName, array $where = []): array
    {
        $ownerColumn = $this->getOwnerColumn($sheetName);
        if ($ownerColumn === null) {
            return $where;
        }

        // Se il chiamante non specifica esplicitamente la colonna utente,
        // aggiungiamo il filtro sull'utente corrente.
        if (!array_key_exists($ownerColumn, $where)) {
            $where[$ownerColumn] = $this->userId;
        }

        return $where;
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(string $sheetName): array
    {
        if ($sheetName === 'CLASSI_ASSEGNATE') {
            return array_values(array_filter(
                \App\Core\LegacyUdaDataGateway::findAllClassiAssegnate($this->inner),
                fn(array $row): bool => (string)($row['id_utente'] ?? '') === $this->userId
            ));
        }
        if ($sheetName === 'CLASSI') {
            return array_values(array_filter(
                \App\Utils\LegacyTeachingGroupView::classes($this->inner, $this->userId),
                fn(array $row): bool => (string)($row['id_utente'] ?? '') === $this->userId
            ));
        }
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::legacyRows($this->inner, 'google_classroom', $this->userId);
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::legacyRows($this->inner, 'github_classroom', $this->userId);
        }
        if ($this->isUserScoped($sheetName)) {
            $ownerColumn = $this->getOwnerColumn($sheetName);
            return $this->inner->findWhere($sheetName, [$ownerColumn => $this->userId]);
        }

        return $this->inner->findAll($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(string $sheetName, array $where): array
    {
        if ($sheetName === 'CLASSI_ASSEGNATE') {
            $where['id_utente'] = $this->userId;
            return \App\Core\LegacyUdaDataGateway::findClassiAssegnateWhere($this->inner, $where);
        }
        if ($sheetName === 'CLASSI') {
            $where['id_utente'] = $this->userId;
            return \App\Utils\LegacyTeachingGroupView::filter(
                \App\Utils\LegacyTeachingGroupView::classes($this->inner, $this->userId),
                $where
            );
        }
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::filterLegacyRows(
                \App\Core\ProviderNeutralMappingService::legacyRows($this->inner, 'google_classroom', $this->userId), $where
            );
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::filterLegacyRows(
                \App\Core\ProviderNeutralMappingService::legacyRows($this->inner, 'github_classroom', $this->userId), $where
            );
        }
        $where = $this->applyUserFilter($sheetName, $where);
        return $this->inner->findWhere($sheetName, $where);
    }

    /**
     * {@inheritDoc}
     */
    public function findOne(string $sheetName, string $keyField, $keyValue): ?array
    {
        $row = $this->inner->findOne($sheetName, $keyField, $keyValue);

        $ownerColumn = $this->getOwnerColumn($sheetName);
        if ($ownerColumn !== null && $row !== null) {
            // Se la riga non appartiene all'utente corrente, non la esponiamo
            if (isset($row[$ownerColumn]) && $row[$ownerColumn] !== $this->userId) {
                return null;
            }
        }

        return $row;
    }

    /**
     * {@inheritDoc}
     */
    public function insertRow(string $sheetName, array $data): bool
    {
        // Le tabelle legacy rimosse dallo schema non hanno una colonna di
        // ownership riconosciuta, ma le rispettive facade/gateway richiedono
        // comunque l'owner corretto per scrivere sulla tabella canonica.
        // Le impostiamo qui, come già fatto per updateRow.
        if (in_array($sheetName, ['MAPPATURA_STUDENTI', 'CLASSROOM_MAPPINGS', 'GITHUB_CLASSROOMS', 'GITHUB_ASSIGNMENT_STUDENT_MAP'], true)
            || \App\Core\StudentReferenceGateway::handles($sheetName)) {
            $data['id_utente'] = $this->userId;
            return $this->inner->insertRow($sheetName, $data);
        }

        $ownerColumn = $this->getOwnerColumn($sheetName);

        if ($ownerColumn !== null) {
            // Per alcune tabelle (es. UDA_ANAGRAFICA, INTEGRAZIONI_UTENTE) vogliamo
            // che il proprietario sia sempre l'utente corrente, anche in fase di import.
            if ($sheetName === 'UDA_ANAGRAFICA' || $sheetName === 'INTEGRAZIONI_UTENTE') {
                $data[$ownerColumn] = $this->userId;
            } else {
                // Per le altre, se il chiamante non ha specificato esplicitamente
                // il proprietario, lo impostiamo all'utente corrente.
                if (!isset($data[$ownerColumn])) {
                    $data[$ownerColumn] = $this->userId;
                }
            }
        }

        return $this->inner->insertRow($sheetName, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool
    {
        if (in_array($sheetName, ['MAPPATURA_STUDENTI', 'CLASSROOM_MAPPINGS', 'GITHUB_CLASSROOMS', 'GITHUB_ASSIGNMENT_STUDENT_MAP'], true)
            || \App\Core\StudentReferenceGateway::handles($sheetName)) {
            $data['id_utente'] = $this->userId;
            return $this->inner->updateRow($sheetName, $keyField, $keyValue, $data);
        }
        $ownerColumn = $this->getOwnerColumn($sheetName);

        // Per sicurezza, se la tabella è user-scoped verifichiamo che la riga
        // appartenga all'utente corrente prima di aggiornare.
        if ($ownerColumn !== null) {
            $existing = $this->inner->findOne($sheetName, $keyField, $keyValue);
            if ($existing === null) {
                return false;
            }
            if (isset($existing[$ownerColumn]) && $existing[$ownerColumn] !== $this->userId) {
                // Riga di un altro utente: non aggiornare
                return false;
            }
        }

        return $this->inner->updateRow($sheetName, $keyField, $keyValue, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool
    {
        if ($sheetName === 'CLASSROOM_MAPPINGS' || $sheetName === 'GITHUB_CLASSROOMS') {
            $rows = $this->findWhere($sheetName, ['id_mapping' => (string)$keyValue]);
            if ($rows === []) return false;
            $provider = $sheetName === 'CLASSROOM_MAPPINGS' ? 'google_classroom' : 'github_classroom';
            return \App\Core\ProviderNeutralMappingService::deleteLegacy($this->inner, $provider, (string)$keyValue);
        }
        $ownerColumn = $this->getOwnerColumn($sheetName);

        if ($ownerColumn !== null) {
            $existing = $this->inner->findOne($sheetName, $keyField, $keyValue);
            if ($existing === null) {
                return false;
            }
            if (isset($existing[$ownerColumn]) && $existing[$ownerColumn] !== $this->userId) {
                // Riga di un altro utente: non eliminare
                return false;
            }
        }

        return $this->inner->deleteRow($sheetName, $keyValue, $keyField);
    }

    /**
     * {@inheritDoc}
     */
    public function ensureSheetExists(string $sheetName): bool
    {
        return $this->inner->ensureSheetExists($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->inner->getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function createBackup(): string
    {
        return $this->inner->createBackup();
    }

    /**
     * {@inheritDoc}
     */
    public function initialize(): array
    {
        return $this->inner->initialize();
    }

    /**
     * {@inheritDoc}
     */
    public function validate(): array
    {
        return $this->inner->validate();
    }

    /**
     * {@inheritDoc}
     */
    public function repair(): array
    {
        return $this->inner->repair();
    }

    /**
     * {@inheritDoc}
     */
    public function getColumns(string $sheetName): array
    {
        return $this->inner->getColumns($sheetName);
    }

    /**
     * Metodi helper specifici per alcune funzionalità del portale
     * (mappature Classroom). Non fanno parte dell'interfaccia generica,
     * ma vengono usati da pagine come map_classes.php e
     * manage_subject_mappings.php.
     */

    /**
     * Inserisce una mappatura ClasseViva ↔ Google Classroom.
     */
    public function insertClassroomMapping(array $data): bool
    {
        return $this->insertRow('CLASSROOM_MAPPINGS', $data);
    }

    /**
     * Aggiorna una mappatura esistente.
     */
    public function updateClassroomMapping(string $id, array $data): bool
    {
        return $this->updateRow('CLASSROOM_MAPPINGS', 'id_mapping', $id, $data);
    }

    /**
     * Elimina una mappatura per id.
     */
    public function deleteClassroomMapping(string $id): bool
    {
        return $this->deleteRow('CLASSROOM_MAPPINGS', $id, 'id_mapping');
    }

    /**
     * Cancella tutte le mappature dell'utente corrente.
     * (usato in github_classroom_mapping.php e simili).
     */
    public function clearAllClassroomMappings(): bool
    {
        // findAll è già scoped per utente, quindi eliminiamo solo le righe "nostre"
        $rows = $this->findAll('CLASSROOM_MAPPINGS');
        foreach ($rows as $row) {
            if (!empty($row['id_mapping'])) {
                $this->deleteRow('CLASSROOM_MAPPINGS', $row['id_mapping'], 'id_mapping');
            }
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function sheetExists(string $sheetName): bool
    {
        return $this->inner->sheetExists($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function count(string $sheetName): int
    {
        if ($this->isUserScoped($sheetName)) {
            $rows = $this->findAll($sheetName);
            return count($rows);
        }

        return $this->inner->count($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function truncate(string $sheetName): bool
    {
        // Per i fogli user-scoped, "svuotare" significa in pratica
        // cancellare solo le righe dell'utente corrente.
        if ($this->isUserScoped($sheetName)) {
            $rows = $this->findAll($sheetName);
            if (empty($rows)) {
                return true;
            }

            foreach ($rows as $row) {
                // Prova a determinare un campo ID
                $idField = null;

                if (isset($row['id'])) {
                    $idField = 'id';
                } elseif (isset($row['ID'])) {
                    $idField = 'ID';
                } else {
                    foreach (array_keys($row) as $field) {
                        if (strpos($field, 'id_') === 0 || strpos($field, 'ID_') === 0) {
                            $idField = $field;
                            break;
                        }
                    }
                }

                if ($idField !== null && isset($row[$idField])) {
                    $this->inner->deleteRow($sheetName, $row[$idField], $idField);
                }
            }

            return true;
        }

        return $this->inner->truncate($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function getAllSheetNames(): array
    {
        return $this->inner->getAllSheetNames();
    }

    /**
     * {@inheritDoc}
     */
    public function clearSheet(string $sheetName): bool
    {
        // clearSheet ha già una semantica ben definita sugli adapter concreti;
        // qui lo lasciamo passare direttamente all'inner.
        return $this->inner->clearSheet($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function createSheet(string $sheetName, array $columns = []): bool
    {
        return $this->inner->createSheet($sheetName, $columns);
    }

    // ========================================================================
    // METODI DI CONVENIENZA PER UDA (delegati con scoping utente)
    // ========================================================================

    public function findAllUDAs(): array
    {
        return $this->findAll('UDA_ANAGRAFICA');
    }

    public function findUDAById(string $id): ?array
    {
        return $this->findOne('UDA_ANAGRAFICA', 'id_uda', $id);
    }

    public function findMaterialiByUDA(string $udaId): array
    {
        return $this->findWhere('MATERIALI', ['id_uda' => $udaId]);
    }

    public function findObiettiviByUDA(string $udaId): array
    {
        return $this->findWhere('OBIETTIVI', ['id_uda' => $udaId]);
    }

    public function findTestByUDA(string $udaId): array
    {
        return $this->findWhere('TEST', ['id_uda' => $udaId]);
    }

    public function findClassiAssegnate(string $udaId): array
    {
        return \App\Core\LegacyUdaDataGateway::findClassiAssegnate($this, $udaId);
    }

    public function findVotiByUDA(string $udaId): array
    {
        return $this->findWhere('VOTI', ['id_uda' => $udaId]);
    }

    public function insertUDA(array $data): bool
    {
        return $this->insertRow('UDA_ANAGRAFICA', $data);
    }

    public function insertMateriale(array $data): bool
    {
        return $this->insertRow('MATERIALI', $data);
    }

    public function insertObiettivo(array $data): bool
    {
        return $this->insertRow('OBIETTIVI', $data);
    }

    public function insertTest(array $data): bool
    {
        return $this->insertRow('TEST', $data);
    }

    public function insertVoto(array $data): bool
    {
        return $this->insertRow('VOTI', $data);
    }

    public function deleteVoto(string $id): bool
    {
        return $this->deleteRow('VOTI', $id, 'id_voto');
    }

    public function insertClasseAssegnata(array $data): bool
    {
        $data['id_utente'] = $this->userId;
        return \App\Core\LegacyUdaDataGateway::insertClasseAssegnata($this, $data);
    }

    public function deleteClasseAssegnata(string $id): bool
    {
        return \App\Core\LegacyUdaDataGateway::deleteClasseAssegnata($this, $id);
    }

    public function updateUDA(string $id, array $data): bool
    {
        return $this->updateRow('UDA_ANAGRAFICA', 'id_uda', $id, $data);
    }

    public function deleteUDA(string $id): bool
    {
        return $this->deleteRow('UDA_ANAGRAFICA', $id, 'id_uda');
    }

    public function deleteMateriale(string $id): bool
    {
        return $this->deleteRow('MATERIALI', $id, 'id_materiale');
    }

    public function deleteObiettivo(string $id): bool
    {
        return $this->deleteRow('OBIETTIVI', $id, 'id_obiettivo');
    }
}
