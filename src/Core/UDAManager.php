<?php

namespace App\Core;

use App\Models\UDA;
use App\Core\Database\DatabaseFactory;
use App\Core\Database\DatabaseAdapterInterface;

/**
 * UDAManager - Gestisce la logica di business per le Unità di Apprendimento.
 *
 * Orchesta le operazioni, utilizzando il DatabaseAdapter per l'accesso ai dati
 * e trasformando i dati grezzi in oggetti di modello (Models).
 */
class UDAManager
{
    private $dbManager; // DatabaseAdapterInterface
    private FileManager $fileManager;
    private TemplateManager $templateManager;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        // Crea adapter usando il Factory
        $this->dbManager = DatabaseFactory::createWithInitialization($config, true);

        $this->fileManager = new FileManager($config);
        $this->templateManager = new TemplateManager($config);
    }

    /**
     * Recupera tutte le UDA dal database
     *
     * @return UDA[]
     */
    public function getAllUDAs(): array
    {
        $udasData = $this->dbManager->findAllUDAs();
        $udas = [];

        foreach ($udasData as $udaData) {
            if (!empty($udaData['id_uda'])) {
                $udas[] = UDA::fromArray($udaData);
            }
        }

        return $udas;
    }

    /**
     * Recupera una singola UDA (solo anagrafica)
     *
     * @param string $id ID dell'UDA
     * @return UDA|null Oggetto UDA o null se non trovata
     */
    public function getUDAById(string $id): ?UDA
    {
        $udaData = $this->dbManager->findUDAById($id);

        if (!$udaData) {
            return null;
        }

        return UDA::fromArray($udaData);
    }

    /**
     * Recupera una singola UDA con tutti i suoi dati correlati
     *
     * @param string $id ID dell'UDA
     * @return array|null UDA completa con materiali, obiettivi, test, etc.
     */
    public function getUDAComplete(string $id): ?array
    {
        $udaData = $this->dbManager->findUDAById($id);

        if (!$udaData) {
            return null;
        }

        return [
            'uda' => UDA::fromArray($udaData),
            'materiali' => $this->dbManager->findMaterialiByUDA($id),
            'obiettivi' => $this->dbManager->findObiettiviByUDA($id),
            'test' => $this->dbManager->findTestByUDA($id),
            'classi_assegnate' => $this->dbManager->findClassiAssegnate($id),
            'voti' => $this->dbManager->findVotiByUDA($id),
            'files' => $this->fileManager->getUDAFiles($id)
        ];
    }

    /**
     * Restituisce i file/materiali associati a una UDA (opzionale categoria).
     */
    public function getUDAFiles(string $udaId, ?string $category = null): array
    {
        return $this->fileManager->getUDAFiles($udaId, $category);
    }

    /**
     * Crea una nuova UDA completa
     *
     * @param array $udaData Dati dell'UDA
     * @param array $materiali Array di materiali
     * @param array $obiettivi Array di obiettivi
     * @return string ID dell'UDA creata
     */
    public function createUDA(array $udaData, array $materiali = [], array $obiettivi = []): string
    {
        // Genera ID univoco
        $udaId = 'UDA_' . date('Ymd') . '_' . uniqid();
        $udaData['id_uda'] = $udaId;
        $udaData['data_creazione'] = date('Y-m-d H:i:s');
        $udaData['stato'] = $udaData['stato'] ?? 'bozza';

        // Crea backup prima di modificare
        $this->dbManager->createBackup();

        // Inserisci UDA nel database
        $this->dbManager->insertUDA($udaData);

        // Crea struttura cartelle
        $year = $this->config['academic_year']['current'];
        $this->fileManager->createUDAFolderStructure($udaId, $year);

        // Inserisci materiali
        foreach ($materiali as $materiale) {
            $materiale['id_materiale'] = 'MAT_' . uniqid();
            $materiale['id_uda'] = $udaId;
            $materiale['data_creazione'] = date('Y-m-d H:i:s');
            $this->dbManager->insertMateriale($materiale);
        }

        // Inserisci obiettivi
        foreach ($obiettivi as $obiettivo) {
            $obiettivo['id_obiettivo'] = 'OBJ_' . uniqid();
            $obiettivo['id_uda'] = $udaId;
            $this->dbManager->insertObiettivo($obiettivo);
        }

        return $udaId;
    }

    /**
     * Aggiorna un'UDA esistente
     */
    public function updateUDA(string $id, array $udaData): bool
    {
        $udaData['ultima_modifica'] = date('Y-m-d H:i:s');
        return $this->dbManager->updateUDA($id, $udaData);
    }

    /**
     * Elimina un'UDA e tutti i dati correlati
     */
    public function deleteUDA(string $id): bool
    {
        // Crea backup prima di eliminare
        $backupFile = $this->dbManager->createBackup();

        try {
            // Elimina materiali associati
            $materiali = $this->dbManager->findMaterialiByUDA($id);
            foreach ($materiali as $mat) {
                if (!empty($mat['id_materiale'])) {
                    $this->dbManager->deleteMateriale($mat['id_materiale']);
                }
            }

            // Elimina obiettivi associati
            $obiettivi = $this->dbManager->findObiettiviByUDA($id);
            foreach ($obiettivi as $obj) {
                if (!empty($obj['id_obiettivo'])) {
                    $this->dbManager->deleteObiettivo($obj['id_obiettivo']);
                }
            }

            // Elimina test associati
            $test = $this->dbManager->findTestByUDA($id);
            foreach ($test as $t) {
                if (!empty($t['id_test'])) {
                    $this->dbManager->deleteRow('TEST', $t['id_test'], 'id_test');
                }
            }

            // Elimina voti associati
            $voti = $this->dbManager->findVotiByUDA($id);
            foreach ($voti as $v) {
                if (!empty($v['id_voto'])) {
                    $this->dbManager->deleteRow('VOTI', $v['id_voto'], 'id_voto');
                }
            }

            // Elimina classi assegnate
            $classi = $this->dbManager->findClassiAssegnate($id);
            foreach ($classi as $c) {
                if (!empty($c['id_assegnazione'])) {
                    $this->dbManager->deleteClasseAssegnata((string)$c['id_assegnazione']);
                }
            }

            // Elimina l'UDA stessa
            return $this->dbManager->deleteUDA($id);

        } catch (\Exception $e) {
            // In caso di errore, il backup è già stato creato
            throw new \Exception("Errore durante l'eliminazione dell'UDA: " . $e->getMessage() .
                                " - Backup salvato in: " . $backupFile);
        }
    }

    /**
     * Aggiunge un materiale all'UDA
     */
    public function addMateriale(string $udaId, array $materialeData, $file = null): string
    {
        $materialId = 'MAT_' . uniqid();
        $materialeData['id_materiale'] = $materialId;
        $materialeData['id_uda'] = $udaId;
        $materialeData['data_creazione'] = date('Y-m-d H:i:s');

        // Gestisci upload file se presente
        if ($file && isset($file['tmp_name'])) {
            $uploadInfo = $this->fileManager->uploadFile($file, $udaId, 'materiali');
            $materialeData['local_path'] = $uploadInfo['relative_path'];
        }

        $this->dbManager->insertMateriale($materialeData);

        return $materialId;
    }

    /**
     * Aggiunge un test all'UDA
     */
    public function addTest(string $udaId, array $testData): string
    {
        $testId = 'TEST_' . uniqid();
        $testData['id_test'] = $testId;
        $testData['id_uda'] = $udaId;

        $this->dbManager->insertTest($testData);

        return $testId;
    }

    /**
     * Aggiunge un voto
     */
    public function addVoto(string $udaId, array $votoData): string
    {
        $votoId = 'VOTO_' . uniqid();
        $votoData['id_voto'] = $votoId;
        $votoData['id_uda'] = $udaId;
        $votoData['data_valutazione'] = $votoData['data_valutazione'] ?? date('Y-m-d');
        if (empty($votoData['link_origine'])) {
            $votoData['link_origine'] = function_exists('app_url')
                ? app_url('public/uda_grades.php?id=' . urlencode((string)$udaId))
                : '';
        }

        $this->dbManager->insertVoto($votoData);

        return $votoId;
    }

    /**
     * Assegna UDA a una classe
     */
    public function assignToClass(string $udaId, string $classeId, string $nomeClasse): bool
    {
        $data = [
            'id_assegnazione' => 'ASSEGN_' . uniqid(),
            'id_uda' => $udaId,
            'id_classe' => $classeId,
            'nome_classe' => $nomeClasse,
            'data_assegnazione' => date('Y-m-d H:i:s'),
            'pubblicato_classroom' => 0
        ];

        return $this->dbManager->insertClasseAssegnata($data);
    }

    /**
     * Crea rubrica di valutazione per l'UDA
     */
    public function createRubrica(string $udaId, array $indicatori = []): string
    {
        $udaData = $this->dbManager->findUDAById($udaId);

        if (!$udaData) {
            throw new \Exception("UDA non trovata");
        }

        return $this->templateManager->createRubricaFromTemplate($udaData, $indicatori);
    }

    /**
     * Crea file voti per una classe
     */
    public function createFileVoti(string $udaId, array $studenti, string $tipoValutazione = 'orale'): string
    {
        return $this->templateManager->createVotiFromTemplate($udaId, $studenti, $tipoValutazione);
    }

    /**
     * Ottiene statistiche UDA
     */
    public function getStatistics(string $udaId): array
    {
        $voti = $this->dbManager->findVotiByUDA($udaId);

        $stats = [
            'num_studenti' => count($voti),
            'media_voti' => 0,
            'voto_min' => null,
            'voto_max' => null,
            'sufficienze' => 0,
            'insufficienze' => 0
        ];

        if (empty($voti)) {
            return $stats;
        }

        $somma = 0;
        $sufficienzaMin = $this->config['grades']['passing_grade'] ?? 6.0;

        foreach ($voti as $voto) {
            $v = (float)$voto['voto'];
            $somma += $v;

            if ($stats['voto_min'] === null || $v < $stats['voto_min']) {
                $stats['voto_min'] = $v;
            }

            if ($stats['voto_max'] === null || $v > $stats['voto_max']) {
                $stats['voto_max'] = $v;
            }

            if ($v >= $sufficienzaMin) {
                $stats['sufficienze']++;
            } else {
                $stats['insufficienze']++;
            }
        }

        $stats['media_voti'] = round($somma / count($voti), 2);

        return $stats;
    }

    /**
     * Duplica un'UDA esistente
     */
    public function duplicateUDA(string $sourceId, string $newTitle): string
    {
        $sourceData = $this->dbManager->findUDAById($sourceId);

        if (!$sourceData) {
            throw new \Exception("UDA sorgente non trovata");
        }

        // Crea nuova UDA con dati modificati
        $newData = $sourceData;
        $newData['titolo'] = $newTitle;
        $newData['stato'] = 'bozza';

        $materiali = $this->dbManager->findMaterialiByUDA($sourceId);
        $obiettivi = $this->dbManager->findObiettiviByUDA($sourceId);

        return $this->createUDA($newData, $materiali, $obiettivi);
    }
}
