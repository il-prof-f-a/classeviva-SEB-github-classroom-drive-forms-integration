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
            $sezioni = ['info', 'obiettivi', 'materiali', 'rubriche', 'laboratorio', 'domande', 'statistiche'];
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
}
