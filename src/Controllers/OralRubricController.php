<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\StudentIdentityResolver;
use App\Integration\ClasseVivaAPI;

/**
 * Controller compatibile con le API della rubrica orale.
 *
 * La rubrica usa esclusivamente l'adapter SQL e l'identificativo interno
 * dello studente. I nomi vengono richiesti a ClasseViva solo per la risposta
 * corrente e non vengono salvati nel database.
 */
final class OralRubricController
{
    private StudentIdentityResolver $studentResolver;
    private StudentIdentityRepository $studentIdentities;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private ClasseVivaAPI $clvApi
    ) {
        $userId = (string)($_SESSION['user_id'] ?? 'system');
        $students = new StudentRepository($db, $userId);
        $this->studentIdentities = new StudentIdentityRepository($db, $userId);
        $this->studentResolver = new StudentIdentityResolver(
            $students,
            $this->studentIdentities,
            new GroupStudentRepository($db, $userId),
            new StudentResourceRepository($db, $userId)
        );
    }

    public function loadEvaluations(int $idStudente, int $idMateria, bool $visualizzaVotiVecchi = false): array
    {
        $externalId = (string)$idStudente;
        $student = $this->studentResolver->resolve('classeviva', $externalId);
        $internalId = (string)($student['id_studente'] ?? '');
        $rows = $internalId !== ''
            ? $this->db->findWhere('VALUTAZIONI_LABORATORIO', ['id_studente' => $internalId])
            : [];
        $indicatori = [];
        foreach ($this->db->findAll('INDICATORI_LABORATORIO') as $row) {
            $indicatorId = (string)($row['id_indicatore'] ?? '');
            if ($indicatorId === '') continue;
            $indicatori[] = [
                'id_indicatore' => $indicatorId,
                'nomeEsteso' => (string)($row['nome'] ?? ''),
                'descrizioneEstesa' => (string)($row['descrizione'] ?? ''),
                'categoria' => (string)($row['id_categoria'] ?? ''),
                'descrittori' => [],
                'voti_vecchi' => array_values(array_filter($rows, static fn(array $value): bool => (string)($value['id_indicatore'] ?? '') === $indicatorId)),
            ];
        }
        return ['success' => true, 'indicatori' => $indicatori];
    }

    public function saveEvaluation(
        int $idDescrittore,
        int $idStudente,
        int $idMateria,
        int $voto,
        string $username,
        string $commento = ''
    ): array {
        $student = $this->studentResolver->resolveOrCreate('classeviva', (string)$idStudente);
        $studentId = (string)$student['id_studente'];
        $userId = (string)($_SESSION['user_id'] ?? ($username !== '' ? $username : 'system'));
        $idValutazione = 'VAL_' . bin2hex(random_bytes(10));
        if ($voto >= 0) {
            $this->db->insertRow('VALUTAZIONI_LABORATORIO', [
                'id_valutazione' => $idValutazione,
                'id_uda' => '',
                'id_gruppo' => '',
                'id_studente' => $studentId,
                'id_indicatore' => (string)$idDescrittore,
                'nome_indicatore' => '',
                'valore' => (string)$voto,
                'data_inserimento' => date('Y-m-d H:i:s'),
                'data_registrazione' => '',
                'commento' => $commento,
                'prof' => $username,
                'id_utente' => $userId,
            ]);
        }
        return [
            'success' => true,
            'id' => $idValutazione,
            'message' => $voto >= 0 ? 'Inserimento riuscito' : 'Voto rimosso',
        ];
    }

    public function getStudentsForClass(int $idClasse): array
    {
        $result = [];
        $rows = $this->db->findWhere('GRUPPI_STUDENTI', ['external_context_id' => (string)$idClasse]);
        $liveNames = [];
        try {
            foreach ($this->clvApi->getStudentiClasse((string)$idClasse) as $student) {
                $liveNames[(string)($student['id'] ?? '')] = trim(
                    (string)($student['nome'] ?? '') . ' ' . (string)($student['cognome'] ?? '')
                );
            }
        } catch (\Throwable) {
            // ClasseViva resta una capability opzionale: mantieni i placeholder.
        }
        foreach ($rows as $row) {
            $studentId = (string)($row['id_studente'] ?? '');
            if ($studentId === '') continue;
            $name = 'CLV-' . $studentId;
            foreach ($this->studentIdentities->listForStudent($studentId) as $identity) {
                if (($identity['provider'] ?? '') === 'classeviva') {
                    $name = $liveNames[(string)($identity['external_user_id'] ?? '')] ?? $name;
                    break;
                }
            }
            $result[] = ['id_studente' => $studentId, 'nome' => $name];
        }
        return $result;
    }

    public function getUdaInfo(string $udaId): ?array
    {
        $rows = $this->db->findWhere('UDA_ANAGRAFICA', ['id_uda' => $udaId]);
        if ($rows === []) return null;
        return $rows[0];
    }
}
