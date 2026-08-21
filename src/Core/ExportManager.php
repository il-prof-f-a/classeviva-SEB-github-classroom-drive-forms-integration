<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\UDAManager;
use App\Core\ObiettiviManager;
use App\Core\RubricManager;
use App\Core\LaboratorioManager;
use App\Core\QuestionGenerator;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use Exception;

/**
 * ExportManager - Esporta UDA completa in documenti Word/PDF
 *
 * Genera documentazione completa includendo:
 * - Informazioni generali UDA
 * - Obiettivi didattici
 * - Materiali
 * - Valutazioni (rubriche, laboratorio)
 * - Domande e test
 * - Statistiche
 */
class ExportManager
{
    private DatabaseAdapterInterface $db;
    private UDAManager $udaManager;
    private ObiettiviManager $obiettiviManager;
    private RubricManager $rubricManager;
    private LaboratorioManager $labManager;
    private QuestionGenerator $questionGen;
    private array $config;

    public function __construct(DatabaseAdapterInterface $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->udaManager = new UDAManager($config);
        $this->obiettiviManager = new ObiettiviManager($db, $config);
        $this->rubricManager = new RubricManager($db, $config);
        $this->labManager = new LaboratorioManager($db, $config);
        $this->questionGen = new QuestionGenerator($db, $config);
    }

    /**
     * Esporta UDA completa in formato Word
     *
     * @param string $idUda ID della UDA
     * @param array $sezioni Sezioni da includere (default: tutte)
     * @return string Path del file generato
     */
    public function esportaUDAWord(string $idUda, array $sezioni = []): string
    {
        // Sezioni default (tutte)
        if (empty($sezioni)) {
            $sezioni = ['info', 'obiettivi', 'materiali', 'rubriche', 'laboratorio', 'domande', 'test', 'statistiche'];
        }

        // Recupera dati UDA
        $udaComplete = $this->udaManager->getUDAComplete($idUda);
        if (!$udaComplete) {
            throw new Exception("UDA non trovata: $idUda");
        }

        $uda = $udaComplete['uda'];

        // Crea documento PHPWord
        $phpWord = new PhpWord();

        // Configura stili documento
        $this->configuraStili($phpWord);

        // Sezione principale
        $section = $phpWord->addSection([
            'marginTop' => 1000,
            'marginBottom' => 1000,
            'marginLeft' => 1200,
            'marginRight' => 1200
        ]);

        // HEADER PRINCIPALE
        $this->aggiungiHeader($section, $uda);

        // SEZIONI
        if (in_array('info', $sezioni)) {
            $this->aggiungiSezioneInfo($section, $uda, $udaComplete);
        }

        if (in_array('obiettivi', $sezioni)) {
            $this->aggiungiSezioneObiettivi($section, $idUda);
        }

        if (in_array('materiali', $sezioni)) {
            $this->aggiungiSezioneMateriali($section, $udaComplete);
        }

        if (in_array('rubriche', $sezioni)) {
            $this->aggiungiSezioneRubriche($section, $idUda);
        }

        if (in_array('laboratorio', $sezioni)) {
            $this->aggiungiSezioneLaboratorio($section, $idUda);
        }

        if (in_array('domande', $sezioni)) {
            $this->aggiungiSezioneDomande($section, $idUda);
        }

        if (in_array('test', $sezioni)) {
            $this->aggiungiSezioneTest($section, $idUda);
        }

        if (in_array('statistiche', $sezioni)) {
            $this->aggiungiSezioneStatistiche($section, $idUda);
        }

        // Salva documento
        $fileName = $this->normalizzaNomeFile($uda->titolo ?? 'UDA') . '_' . date('Ymd') . '.docx';
        $outputPath = ROOT_PATH . '/storage/exports/' . $fileName;

        // Crea directory se non esiste
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Configura stili documento
     */
    private function configuraStili(PhpWord $phpWord): void
    {
        // Stile titolo principale
        $phpWord->addTitleStyle(1, [
            'bold' => true,
            'size' => 20,
            'color' => '0d6efd'
        ]);

        // Stile titolo sezione
        $phpWord->addTitleStyle(2, [
            'bold' => true,
            'size' => 16,
            'color' => '2c3e50'
        ]);

        // Stile sottotitolo
        $phpWord->addTitleStyle(3, [
            'bold' => true,
            'size' => 14,
            'color' => '495057'
        ]);

        // Stile normale
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
    }

    /**
     * Aggiunge header documento
     */
    private function aggiungiHeader($section, $uda): void
    {
        $section->addTitle(htmlspecialchars($uda->titolo ?? 'UDA'), 1);

        if ($uda->argomento) {
            $section->addText(
                htmlspecialchars($uda->argomento),
                ['size' => 12, 'italic' => true, 'color' => '6c757d']
            );
        }

        $section->addText(
            'Documento generato il: ' . date('d/m/Y H:i'),
            ['size' => 9, 'color' => '999999']
        );

        $section->addTextBreak(1);
    }

    /**
     * Sezione: Informazioni Generali
     */
    private function aggiungiSezioneInfo($section, $uda, $udaComplete): void
    {
        $section->addTitle('Informazioni Generali', 2);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc']);

        $this->aggiungiRigaTabella($table, 'ID UDA:', $uda->id_uda ?? 'N/D');
        $this->aggiungiRigaTabella($table, 'Stato:', ucfirst($uda->stato ?? 'N/D'));
        $this->aggiungiRigaTabella($table, 'Disciplina:', $uda->disciplina ?? 'N/D');
        $this->aggiungiRigaTabella($table, 'Data Inizio:', $uda->data_inizio ?? 'N/D');
        $this->aggiungiRigaTabella($table, 'Data Fine:', $uda->data_fine ?? 'N/D');

        if ($uda->descrizione) {
            $section->addTextBreak(1);
            $section->addText('Descrizione:', ['bold' => true]);
            $section->addText(htmlspecialchars($uda->descrizione), ['spaceAfter' => 200]);
        }

        $section->addTextBreak(1);
    }

    /**
     * Sezione: Obiettivi Didattici
     */
    private function aggiungiSezioneObiettivi($section, string $idUda): void
    {
        $obiettivi = $this->obiettiviManager->getObiettiviPerUDA($idUda);

        $section->addTitle('Obiettivi Didattici', 2);
        $section->addText('Totale obiettivi: ' . count($obiettivi), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($obiettivi)) {
            $section->addText('Nessun obiettivo definito.', ['italic' => true, 'color' => '999999']);
            return;
        }

        // Raggruppa per tipo
        $perTipo = [];
        foreach ($obiettivi as $obj) {
            $tipo = $obj->tipo_obiettivo ?? 'altro';
            $perTipo[$tipo][] = $obj;
        }

        foreach ($perTipo as $tipo => $objs) {
            $section->addTitle(ucwords(str_replace('_', ' ', $tipo)), 3);

            foreach ($objs as $idx => $obj) {
                $testo = ($idx + 1) . '. ';

                if ($obj->codice) {
                    $testo .= '[' . $obj->codice . '] ';
                }

                $testo .= $obj->descrizione ?? '';

                if ($obj->livello_tassonomia) {
                    $testo .= ' (Bloom: L' . $obj->livello_tassonomia . ' - ' .
                        (\App\Models\Obiettivo::LIVELLI_BLOOM[$obj->livello_tassonomia] ?? '') . ')';
                }

                $section->addListItem($testo, 0, ['size' => 11]);
            }

            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Materiali Didattici
     */
    private function aggiungiSezioneMateriali($section, $udaComplete): void
    {
        $materiali = $udaComplete['materiali'] ?? [];

        $section->addTitle('Materiali Didattici', 2);
        $section->addText('Totale materiali: ' . count($materiali), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($materiali)) {
            $section->addText('Nessun materiale caricato.', ['italic' => true, 'color' => '999999']);
            return;
        }

        foreach ($materiali as $mat) {
            $section->addText(
                htmlspecialchars($mat['nome'] ?? 'Senza titolo'),
                ['bold' => true, 'size' => 12]
            );

            if (!empty($mat['tipo_materiale'])) {
                $section->addText('Tipo: ' . htmlspecialchars($mat['tipo_materiale']));
            }

            if (!empty($mat['descrizione'])) {
                $section->addText('Descrizione: ' . htmlspecialchars($mat['descrizione']));
            }

            if (!empty($mat['url_drive'])) {
                $section->addLink(
                    htmlspecialchars($mat['url_drive']),
                    htmlspecialchars($mat['url_drive']),
                    ['color' => '0d6efd', 'underline' => Font::UNDERLINE_SINGLE]
                );
            }

            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Rubriche Valutazione
     */
    private function aggiungiSezioneRubriche($section, string $idUda): void
    {
        $allRubriche = $this->db->findAll('RUBRICA');
        $rubricheUDA = array_filter($allRubriche, fn($r) => ($r['id_uda'] ?? '') === $idUda);

        $section->addTitle('Rubriche di Valutazione Orale', 2);
        $section->addText('Totale rubriche compilate: ' . count($rubricheUDA), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($rubricheUDA)) {
            $section->addText('Nessuna rubrica compilata.', ['italic' => true, 'color' => '999999']);
            return;
        }

        // Raggruppa per id_rubrica
        $rubriche = [];
        foreach ($rubricheUDA as $row) {
            $id = $row['id_rubrica'] ?? '';
            $rubriche[$id][] = $row;
        }

        foreach ($rubriche as $idRubrica => $rows) {
            $first = $rows[0];
            $section->addText(
                'Studente: ' . htmlspecialchars($first['studente'] ?? 'N/D'),
                ['bold' => true, 'size' => 12]
            );
            $section->addText('Data: ' . ($first['data_valutazione'] ?? 'N/D'));
            $section->addText('Voto: ' . ($first['voto_finale'] ?? 'N/D'), ['bold' => true, 'color' => '0d6efd']);

            if (!empty($first['giudizio_sintetico'])) {
                $section->addText('Giudizio: ' . htmlspecialchars($first['giudizio_sintetico']));
            }

            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Valutazioni Laboratorio
     */
    private function aggiungiSezioneLaboratorio($section, string $idUda): void
    {
        $allValutazioni = $this->db->findAll('VALUTAZIONI_LABORATORIO');
        $valutazioniUDA = array_filter($allValutazioni, fn($v) => ($v['id_uda'] ?? '') === $idUda);

        $section->addTitle('Valutazioni Laboratorio (+/-)', 2);
        $section->addText('Totale valutazioni: ' . count($valutazioniUDA), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($valutazioniUDA)) {
            $section->addText('Nessuna valutazione laboratorio.', ['italic' => true, 'color' => '999999']);
            return;
        }

        // Raggruppa per studente
        $studenti = [];
        foreach ($valutazioniUDA as $val) {
            $idStud = $val['id_studente'] ?? '';
            if (!isset($studenti[$idStud])) {
                $studenti[$idStud] = [];
            }
            $studenti[$idStud][] = $val;
        }

        foreach ($studenti as $idStud => $valutazioni) {
            $first = $valutazioni[0];
            $section->addText(
                'Studente: ' . htmlspecialchars($first['nome_studente'] ?? $idStud),
                ['bold' => true, 'size' => 12]
            );

            // Conta evidenze +/-
            $positivi = 0;
            $negativi = 0;
            foreach ($valutazioni as $v) {
                if (($v['valore'] ?? '') === '+') $positivi++;
                if (($v['valore'] ?? '') === '-') $negativi++;
            }

            $section->addText("Evidenze positive: $positivi | Evidenze negative: $negativi");

            if (!empty($first['voto'])) {
                $section->addText('Voto: ' . $first['voto'], ['bold' => true, 'color' => '0d6efd']);
            }

            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Domande e Test
     */
    private function aggiungiSezioneDomande($section, string $idUda): void
    {
        $allDomande = $this->db->findAll('DOMANDE_INTERROGAZIONE');
        $domandeUDA = array_filter($allDomande, fn($d) => ($d['id_uda'] ?? '') === $idUda);

        $section->addTitle('Domande per Interrogazioni e Test', 2);
        $section->addText('Totale domande: ' . count($domandeUDA), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($domandeUDA)) {
            $section->addText('Nessuna domanda creata.', ['italic' => true, 'color' => '999999']);
            return;
        }

        foreach ($domandeUDA as $idx => $dom) {
            $numero = $idx + 1;
            $testo = $dom['testo_domanda'] ?? $dom['domanda'] ?? '';
            $tipo = $dom['tipo_domanda'] ?? 'aperta';
            $livello = $dom['livello_bloom'] ?? null;

            $section->addText(
                "$numero. " . htmlspecialchars($testo),
                ['bold' => true]
            );

            $info = "Tipo: $tipo";
            if ($livello) {
                $info .= " | Bloom: L$livello";
            }
            if (!empty($dom['punti'])) {
                $info .= " | Punti: " . $dom['punti'];
            }

            $section->addText($info, ['size' => 9, 'color' => '666666']);
            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Test e Verifiche (link esterni)
     */
    private function aggiungiSezioneTest($section, string $idUda): void
    {
        $tests = $this->db->findWhere('TEST', ['id_uda' => $idUda]);

        $section->addTitle('Test e Verifiche', 2);
        $section->addText('Totale test: ' . count($tests), ['bold' => true]);
        $section->addTextBreak(1);

        if (empty($tests)) {
            $section->addText('Nessun test collegato.', ['italic' => true, 'color' => '999999']);
            return;
        }

        foreach ($tests as $test) {
            $section->addText(
                htmlspecialchars((string)($test['nome'] ?? 'Test senza titolo')),
                ['bold' => true, 'size' => 12]
            );

            $piattaforma = (string)($test['piattaforma'] ?? 'altro');
            $section->addText('Piattaforma: ' . htmlspecialchars($piattaforma));

            if (!empty($test['descrizione'])) {
                $section->addText('Descrizione: ' . htmlspecialchars((string)$test['descrizione']));
            }
            if (!empty($test['id_esterno'])) {
                $section->addText('ID esterno: ' . htmlspecialchars((string)$test['id_esterno']));
            }

            $links = [
                'URL Studenti' => $test['url_studenti'] ?? ($test['url'] ?? ''),
                'URL Docente/Gestione' => $test['url_docente'] ?? ($test['url_gestione'] ?? ''),
                'URL Assignment Studenti' => $test['url_assignment_student'] ?? '',
                'URL Assignment Docente' => $test['url_assignment_teacher'] ?? '',
            ];

            foreach ($links as $label => $url) {
                $url = trim((string)$url);
                if ($url === '') {
                    continue;
                }
                $section->addText($label . ':', ['bold' => true, 'size' => 10]);
                $section->addLink(
                    htmlspecialchars($url),
                    htmlspecialchars($url),
                    ['color' => '0d6efd', 'underline' => Font::UNDERLINE_SINGLE, 'size' => 10]
                );
            }

            $section->addTextBreak(1);
        }
    }

    /**
     * Sezione: Statistiche
     */
    private function aggiungiSezioneStatistiche($section, string $idUda): void
    {
        $section->addTitle('Statistiche', 2);

        try {
            $stats = $this->udaManager->getStatistics($idUda);

            if ($stats) {
                $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc']);
                $this->aggiungiRigaTabella($table, 'Studenti Valutati:', $stats['num_studenti'] ?? 0);
                $this->aggiungiRigaTabella($table, 'Media Voti:', number_format($stats['media_voti'] ?? 0, 2));
                $this->aggiungiRigaTabella($table, 'Sufficienze:', $stats['sufficienze'] ?? 0);
                $this->aggiungiRigaTabella($table, 'Insufficienze:', $stats['insufficienze'] ?? 0);
            }
        } catch (Exception $e) {
            $section->addText('Statistiche non disponibili.', ['italic' => true, 'color' => '999999']);
        }
    }

    /**
     * Helper: Aggiunge riga a tabella
     */
    private function aggiungiRigaTabella($table, string $label, $value): void
    {
        $table->addRow();
        $table->addCell(3000)->addText($label, ['bold' => true]);
        $table->addCell(6000)->addText((string)$value);
    }

    /**
     * Normalizza nome file
     */
    private function normalizzaNomeFile(string $nome): string
    {
        $nome = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nome);
        $nome = preg_replace('/_+/', '_', $nome);
        return trim($nome, '_');
    }

    /**
     * Genera anteprima export (solo info base, veloce)
     */
    public function anteprimaExport(string $idUda): array
    {
        $udaComplete = $this->udaManager->getUDAComplete($idUda);
        if (!$udaComplete) {
            throw new Exception("UDA non trovata");
        }

        $obiettivi = $this->obiettiviManager->getObiettiviPerUDA($idUda);
        $statsdomande = $this->questionGen->getStatisticheDomandeUDA($idUda);

        $allRubriche = $this->db->findAll('RUBRICA');
        $rubricheCount = count(array_filter($allRubriche, fn($r) => ($r['id_uda'] ?? '') === $idUda));

        $allLab = $this->db->findAll('VALUTAZIONI_LABORATORIO');
        $labCount = count(array_filter($allLab, fn($v) => ($v['id_uda'] ?? '') === $idUda));

        $classiAssegnate = $udaComplete['classi_assegnate'] ?? [];
        $uniqueAssignments = [];
        foreach ($classiAssegnate as $assegnazione) {
            $classId = (string)($assegnazione['id_classe'] ?? '');
            $subjectId = (string)($assegnazione['id_materia_cv'] ?? '');
            $key = $classId . '|' . $subjectId;
            if ($key === '|') {
                continue;
            }
            $uniqueAssignments[$key] = true;
        }

        return [
            'uda' => $udaComplete['uda'],
            'num_obiettivi' => count($obiettivi),
            'num_materiali' => count($udaComplete['materiali'] ?? []),
            'num_rubriche' => $rubricheCount,
            'num_laboratorio' => $labCount,
            'num_domande' => $statsdomande['totale'] ?? 0,
            'num_classi' => count($uniqueAssignments)
        ];
    }

    /**
     * Genera il pacchetto di esportazione: documento Word riepilogativo + file Excel
     * protetti in un unico archivio ZIP cifrato con password.
     *
     * @param string $idUda
     * @param array $sezioni
     * @param string $password
     * @return string Path dell'archivio ZIP
     */
    public function esportaUDAPacchetto(string $idUda, array $sezioni = [], string $password = ''): string
    {
        $password = trim($password);
        if ($password === '') {
            throw new Exception('Password ZIP obbligatoria per proteggere i dati.');
        }

        $wordPath = $this->esportaUDAWord($idUda, $sezioni);
        $excelPath = $this->esportaUDADatiExcel($idUda);

        $udaComplete = $this->udaManager->getUDAComplete($idUda);
        $base = $this->normalizzaNomeFile($udaComplete['uda']->titolo ?? 'UDA');
        $zipPath = ROOT_PATH . '/storage/exports/' . $base . '_esportazione_' . date('Ymd_His') . '.zip';

        $this->creaZipProtetto([$wordPath, $excelPath], $password, $zipPath);

        // I file intermedi non cifrati non devono restare su disco.
        @unlink($wordPath);
        @unlink($excelPath);

        return $zipPath;
    }

    /**
     * Crea un archivio ZIP cifrato (AES-256 quando disponibile) con i file indicati.
     *
     * @param list<string> $filePaths
     * @param string $password
     * @param string $zipPath
     * @return string
     */
    public function creaZipProtetto(array $filePaths, string $password, string $zipPath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new Exception('Supporto ZIP non disponibile.');
        }

        $dir = dirname($zipPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Impossibile creare l'archivio ZIP.");
        }

        foreach ($filePaths as $filePath) {
            if (!is_file($filePath)) {
                $zip->close();
                throw new Exception('File da archiviare non trovato.');
            }
            $localName = basename($filePath);
            if (method_exists($zip, 'setEncryptionName')) {
                $zip->addFile($filePath, $localName);
                $zip->setEncryptionName($localName, \ZipArchive::EM_AES_256, $password);
            } else {
                $zip->setPassword($password);
                $zip->addFile($filePath, $localName);
            }
        }

        $zip->close();
        return $zipPath;
    }

    /**
     * Esporta i dati sensibili dell'UDA (voti, valutazioni, risposte ai test) in Excel.
     */
    public function esportaUDADatiExcel(string $idUda): string
    {
        $udaComplete = $this->udaManager->getUDAComplete($idUda);
        if (!$udaComplete) {
            throw new Exception("UDA non trovata: $idUda");
        }
        $uda = $udaComplete['uda'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $this->aggiungiSheetVoti($spreadsheet->getActiveSheet(), $idUda);
        $this->aggiungiSheetValutazioniRubrica($spreadsheet->createSheet(), $idUda);
        $this->aggiungiSheetValutazioniLaboratorio($spreadsheet->createSheet(), $idUda);
        $this->aggiungiSheetRisposteTest($spreadsheet->createSheet(), $idUda);

        $fileName = $this->normalizzaNomeFile($uda->titolo ?? 'UDA') . '_dati_' . date('Ymd') . '.xlsx';
        $outputPath = ROOT_PATH . '/storage/exports/' . $fileName;
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Scrive intestazioni e righe su un foglio Excel con stile coerente.
     *
     * @param mixed $sheet
     * @param string $title
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function scriviSheet($sheet, string $title, array $headers, array $rows): void
    {
        $sheet->setTitle($title);

        $col = 1;
        foreach ($headers as $header) {
            $cell = $sheet->getCellByColumnAndRow($col, 1);
            $cell->setValue($header);
            $cell->getStyle()->getFont()->setBold(true);
            $cell->getStyle()->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $cell->getStyle()->getFont()->getColor()->setRGB('FFFFFF');
            $col++;
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($headers as $i => $unused) {
                $sheet->getCellByColumnAndRow($col, $rowIndex)->setValue((string)($row[$i] ?? ''));
                $col++;
            }
            $rowIndex++;
        }

        $maxCol = max(1, count($headers));
        for ($c = 1; $c <= $maxCol; $c++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function aggiungiSheetVoti($sheet, string $idUda): void
    {
        $headers = [
            'Studente (interno)', 'Classe/Gruppo', 'Tipo voto', 'Voto', 'Giudizio',
            'Data valutazione', 'Pubblicato', 'Provider pubblicazione', 'ID pubblicazione esterna',
            'Evidenze +', 'Evidenze -', 'Evidenze totali', 'Origine'
        ];
        $rows = [];
        foreach ($this->db->findWhere('VOTI', ['id_uda' => $idUda]) as $v) {
            $rows[] = [
                $this->val($v, 'id_studente', $v['id_studente_cv'] ?? ''),
                $this->val($v, 'id_gruppo', $v['id_classe_cv'] ?? ''),
                $this->val($v, 'tipo_voto'),
                $this->val($v, 'voto'),
                $this->val($v, 'giudizio', $v['descrizione'] ?? ''),
                $this->val($v, 'data_valutazione'),
                $this->siNo($v['pubblicato'] ?? 0),
                $this->val($v, 'provider_pubblicazione'),
                $this->val($v, 'external_publication_id'),
                $this->val($v, 'num_evidenze_positive'),
                $this->val($v, 'num_evidenze_negative'),
                $this->val($v, 'num_evidenze_totali'),
                $this->val($v, 'link_origine'),
            ];
        }
        $this->scriviSheet($sheet, 'Voti', $headers, $rows);
    }

    private function aggiungiSheetValutazioniRubrica($sheet, string $idUda): void
    {
        $headers = ['Studente (interno)', 'Classe/Gruppo', 'Rubrica', 'Voto numerico', 'Voto finale', 'Giudizio', 'Data', 'Pubblicato CV'];
        $rows = [];
        foreach ($this->db->findWhere('VALUTAZIONI_RUBRICA', ['id_uda' => $idUda]) as $v) {
            $rows[] = [
                $this->val($v, 'id_studente', $v['id_studente_cv'] ?? ''),
                $this->val($v, 'id_gruppo', $v['id_classe_cv'] ?? ''),
                $this->val($v, 'id_rubrica'),
                $this->val($v, 'voto_numerico'),
                $this->val($v, 'voto_finale'),
                $this->val($v, 'giudizio', $v['valutazione_testuale'] ?? ''),
                $this->val($v, 'data_valutazione'),
                $this->siNo($v['pubblicato_cv'] ?? ($v['pubblicato'] ?? 0)),
            ];
        }
        $this->scriviSheet($sheet, 'Valutazioni Rubrica', $headers, $rows);
    }

    private function aggiungiSheetValutazioniLaboratorio($sheet, string $idUda): void
    {
        $headers = ['Studente', 'Gruppo', 'Indicatore', 'Nome indicatore', 'Valore', 'Data inserimento', 'Data registrazione', 'Commento'];
        $rows = [];
        foreach ($this->db->findWhere('VALUTAZIONI_LABORATORIO', ['id_uda' => $idUda]) as $v) {
            $rows[] = [
                $this->val($v, 'id_studente'),
                $this->val($v, 'id_gruppo'),
                $this->val($v, 'id_indicatore'),
                $this->val($v, 'nome_indicatore'),
                $this->val($v, 'valore'),
                $this->val($v, 'data_inserimento'),
                $this->val($v, 'data_registrazione'),
                $this->val($v, 'commento'),
            ];
        }
        $this->scriviSheet($sheet, 'Valutazioni Laboratorio', $headers, $rows);
    }

    private function aggiungiSheetRisposteTest($sheet, string $idUda): void
    {
        $headers = ['Test', 'Domanda', 'Etichetta domanda', 'Studente', 'Gruppo', 'Confidenza livello', 'Confidenza valore', 'Corretta', 'Score CBA', 'Score classico', 'Punteggio normalizzato', 'Timestamp'];
        $rows = [];
        foreach ($this->db->findWhere('TEST', ['id_uda' => $idUda]) as $test) {
            $testId = $test['id_test'] ?? '';
            if ($testId === '') {
                continue;
            }
            foreach ($this->db->findWhere('TEST_CBM_RISPOSTE', ['id_test' => $testId]) as $r) {
                $rows[] = [
                    $this->val($r, 'id_test'),
                    $this->val($r, 'id_domanda'),
                    $this->val($r, 'domanda_label'),
                    $this->val($r, 'id_studente'),
                    $this->val($r, 'id_gruppo'),
                    $this->val($r, 'confidenza_livello'),
                    $this->val($r, 'confidenza_valore'),
                    $this->siNo($r['corretta'] ?? 0),
                    $this->val($r, 'score_cba'),
                    $this->val($r, 'score_classico'),
                    $this->val($r, 'punteggio_normalizzato'),
                    $this->val($r, 'timestamp_risposta'),
                ];
            }
        }
        $this->scriviSheet($sheet, 'Risposte Test', $headers, $rows);
    }

    private function val(array $row, string $key, string $default = ''): string
    {
        $v = $row[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        return (string)$v;
    }

    private function siNo($value): string
    {
        $v = strtolower(trim((string)$value));
        return in_array($v, ['1', 'si', 'sì', 'yes', 'true'], true) ? 'Sì' : 'No';
    }
}
