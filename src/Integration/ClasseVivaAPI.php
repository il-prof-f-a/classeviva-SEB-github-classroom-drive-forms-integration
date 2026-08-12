<?php

namespace App\Integration;

use App\Core\ClasseVivaSessionStore;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

/**
 * ClasseVivaAPI - Integrazione con API Spaggiari ClasseViva
 *
 * Gestisce autenticazione, recupero classi/studenti e pubblicazione voti
 */
class ClasseVivaAPI
{
    private ClientInterface $client;
    private array $config;
    private string $baseUrl;
    private ?string $token = null;
    private ?string $userId = null;
    private ?string $phpSessionId = null;
    private array $fullConfig = [];
    private ?array $tokenMeta = null;
    private ?string $storedPhpSessionId = null;

    public function __construct(array $config, ?ClientInterface $client = null)
    {
        $this->fullConfig = $config;
        $this->config = $config['classeviva'] ?? [];
		

        // Base URL senza trailing slash per costruzione manuale URL
        $this->baseUrl = rtrim((string)($this->config['base_url'] ?? 'https://web.spaggiari.eu/rest/v1'), '/');

        $this->applyStoredToken($this->config['token'] ?? null);

        $this->client = $client ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->config['timeout'] ?? 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'CVVS/std/4.2.3 Android/12',
                'Z-Dev-Apikey' => 'XXXXXXXXX'
            ]
        ]);

		//$this->loadTemporaryToken();
        //$this->loadTemporaryTokenFromConstants();
    }

    /**
     * Autentica l'utente e ottiene il token
     *
     * @param string|null $username Username (se null usa quello passato in precedenza o da config/env)
     * @param string|null $password Password (se null usa quella passata in precedenza o da config/env)
     * @return bool True se autenticazione riuscita
     */
    public function authenticate(?string $username = null, ?string $password = null): bool
    {
        if (!$username || !$password) {
            throw new Exception("Credenziali ClasseViva mancanti (username, password)");
        }

        try {
            // Autenticazione REST API - come da codice di esempio
            $response = $this->client->post("{$this->baseUrl}/auth/login", [
                'json' => [
                    'ident' => null,
                    'pass' => $password,
                    'uid' => $username
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['token'])) {
                $this->token = $data['token'];
                // Estrai l'ID numerico dall'ident (es: "S1234567" -> "1234567")
                $this->userId = preg_replace("/[^0-9]/", "", $data['ident'] ?? '');
                $this->setTokenPayload([
                    'token' => $this->token,
                    'user_id' => $this->userId,
                    'ident' => $data['ident'] ?? null,
                    'created' => $data['created'] ?? time(),
                    'expire' => $data['expire'] ?? null,
                    'expires_in' => $data['expires_in'] ?? null,
                    'release' => $data['release'] ?? null,
                ]);
                return true;
            }

            return false;

        } catch (GuzzleException $e) {
            throw new Exception("Errore autenticazione ClasseViva: " . $e->getMessage());
        } finally {
            // Le credenziali vivono soltanto per la durata di questa richiesta.
            $username = null;
            $password = null;
        }
    }

    /**
     * Recupera la lista delle classi
     *
     * @return array Lista delle classi
     */
    public function getClasses(): array
    {
        $this->ensureAuthenticated();

        try {
            // Endpoint corretto per ottenere classi con studenti
            $response = $this->client->get("{$this->baseUrl}/teachers/{$this->userId}/classes/mine/students1", [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['classes'] ?? [];

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero classi: " . $e->getMessage());
        }
    }

    /**
     * Recupera gli studenti di una classe
     *
     * @param string $classId ID della classe
     * @return array Lista studenti
     */
    public function getStudents(string $classId): array
    {
        // Usa il metodo getStudentiClasse() che è già implementato e funzionante
        return $this->getStudentiClasse($classId);
    }

    /**
     * Elenco semplificato delle classi del docente.
     */
    public function listTeacherClasses(bool $withSubjects = false): array
    {
        if ($withSubjects) {
            return $this->getClassesWithTeacherSubjects();
        }

        return $this->getClasses();
    }

    /**
     * Wrap semplificato per ottenere la lista studenti di una classe.
     */
    public function listStudentsForClass(string $classId): array
    {
        return $this->getStudentiClasse($classId);
    }

    /**
     * Materie afferenti al docente.
     */
    public function listSubjects(): array
    {
        return $this->getSubjects();
    }

    /**
     * Recupera le materie insegnate
     *
     * @return array Lista materie
     */
    public function getSubjects(): array
    {
        $this->ensureAuthenticated();

        try {
            $response = $this->client->get('/teacher/subjects', [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['subjects'] ?? [];

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero materie: " . $e->getMessage());
        }
    }

    /**
     * Recupera tutte le classi con le materie che il docente insegna
     *
     * Questo metodo ritorna solo le materie che il docente autenticato
     * effettivamente insegna in ogni classe.
     *
     * Usa l'endpoint /teachers/{tID}/classes/mine/students1 che ritorna
     * direttamente subjects con subjectId e subjectDesc
     *
     * @return array Array strutturato: [class_id => [class_data + subjects => [...]]]
     */
    public function getClassesWithTeacherSubjects(): array
    {
        $this->ensureAuthenticated();

        try {
            // Usa l'endpoint che ritorna classi con materie del docente
            $response = $this->client->get("{$this->baseUrl}/teachers/{$this->userId}/classes/mine/students1", [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $classes = $data['classes'] ?? [];

            // Struttura i dati per facilitare l'uso
            $result = [];
            foreach ($classes as $class) {
                $classId = $class['id'] ?? $class['classId'] ?? '';
                $className = $class['name'] ?? $class['className'] ?? '';

                // Estrai le materie che il docente insegna in questa classe
                $rawSubjects = $class['subjects'] ?? [];

                // Normalizza i dati delle materie in formato consistente
                $subjects = [];
                foreach ($rawSubjects as $subject) {
                    // L'API ClasseViva ritorna direttamente subjectId e subjectDesc
                    // (come confermato dal codice funzionante di piuomeno)
                    $subjectId = $subject['subjectId'] ?? '';
                    $subjectName = $subject['subjectDesc'] ?? '';

                    // Fallback solo se mancano i campi principali
                    if (empty($subjectName) && !empty($subjectId)) {
                        $subjectName = "Materia {$subjectId}";
                    }

                    $subjects[] = [
                        'id' => $subjectId,
                        'name' => $subjectName,
                        'raw' => $subject // Mantieni dati originali per debug
                    ];
                }

                $result[] = [
                    'id' => $classId,
                    'name' => $className,
                    'subjects' => $subjects,
                    'raw_data' => $class // Mantieni i dati grezzi per debug
                ];
            }

            return $result;

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero classi con materie: " . $e->getMessage());
        }
    }

    /**
     * Pubblica voti multipli (batch)
     *
     * @param array $grades Array di voti da pubblicare
     * @return array Risultati pubblicazione
     */
    public function publishGradeBatch(array $grades): array
    {
        $results = [];

        foreach ($grades as $index => $gradeData) {
            try {
                $result = $this->publishGrade($gradeData);
                $results[$index] = [
                    'success' => true,
                    'data' => $result
                ];
            } catch (Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'data' => $gradeData
                ];
            }
        }

        return $results;
    }

    /**
     * Recupera il calendario scolastico
     *
     * @return array Eventi del calendario
     */
    public function getCalendar(): array
    {
        $this->ensureAuthenticated();

        try {
            $response = $this->client->get('/teacher/calendar', [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['events'] ?? [];

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero calendario: " . $e->getMessage());
        }
    }

    /**
     * Ottiene il PHP Session Token (PHPSESSID) per accesso interfaccia web
     *
     * IMPORTANTE: Questo token è necessario per operazioni web-based come
     * pubblicazione voti, note, ecc. Usare con ESTREMA cautela.
     *
     * @return string|null Session token (PHPSESSID) o null se fallito
     */
    public function getPhpSessionToken(): ?string
    {
        if (empty($this->token)) {
            throw new Exception("Token REST ClasseViva mancante per generare la sessione web");
        }

        try {
            $response = $this->client->get('https://web.spaggiari.eu/cvv/app/default/regvoti.php', [
                'headers' => [
                    'Z-Auth-Token' => $this->token,
                    'User-Agent' => 'Mozilla/5.0',
                ],
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => $this->config['timeout'] ?? 30,
            ]);

            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                if (preg_match('/(?:^|;\s*)PHPSESSID=([^;]+)/i', $cookie, $matches)) {
                    $this->phpSessionId = $matches[1];
                    $this->storedPhpSessionId = $matches[1];
                    $this->tokenMeta ??= ['token' => $this->token];
                    $this->tokenMeta['php_session_id'] = $matches[1];
                    $this->persistTokenPayloadToPhpSession();
                    return $matches[1];
                }
            }

            return null;
        } catch (GuzzleException $e) {
            throw new Exception("Errore ottenimento session token: " . $e->getMessage());
        }
    }

    /**
     * Verifica che il token sia presente e valido (senza ricorrere a credenziali).
     */
    private function ensureAuthenticated(): void
    {
        if (empty($this->token)) {
            throw new Exception("Token ClasseViva mancante");
        }

        if (!$this->validateToken(false)) {
            throw new Exception("Token ClasseViva non valido o scaduto");
        }
    }

    /**
     * Ottiene lista studenti di una classe (GDPR-compliant)
     *
     * @param string $classId ID della classe ClasseViva
     * @return array Lista studenti con id, nome, cognome
     */
    public function getStudentiClasse(string $classId): array
    {
        $this->ensureAuthenticated();

        try {
            // L'endpoint /classes/mine/students1 già include gli studenti per ogni classe
            // Quindi carichiamo tutte le classi e filtriamo per quella richiesta
            $response = $this->client->get("{$this->baseUrl}/teachers/{$this->userId}/classes/mine/students1", [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $classes = $data['classes'] ?? [];

            // Cerca la classe specifica
            foreach ($classes as $class) {
                $currentClassId = (string) ($class['classId'] ?? $class['id'] ?? '');

                if ($currentClassId === $classId) {
                    $students = $class['students'] ?? [];

                    // Normalizza formato di ritorno
                    $result = [];
                    foreach ($students as $student) {
                        $result[] = [
                            'id' => (string) ($student['studentId'] ?? $student['id'] ?? ''),
                            'nome' => $student['firstName'] ?? $student['nome'] ?? '',
                            'cognome' => $student['lastName'] ?? $student['cognome'] ?? ''
                        ];
                    }

                    return $result;
                }
            }

            // Classe non trovata
            throw new Exception("Classe con ID $classId non trovata");

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero studenti classe: " . $e->getMessage());
        }
    }

    /**
     * Ottiene dettagli singolo studente (GDPR-compliant)
     *
     * NOTA: Questo metodo ritorna dati personali in real-time dalle API
     * MAI salvare questi dati nel database locale!
     *
     * @param string $studentId ID studente ClasseViva
     * @return array Dati studente (id, nome, cognome)
     */
    public function getStudente(string $studentId): array
    {
        $this->ensureAuthenticated();

        try {
            // Cerca studente in tutte le classi
            $classes = $this->getClasses();

            foreach ($classes as $class) {
                $students = $this->getStudentiClasse($class['id'] ?? $class['classId'] ?? '');

                foreach ($students as $student) {
                    if ($student['id'] === $studentId) {
                        return $student;
                    }
                }
            }

            throw new Exception("Studente ID $studentId non trovato");

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero studente: " . $e->getMessage());
        }
    }

    /**
     * Ottiene il token corrente
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Imposta manualmente il token (per sessioni persistenti)
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
        if ($this->tokenMeta === null) {
            $this->tokenMeta = ['token' => $token];
        } else {
            $this->tokenMeta['token'] = $token;
        }
    }

    /**
     * Restituisce il payload completo del token (token stringa + metadata).
     */
    public function getTokenPayload(): ?array
    {
        return $this->tokenMeta;
    }

    /**
     * Imposta il token partendo da un payload strutturato (usato per la persistenza).
     */
    public function setTokenPayload(?array $payload): void
    {
        if (!$payload || empty($payload['token'])) {
            $this->tokenMeta = null;
            $this->token = null;
            $this->userId = null;
            $this->phpSessionId = null;
            $this->storedPhpSessionId = null;
            return;
        }

        $this->tokenMeta = $payload;
        $this->token = $payload['token'];
        $this->userId = $payload['user_id'] ?? $this->userId;
    }

    private function applyStoredToken(?array $payload): void
    {
        if (!is_array($payload)) {
            return;
        }
        $this->setTokenPayload($payload);
        // Se nel payload è presente un PHPSESSID già valido, conservalo
        if (!empty($payload['php_session_id'])) {
            $this->phpSessionId = $payload['php_session_id'];
            $this->storedPhpSessionId = $payload['php_session_id'];
        }
    }


    /**
     * Garantisce che esista un PHPSESSID valido.
     * Usa la sessione web corrente se valida; in caso contrario la rigenera
     * dal token REST senza conservare o riutilizzare le credenziali.
     */
    private function ensurePhpSession(): void
    {
        if (!empty($this->phpSessionId)) {
            if ($this->pingWebSession(true)) {
                return;
            }

            $this->phpSessionId = null;
            $this->storedPhpSessionId = null;
            unset($this->tokenMeta['php_session_id']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $store = new ClasseVivaSessionStore($_SESSION);
                $store->clearPhpSessionId();
            }
        }

        if (!empty($this->storedPhpSessionId)) {
            $this->phpSessionId = $this->storedPhpSessionId;
            return;
        }

        if (!empty($this->token) && $this->getPhpSessionToken()) {
            return;
        }

        throw new Exception(
            "Sessione web ClasseViva non disponibile. " .
            "Rigenera il token ClasseViva dalla finestra di autenticazione."
        );
    }

    /**
     * Verifica che il token attivo sia ancora valido (chiamata allo stesso endpoint usato abitualmente).
     * Facoltativamente effettua un ping alla sessione web per mantenerla viva.
     */
    public function validateToken(bool $throwOnFailure = false, bool $pingSession = false): bool
    {
        if (empty($this->token)) {
            if ($throwOnFailure) {
                throw new Exception("Token ClasseViva mancante o incompleto");
            }
            return false;
        }

        try {
            $response = $this->client->get("{$this->baseUrl}/auth/ticket", [
                'headers' => [
                    'Z-Auth-Token' => $this->token,
                    'Accept' => 'application/json'
                ],
                'timeout' => $this->config['timeout'] ?? 30,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode((string)$response->getBody(), true);
            $isValid = $statusCode >= 200 && $statusCode < 300
                && is_array($body)
                && !empty($body['ticket']);
            if (!$isValid && $throwOnFailure) {
                throw new Exception("Token ClasseViva non valido o scaduto");
            }
            if ($isValid && $pingSession) {
                $this->pingWebSession();
            }
            return $isValid;
        } catch (GuzzleException $e) {
            if ($throwOnFailure) {
                throw new Exception("Token ClasseViva non valido: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Esegue un ping leggero alla sessione web (PHPSESSID) per mantenerla viva.
     */
    public function pingWebSession(bool $force = false): bool
    {
        if (!$force && session_status() === PHP_SESSION_ACTIVE) {
            $lastPing = $_SESSION['cv_web_ping_at'] ?? 0;
            if (is_numeric($lastPing) && (time() - (int)$lastPing) < 300) {
                return true;
            }
        }

        if (empty($this->phpSessionId) && !empty($this->storedPhpSessionId)) {
            $this->phpSessionId = $this->storedPhpSessionId;
        }

        if (empty($this->phpSessionId)) {
            return false;
        }

        try {
            $response = $this->client->get('https://web.spaggiari.eu/cvv/app/default/regvoti.php', [
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'User-Agent' => 'Mozilla/5.0'
                ],
                'timeout' => 8,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $location = strtolower($response->getHeaderLine('Location'));
            $redirectsToLogin = str_contains($location, '/auth-') || str_contains($location, 'login');
            if ($statusCode >= 200 && $statusCode < 400 && !$redirectsToLogin) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['cv_web_ping_at'] = time();
                }
                return true;
            }
        } catch (GuzzleException $e) {
            // Best effort: non bloccare il flusso
        }

        return false;
    }

    private function persistTokenPayloadToPhpSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($this->tokenMeta['token'])) {
            return;
        }

        $store = new ClasseVivaSessionStore($_SESSION);
        $store->storeTokenPayload($this->tokenMeta);
    }

    /**
     * Pubblica un'annotazione sul registro
     *
     * Le annotazioni sono note testuali associate a uno studente (es. evidenze laboratorio +/-)
     * Diverse dai voti numerici, sono utili per valutazioni formative e competenze trasversali
     *
     * @param array $annotationData Dati dell'annotazione:
     *   - student_id: ID studente ClasseViva (obbligatorio)
     *   - class_id: ID classe (obbligatorio)
     *   - subject_id: ID materia (obbligatorio)
     *   - text: Testo annotazione (obbligatorio)
     *   - date: Data annotazione YYYY-MM-DD (obbligatorio)
     *   - type: Tipo annotazione (default: 'positive')
     *   - visible_to_student: Visibile allo studente (default: true)
     * @return array Risultato pubblicazione
     * @throws Exception Se pubblicazione fallisce
     */
    public function publishAnnotation(array $annotationData): array
    {
		
        $this->ensureAuthenticated();

        // Valida dati obbligatori
        $required = ['student_id', 'class_id', 'subject_id', 'text', 'date'];
        foreach ($required as $field) {
            if (!isset($annotationData[$field])) {
                throw new Exception("Campo obbligatorio mancante per annotazione: {$field}");
            }
        }

        try {
            // Prova diversi endpoint possibili per le annotazioni
            $endpoints = [
                "/students/{$annotationData['student_id']}/notes",
                "/teachers/{$this->userId}/students/{$annotationData['student_id']}/notes",
                "/students/{$annotationData['student_id']}/noticeboard",
                "/teachers/{$this->userId}/noticeboard"
            ];

            $payload = [
                'evtCode' => 'NTTE',
                'evtDate' => $annotationData['date'],
                'evtText' => $annotationData['text'],
                'evtHCode' => '',
                'cntId' => $annotationData['subject_id'],
                'cntName' => $annotationData['subject_name'] ?? '',
                'readStatus' => $annotationData['visible_to_student'] ?? true ? 'Y' : 'N'
            ];

            $lastError = null;
            foreach ($endpoints as $endpoint) {
                try {
                    $response = $this->client->post("{$this->baseUrl}{$endpoint}", [
                        'headers' => [
                            'Z-Auth-Token' => $this->token,
                            'Content-Type' => 'application/json'
                        ],
                        'json' => $payload
                    ]);

                    $data = json_decode($response->getBody(), true);
                    // Notifica via email (stesso meccanismo dei voti) se non soppressa
                    if (empty($annotationData['__batch_suppress_notification'])) {
                        $this->sendAnnotationNotification($annotationData);
                    }
                    return $data;

                } catch (GuzzleException $e) {
                    $lastError = $e->getMessage();
                    // Prova endpoint successivo
                    continue;
                }
            }

            // Se nessun endpoint ha funzionato, lancia l'ultimo errore
            throw new Exception("Nessun endpoint disponibile per annotazioni. Ultimo errore: " . $lastError);

        } catch (Exception $e) {
            throw new Exception("Errore pubblicazione annotazione: " . $e->getMessage());
        }
    }

    /**
     * Pubblica annotazioni multiple (batch)
     *
     * @param array $annotations Array di annotazioni da pubblicare
     * @return array Risultati pubblicazione con successi e fallimenti
     */
    public function publishAnnotationBatch(array $annotations): array
    {
        $results = [];
        $successAnnotations = [];

        foreach ($annotations as $index => $annotationData) {
            try {
                // Evita notifiche per singola annotazione: invieremo dopo in batch
                $annotationData['__batch_suppress_notification'] = true;
                $result = $this->publishAnnotation($annotationData);
                $successAnnotations[] = $annotationData;
                $results[$index] = [
                    'success' => true,
                    'data' => $result
                ];
            } catch (Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'data' => $annotationData
                ];
            }
        }

        // Invia una notifica per batch (per studente)
        if (!empty($successAnnotations)) {
            $this->sendAnnotationBatchNotification($successAnnotations);
        }

        return $results;
    }

    /**
     * Invia notifica email per annotazione pubblicata
     *
     * Riutilizza NotificationManager (stessa config SMTP dei voti).
     */
    private function sendAnnotationNotification(array $annotationData): void
    {
        try {
            if (empty($annotationData['student_id']) || empty($annotationData['class_id'])) {
                return;
            }

            $studentInfo = $this->getStudentInfo($annotationData['student_id'], $annotationData['class_id']);
            if (!$studentInfo) {
                return;
            }

            $notificationManager = new \App\Core\NotificationManager($this->fullConfig);

            $gradeData = [
                'student_id'   => $annotationData['student_id'],
                'class_id'     => $annotationData['class_id'],
                'subject_id'   => $annotationData['subject_id'] ?? '',
                'subject_name' => $annotationData['subject_name'] ?? 'Annotazione',
                'grade_type'   => 'annotazione',
                // usa testo (troncato) come valore "voto" da mostrare
                'grade_value'  => mb_substr($annotationData['text'] ?? 'Annotazione', 0, 120),
                'date'         => $annotationData['date'] ?? date('Y-m-d'),
                'notes'        => $annotationData['text'] ?? ''
            ];

            // Riusa il template dei voti; il valore mostra il testo annotazione
            $notificationManager->sendGradePublishedNotification($gradeData, $studentInfo);
        } catch (\Exception $e) {
            error_log("Errore invio notifica annotazione: " . $e->getMessage());
        }
    }

    /**
     * Invia una sola notifica per batch di annotazioni (una per studente)
     */
    private function sendAnnotationBatchNotification(array $annotations): void
    {
        // Raggruppa per studente/classe
        $byStudent = [];
        foreach ($annotations as $ann) {
            $key = ($ann['class_id'] ?? '') . '|' . ($ann['student_id'] ?? '');
            $byStudent[$key]['class_id'] = $ann['class_id'] ?? '';
            $byStudent[$key]['student_id'] = $ann['student_id'] ?? '';
            $byStudent[$key]['subject_id'] = $ann['subject_id'] ?? '';
            $byStudent[$key]['subject_name'] = $ann['subject_name'] ?? 'Annotazione';
            $byStudent[$key]['list'][] = $ann;
        }

        foreach ($byStudent as $group) {
            if (empty($group['student_id']) || empty($group['class_id'])) {
                continue;
            }
            $studentInfo = $this->getStudentInfo($group['student_id'], $group['class_id']);
            if (!$studentInfo) {
                continue;
            }

            $notificationManager = new \App\Core\NotificationManager($this->fullConfig);

            // Compone note con tutte le annotazioni
            $lines = [];
            foreach ($group['list'] as $ann) {
                $lines[] = ($ann['date'] ?? date('Y-m-d')) . ' | ' . ($ann['text'] ?? '');
            }

            $gradeData = [
                'student_id'   => $group['student_id'],
                'class_id'     => $group['class_id'],
                'subject_id'   => $group['subject_id'],
                'subject_name' => $group['subject_name'],
                'grade_type'   => 'annotazione',
                'grade_value'  => 'Annotazioni: ' . count($group['list']),
                'date'         => date('Y-m-d'),
                'notes'        => implode("\n", $lines)
            ];

            $notificationManager->sendGradePublishedNotification($gradeData, $studentInfo);
        }
    }

    /**
     * Recupera annotazioni per uno studente
     *
     * @param string $studentId ID studente
     * @param string|null $startDate Data inizio periodo (YYYY-MM-DD)
     * @param string|null $endDate Data fine periodo (YYYY-MM-DD)
     * @return array Lista annotazioni
     */
    public function getStudentAnnotations(string $studentId, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->ensureAuthenticated();

        try {
            $queryParams = ['studentId' => $studentId];

            if ($startDate) {
                $queryParams['startDate'] = $startDate;
            }
            if ($endDate) {
                $queryParams['endDate'] = $endDate;
            }

            $response = $this->client->get('/teacher/annotations', [
                'headers' => [
                    'Z-Auth-Token' => $this->token
                ],
                'query' => $queryParams
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['annotations'] ?? [];

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero annotazioni: " . $e->getMessage());
        }
    }


    /**
     * Pubblica voto su registro elettronico ClasseViva (endpoint WEB - votiio.php)
     *
     * Supporta 3 tipi di voto:
     * - 'orale' (causale_codice: VO)
     * - 'scritto' (causale_codice: VS)
     * - 'pratico' (causale_codice: VA - Altro)
     *
     * Valori speciali:
     * - 'a' = Assente (valore: -5)
     * - 'i' = Impreparato (valore: -4)
     * - Numero da 1 a 10 = Voto numerico con punto es 9.5 = 9½
     *
     * Parametri gradeData:
     *   - student_id: ID studente numerico (obbligatorio)
     *   - class_id: ID classe (obbligatorio)
     *   - subject_id: ID materia (obbligatorio)
     *   - subject_name: Nome materia (obbligatorio)
     *   - grade_type: 'orale', 'scritto', 'pratico' (obbligatorio)
     *   - grade_value: Voto numerico 1-10, 'a' (assente), 'i' (impreparato) (obbligatorio)
     *   - date: Data in formato Y-m-d (obbligatorio)
     *   - description: Descrizione/Argomento (default: '')
     *   - notes: Note aggiuntive (default: '')
     *   - weight: Peso percentuale 1-100 (default: 100)
     *   - group_id: ID gruppo (default: '')
     *   - uda_id: ID UDA se collegato (default: '')
     *
     * @param array $gradeData
     * @return array Risultato pubblicazione
     * @throws Exception Se pubblicazione fallisce
     */
    /**
     * Determina il prefisso del periodo scolastico per un voto in base alla data
     *
     * Il prefisso determina in quale COLONNA del registro ClasseViva viene scritto il voto.
     * Il numero nel prefisso (1, 2 o 3) corrisponde direttamente alla colonna nel registro.
     *
     * Configurazione per 2 periodi (quadrimestri):
     * - S1 = Colonna 1 = 1° quadrimestre (voti inseriti fino alla data limite)
     * - S3 = Colonna 3 = 2° quadrimestre/pentamestre (voti dopo la data limite)
     *
     * Configurazione per 3 periodi (trimestri):
     * - S1 = Colonna 1 = 1° trimestre (voti fino alla prima data limite)
     * - S2 = Colonna 2 = 2° trimestre (voti tra la prima e la seconda data limite)
     * - S3 = Colonna 3 = 3° trimestre (voti dopo la seconda data limite)
     *
     * @param string|null $date Data del voto in formato Y-m-d
     * @return string Prefisso periodo (S1, S2, S3) che determina la colonna del registro
     */
    private function resolveGradePrefix(?string $date): string
    {
        if (empty($date)) {
            return 'S1';
        }
        $parsed = strtotime($date);
        if ($parsed === false) {
            return 'S1';
        }

        // Leggi configurazione periodi dalle impostazioni utente
        $periodCount = (int)($this->fullConfig['classeviva']['period_count'] ?? 2);
        $periodDate1 = $this->fullConfig['classeviva']['period_date_1'] ?? '01-31'; // MM-DD
        $periodDate2 = $this->fullConfig['classeviva']['period_date_2'] ?? '';      // MM-DD

        // Estrai mese e giorno dalla data del voto
        $votoMonth = (int)date('n', $parsed);
        $votoDay = (int)date('j', $parsed);
        $votoYear = (int)date('Y', $parsed);

        // Funzione helper per confrontare date (gestisce l'anno scolastico)
        $parseLimit = function(string $mmdd) use ($votoYear, $votoMonth): ?array {
            if (empty($mmdd) || strlen($mmdd) !== 5) return null;
            $parts = explode('-', $mmdd);
            if (count($parts) !== 2) return null;
            $limitMonth = (int)$parts[0];
            $limitDay = (int)$parts[1];
            // Per l'anno scolastico, se la data limite è prima di settembre,
            // appartiene all'anno successivo rispetto all'inizio dell'anno scolastico
            $limitYear = $votoYear;
            if ($limitMonth < 9 && $votoMonth >= 9) {
                // Il voto è a settembre-dicembre, la data limite è nell'anno successivo
                $limitYear = $votoYear + 1;
            } elseif ($votoMonth < 9 && $limitMonth >= 9) {
                // Il voto è a gennaio-agosto, la data limite è nell'anno precedente
                $limitYear = $votoYear - 1;
            }
            return ['month' => $limitMonth, 'day' => $limitDay, 'year' => $limitYear];
        };

        // Confronta data del voto con le date limite
        $votoTimestamp = mktime(0, 0, 0, $votoMonth, $votoDay, $votoYear);

        $limit1 = $parseLimit($periodDate1);
        $limit2 = $parseLimit($periodDate2);

        if ($periodCount === 3 && $limit1 && $limit2) {
            // 3 periodi (trimestri)
            $limit1Timestamp = mktime(0, 0, 0, $limit1['month'], $limit1['day'], $limit1['year']);
            $limit2Timestamp = mktime(0, 0, 0, $limit2['month'], $limit2['day'], $limit2['year']);

            if ($votoTimestamp <= $limit1Timestamp) {
                return 'S1'; // Colonna 1 = 1° trimestre
            } elseif ($votoTimestamp <= $limit2Timestamp) {
                return 'S2'; // Colonna 2 = 2° trimestre
            } else {
                return 'S3'; // Colonna 3 = 3° trimestre
            }
        } else {
            // 2 periodi (quadrimestri) - default
            if ($limit1) {
                $limit1Timestamp = mktime(0, 0, 0, $limit1['month'], $limit1['day'], $limit1['year']);
                if ($votoTimestamp <= $limit1Timestamp) {
                    return 'S1'; // Colonna 1 = 1° quadrimestre
                } else {
                    return 'S3'; // Colonna 3 = 2° quadrimestre (pentamestre)
                }
            } else {
                // Fallback: usa la logica originale (31 gennaio come separatore)
                if (($votoMonth > 1 || ($votoMonth === 1 && $votoDay > 31)) && $votoMonth < 9) {
                    return 'S3'; // Colonna 3 = pentamestre
                }
                return 'S1'; // Colonna 1 = 1° periodo
            }
        }
    }

    /**
     * Trova il primo slot libero per un tipo di voto
     *
     * Analizza i voti esistenti dello studente e determina il primo spazio
     * disponibile (1-5) per il tipo di voto specificato.
     *
     * La ricerca avviene nella colonna del periodo corretto in base alla data:
     * - 2 periodi: colonna 1 (1° quadrimestre) o colonna 3 (2° quadrimestre)
     * - 3 periodi: colonna 1, 2 o 3 in base al trimestre
     *
     * @param string $studentId ID studente ClasseViva
     * @param string $classId ID classe ClasseViva
     * @param string $subjectId ID materia ClasseViva
     * @param string $gradeType 'orale', 'scritto' o 'pratico'
     * @param string|null $gradeDate Data voto (Y-m-d) per determinare il periodo/colonna
     * @return int Numero slot libero (1-5) o -1 se tutti occupati
     * @throws Exception Se recupero voti fallisce
     */
    public function getNextAvailableSlot(string $studentId, string $classId, string $subjectId, string $gradeType, ?string $gradeDate = null): int
    {
        // Usa getStudentGrades() per recuperare i voti esistenti in modo affidabile
        $grades = $this->getStudentGrades($studentId, $classId, $subjectId, $gradeDate);

        // Controlla quali slot sono occupati per il tipo di voto richiesto
        $occupiedSlots = [];

        if (isset($grades[$gradeType]) && is_array($grades[$gradeType])) {
            foreach ($grades[$gradeType] as $grade) {
                if (isset($grade['slot'])) {
                    $occupiedSlots[$grade['slot']] = true;
                }
            }
        }

        // Trova il primo slot libero (da 1 a 5)
        for ($slot = 1; $slot <= 5; $slot++) {
            if (!isset($occupiedSlots[$slot])) {
                return $slot;
            }
        }

        // Tutti gli slot sono occupati
        // -1 indica che non ci sono spazi liberi
        return -1;
    }

        public function publishGrade(array $gradeData): array
    {
        // Fai SEMPRE login web fresco per ogni operazione (come richiesto da ClasseViva)
        $this->ensureAuthenticated();
        $this->ensurePhpSession();

        if (empty($gradeData['date'])) {
            if (!empty($gradeData['data_valutazione'])) {
                $gradeData['date'] = $gradeData['data_valutazione'];
            } elseif (!empty($gradeData['data_creazione'])) {
                $gradeData['date'] = $gradeData['data_creazione'];
            }
        }

        if (!empty($gradeData['date'])) {
            $parsedDate = strtotime($gradeData['date']);
            if ($parsedDate !== false) {
                $gradeData['date'] = date('Y-m-d', $parsedDate);
            }
        }

        // Valida dati obbligatori
        $required = ['student_id', 'class_id', 'subject_id', 'subject_name', 'grade_type', 'grade_value', 'date'];
        foreach ($required as $field) {
            if (!isset($gradeData[$field])) {
                throw new Exception("Campo obbligatorio mancante per voto: {$field}");
            }
        }

        // Valida tipo voto
        $validTypes = ['orale', 'scritto', 'pratico'];
        if (!in_array($gradeData['grade_type'], $validTypes)) {
            throw new Exception("Tipo voto non valido: {$gradeData['grade_type']}. Usa: orale, scritto, pratico");
        }

        try {
            $url = 'https://web.spaggiari.eu/cvv/app/default/votiio.php';

            // Determina causale (tipo voto) e pattern descrizione_1
            // Pattern ClasseViva: SN_X_Y dove:
            //   N = numero colonna/periodo (1, 2 o 3) - determina in quale colonna del registro va il voto
            //   X = tipo voto (1=scritto, 2=orale, 3=pratico)
            //   Y = posizione slot
            $periodPrefix = $this->resolveGradePrefix($gradeData['date'] ?? null);
            $causaleMap = [
                'orale' => ['codice' => 'VO', 'desc' => '<ztx>Orale</ztx>', 'prefix' => $periodPrefix . '_2_'],
                'scritto' => ['codice' => 'VS', 'desc' => '<ztx>Scritto</ztx>', 'prefix' => $periodPrefix . '_1_'],
                'pratico' => ['codice' => 'VA', 'desc' => '<ztx>Altro</ztx>', 'prefix' => $periodPrefix . '_3_']
            ];

            $causale = $causaleMap[$gradeData['grade_type']];

            // Auto-detect primo slot libero se non specificato
            if (!isset($gradeData['slot_position'])) {
                $slotPosition = $this->getNextAvailableSlot(
                    $gradeData['student_id'],
                    $gradeData['class_id'],
                    $gradeData['subject_id'],
                    $gradeData['grade_type'],
                    $gradeData['date'] ?? null
                );

                if ($slotPosition === -1) {
                    throw new Exception(
                        "Tutti gli slot per {$gradeData['grade_type']} sono occupati. " .
                        "Eliminare voti esistenti o specificare manualmente uno slot da sovrascrivere (1-5)."
                    );
                }
            } else {
                $slotPosition = $gradeData['slot_position'];
                if ($slotPosition < 1 || $slotPosition > 5) {
                    throw new Exception("Slot position deve essere tra 1 e 5, ricevuto: $slotPosition");
                }
            }
            // Converti valore voto
            $gradeValue = $gradeData['grade_value'];
            $valore = $gradeValue;
            $valoreDisplay = $gradeValue;
            $halfSymbol = '½';

            // Gestisci valori speciali
            if (strtolower((string)$gradeValue) === 'a') {
                $valore = -5;  // Assente
                $valoreDisplay = 'a';
            } elseif (strtolower((string)$gradeValue) === 'i') {
                $valore = 4;  // Impreparato (dai curl reali è 4, non -4)
                $valoreDisplay = 'i';
            } else {
                $rawValue = trim((string)$gradeValue);
                $normalizedValue = null;

                if (strpos($rawValue, $halfSymbol) !== false) {
                    if (preg_match('/(\d+)/', $rawValue, $matches)) {
                        $normalizedValue = (float)$matches[1] + 0.5;
                    }
                } else {
                    $numericCandidate = str_replace(',', '.', $rawValue);
                    if (is_numeric($numericCandidate)) {
                        $normalizedValue = (float)$numericCandidate;
                    }
                }

                if ($normalizedValue === null) {
                    throw new Exception("Valore voto non valido: $gradeValue. Usa: 1-10, 'a', 'i'");
                }

                if ($normalizedValue < 1 || $normalizedValue > 10) {
                    throw new Exception("Voto numerico deve essere tra 1 e 10, ricevuto: $normalizedValue");
                }

                $integerPart = floor($normalizedValue);
                $fraction = $normalizedValue - $integerPart;

                if (abs($fraction - 0.5) < 0.00001) {
                    $halfDisplay = $integerPart . $halfSymbol;
                    $valoreDisplay = $halfDisplay;
                    $valore = rtrim(rtrim(number_format($normalizedValue, 2, '.', ''), '0'), '.');
                } elseif (abs($fraction) < 0.00001) {
                    $valoreDisplay = (string)(int)$normalizedValue;
                    $valore = $valoreDisplay;
                } else {
                    $valore = rtrim(rtrim(number_format($normalizedValue, 2, '.', ''), '0'), '.');
                    $valoreDisplay = $valore;
                }
            }
            // Converti data da Y-m-d a d-m-Y
            $date = \DateTime::createFromFormat('Y-m-d', $gradeData['date']);
            if (!$date) {
                throw new Exception("Formato data non valido: {$gradeData['date']}. Usa Y-m-d");
            }
            $dataEvento = $date->format('d-m-Y');

            // Genera descrizione_1 con pattern S1_X_Y (trimestre) o S3_X_Y (pentamestre)
            $descrizione1 = $causale['prefix'] . $slotPosition;

            // TRACCIABILITÀ: Salva nel registro VOTI locale PRIMA della pubblicazione
            $votoId = null;
            try {
                $tracker = new ClasseVivaVotiTracker($this->fullConfig);
                $studentInfo = $this->getStudentInfo($gradeData['student_id'], $gradeData['class_id']);
                $studentName = ($studentInfo['nome'] ?? '') . ' ' . ($studentInfo['cognome'] ?? '');

                $votoId = $tracker->saveGradeToVoti($gradeData, $studentName);
            } catch (\Exception $e) {
                error_log("ClasseVivaAPI: Errore salvataggio VOTI tracciabilità: " . $e->getMessage());
                // Continua comunque con la pubblicazione
            }

            // Aggiungi placeholder tracciabilità alle note
            $nota1 = $gradeData['notes'] ?? '';
            if ($votoId) {
                $nota1 .= (empty($nota1) ? '' : ' ');// . "<uda_s={$votoId}>";
            }

            // Prepara i dati esattamente come richiesto da ClasseViva
            $postData = [
                'ope' => 'AJINS',
                'studente_id' => $gradeData['student_id'],
                'classe' => $gradeData['class_id'],
                'gruppo' => $gradeData['group_id'] ?? '',
                'valore' => $valore,
                'evento_codice' => 'VG',
                'valore_display' => $valoreDisplay,
                'descrizione_1' => $descrizione1,  // Pattern SN_X_Y: N=colonna periodo (1/2/3), X=tipo, Y=slot
                'data' => $dataEvento,
                'materia_id' => $gradeData['subject_id'],
                'materia_desc' => $gradeData['subject_name'],
                'causale_codice' => $causale['codice'],
                'causale_desc' => $causale['desc'],  // <ztx>Orale</ztx>
                'evento_id' => '0',
                'nota_1' => $nota1,  // Note con placeholder tracciabilità
                'nota_2' => $gradeData['notes_2'] ?? '',
                'no_voto' => '0',
                'id_uda' => $gradeData['uda_id'] ?? '',
                'peso' => $gradeData['weight'] ?? '100'
            ];

            $response = $this->client->post($url, [
                'form_params' => $postData,
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent' => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
                    'Accept' => 'application/json, text/javascript, */*',
                    'Accept-Language' => 'it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Origin' => 'https://web.spaggiari.eu',
                    'Referer' => 'https://web.spaggiari.eu/cvv/app/default/regvoti.php?classe_id=' . $gradeData['class_id']
                ],
                'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $bodyTrim = trim($body);

            // Se siamo stati rediretti alla pagina di login, la sessione è scaduta
            if (stripos($bodyTrim, '<!DOCTYPE') !== false) {
                throw new Exception(
                    "Sessione web ClasseViva scaduta. " .
                    "Accedi alla pagina Integrazioni e rigenera il token ClasseViva inserendo le tue credenziali."
                );
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $jsonResponse = json_decode($body, true);
                // Gli endpoint web rispondono spesso con "OK" (plaintext)
                if ($bodyTrim !== '' && strcasecmp($bodyTrim, 'OK') !== 0 && empty($jsonResponse)) {
                    throw new Exception("Risposta inattesa dal registro: {$bodyTrim}");
                }

                $result = [
                    'success' => true,
                    'http_code' => $statusCode,
                    'message' => 'Voto pubblicato con successo',
                    'response' => $jsonResponse ?: $bodyTrim,
                    'grade_type' => $gradeData['grade_type'],
                    'grade_value' => $valoreDisplay,
                    'student_id' => $gradeData['student_id']
                ];

                // Invia notifica email (se abilitata nel config)
                $this->sendGradeNotification($gradeData, $valoreDisplay);

                return $result;
            } else {
                throw new Exception("HTTP $statusCode - Body: $body");
            }

        } catch (GuzzleException $e) {
            throw new Exception("Errore pubblicazione voto: " . $e->getMessage());
        }
    }


    /**
     * Compatibilità interna: genera PHPSESSID dal token REST.
     *
     * @return void
     * @throws Exception Se login fallisce
     */
    private function authenticateWeb(): void
    {
        if (!$this->getPhpSessionToken()) {
            throw new Exception("PHPSESSID non trovato nella risposta ClasseViva");
        }
    }

    /**
     * Pubblica annotazione usando endpoint WEB (regclasse_io.php)
     * Questo è il metodo usato dal vecchio sistema plusminus e che FUNZIONA
     *
     * Parametri annotationData:
     *   - student_id: ID studente numerico (senza prefisso CLV-)
     *   - text: Testo annotazione
     *   - date: Data in formato Y-m-d
     *   - type: positive/negative/neutral (verrà convertito in colore hex)
     *   - visible_to_student: true/false (default: true)
     *
     * @param array $annotationData
     * @return array Risultato pubblicazione
     * @throws Exception Se pubblicazione fallisce
     */
    public function publishAnnotationWeb(array $annotationData): array
    {
        // Usa il PHPSESSID salvato nel token (non più credenziali)
        $this->ensureAuthenticated();
        $this->ensurePhpSession();

        // Valida dati obbligatori
        $required = ["student_id", "text", "date"];
        foreach ($required as $field) {
            if (!isset($annotationData[$field])) {
                throw new Exception("Campo obbligatorio mancante per annotazione web: {$field}");
            }
        }

        try {
            $url = "https://web.spaggiari.eu/cvv/app/default/regclasse_io.php";

            // Converti tipo annotazione in colore esadecimale
            $type = $annotationData["type"] ?? "positive";
            $customColor = $annotationData["color_hex"] ?? null;
            $colore = $customColor ?: match($type) {
                "positive" => "00FF00",
                "negative" => "FF0000",
                default => "FFFF00"
            };

            // Se indicato valore_pm (+/-) usa colori coerenti
            if (isset($annotationData['valore_pm'])) {
                $val = $annotationData['valore_pm'];
                if ($val === '+' || $val === 1 || $val === '1') $colore = '00FF00';
                if ($val === '-' || $val === -1 || $val === '-1') $colore = 'FF0000';
            }

            // Converti data da Y-m-d (ISO) a d-m-Y (formato italiano)
            $date = \DateTime::createFromFormat("Y-m-d", $annotationData["date"]);
            if (!$date) {
                throw new Exception("Formato data non valido: " . $annotationData["date"]);
            }
            $evento_data = $date->format("d-m-Y");

            // Prepara i dati esattamente come il vecchio sistema
            $postData = [
                "action" => "NOTA_PERSONALE",
                "new_nota" => $annotationData["text"],
                "studente_id" => $annotationData["student_id"],
                "crit_valore" => "10",
                "crit_colore" => $colore,
                "evento_data" => $evento_data,
                "pubblica" => ($annotationData["visible_to_student"] ?? true) ? "on" : "off",
                "gruppo_id" => "",
                "tutor" => "off"
            ];

            $response = $this->client->post($url, [
                "form_params" => $postData,
                "headers" => [
                    "Cookie" => "PHPSESSID=" . $this->phpSessionId,
                    "X-Requested-With" => "XMLHttpRequest",
                    "User-Agent" => "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36"
                ],
                "timeout" => 30
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                // Notifica email simil-voto
                $this->sendAnnotationNotification($annotationData);

                return [
                    "success" => true,
                    "http_code" => $statusCode,
                    "message" => "Annotazione pubblicata con successo"
                ];
            } else {
                throw new Exception("HTTP $statusCode - Body: $body");
            }

        } catch (GuzzleException $e) {
            throw new Exception("Errore pubblicazione annotazione web: " . $e->getMessage());
        }
    }


    /**
     * Recupera tutti i voti di uno studente per una specifica materia usando solo il token
     *
     * @param string $studentId ID studente ClasseViva
     * @param string $classId ID classe ClasseViva
     * @param string $subjectId ID materia ClasseViva
     * @return array Array con i voti divisi per tipo (orale, scritto, pratico)
     * @throws Exception Se il recupero fallisce
     */
    public function getStudentGrades(string $studentId, string $classId, string $subjectId, ?string $gradeDate = null): array
    {

        //$this->loadTemporaryTokenFromConstants();
        $this->ensureAuthenticated();
        $this->ensurePhpSession();


        try {
            // USA regvoti.php - mostra TUTTI gli studenti con dettagli completi
            $url = 'https://web.spaggiari.eu/cvv/app/default/regvoti.php';

            $response = $this->client->get($url, [
                'query' => [
                    'classe_id' => $classId,
                    'gruppo_id' => '',
                    'materia_id' => $subjectId
                ],
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'User-Agent' => 'Mozilla/5.0'
                ],
                'timeout' => 30
            ]);

            $html = (string) $response->getBody();

            $grades = [
                'orale' => [],
                'scritto' => [],
                'pratico' => []
            ];

            // Pattern per i 3 tipi di voto
            // Il prefisso (S1, S2, S3) indica la colonna del registro (periodo)
            $prefixBase = $gradeDate ? $this->resolveGradePrefix($gradeDate) : null;
            if ($prefixBase) {
                $prefixes = [$prefixBase];
            } else {
                // Se non c'è una data specifica, cerca in tutte le colonne
                // (2 periodi: S1, S3; 3 periodi: S1, S2, S3)
                $periodCount = (int)($this->fullConfig['classeviva']['period_count'] ?? 2);
                $prefixes = $periodCount === 3 ? ['S1', 'S2', 'S3'] : ['S1', 'S3'];
            }
            $typeMap = [];
            foreach ($prefixes as $prefix) {
                $typeMap[$prefix . '_1_'] = 'scritto';
                $typeMap[$prefix . '_2_'] = 'orale';
                $typeMap[$prefix . '_3_'] = 'pratico';
            }

            // Cerca tutti i voti dello studente
            foreach ($typeMap as $prefix => $type) {
                for ($slot = 1; $slot <= 5; $slot++) {
                    $pattern = $prefix . $slot;

                    // Cerca TD con studente_id e data_no_barre
                    if (preg_match(
                        '/<td[^>]*studente_id=["\']' . $studentId . '["\'][^>]*data_no_barre=["\']' . $pattern . '["\'][^>]*>/i',
                        $html,
                        $tdMatch
                    )) {
                        $tdTag = $tdMatch[0];

                        $gradeInfo = [
                            'slot' => $slot,
                            'type' => $type,
                            'description_code' => $pattern,
                            'value' => null,
                            'date' => null,
                            'notes' => '',
                            'evento_id' => null
                        ];

                        // Valore
                        if (preg_match('/voto_valore=["\'"]?([^"\'>\s]+)["\'"]?/i', $tdTag, $m)) {
                            $val = $m[1];
                            if (!empty($val) && $val != '0.000') {
                                $gradeInfo['value'] = $val;
                            }
                        }

                        if ($gradeInfo['value'] === null) {
                            continue;
                        }

                        // Data
                        if (preg_match('/mydata=["\'"]?(\d{2}-\d{2}-\d{4})["\'"]?/i', $tdTag, $m)) {
                            $dateParts = explode('-', $m[1]);
                            if (count($dateParts) === 3) {
                                $gradeInfo['date'] = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                            }
                        }

                        // Note
                        if (preg_match('/nota1=["\'"]?([^"\']*)["\'"]?/i', $tdTag, $m)) {
                            $gradeInfo['notes'] = urldecode($m[1]);
                        }

                        // Evento ID
                        if (preg_match('/evento_id=["\'"]?(\d+)["\'"]?/i', $tdTag, $m)) {
                            $gradeInfo['evento_id'] = $m[1];
                        }

                        $grades[$type][] = $gradeInfo;
                    }
                }
            }

            return $grades;

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero voti studente: " . $e->getMessage());
        }
    }

    public function calculateGradeAverage(array $grades, ?string $type = null): float|array
    {
        // Se specificato un tipo, calcola solo per quel tipo
        if ($type !== null) {
            if (!isset($grades[$type]) || empty($grades[$type])) {
                return 0.0;
            }

            $sum = 0;
            $count = 0;

            foreach ($grades[$type] as $grade) {
                $value = $grade['value'] ?? null;
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    $count++;
                }
            }

            return $count > 0 ? round($sum / $count, 2) : 0.0;
        }

        // Altrimenti calcola medie per tutti i tipi
        $averages = [];

        foreach (['orale', 'scritto', 'pratico'] as $gradeType) {
            $averages[$gradeType] = $this->calculateGradeAverage($grades, $gradeType);
        }

        // Media generale
        $allGrades = array_merge(
            $grades['orale'] ?? [],
            $grades['scritto'] ?? [],
            $grades['pratico'] ?? []
        );

        $sum = 0;
        $count = 0;

        foreach ($allGrades as $grade) {
            $value = $grade['value'] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $count++;
            }
        }

        $averages['generale'] = $count > 0 ? round($sum / $count, 2) : 0.0;

        return $averages;
    }

    /**
     * Cancella un voto dal registro elettronico
     *
     * Usa l'endpoint votiio.php con operazione AJDEL per cancellare un voto.
     *
     * @param array $gradeData Dati del voto da cancellare:
     *   - evento_id: ID evento del voto (obbligatorio)
     *   - student_id: ID studente (obbligatorio)
     *   - class_id: ID classe (obbligatorio)
     *   - subject_id: ID materia (obbligatorio)
     *   - description_code: Codice descrizione tipo S1_2_1 (opzionale, default: S1_2_1)
     * @return array Risultato cancellazione
     * @throws Exception Se cancellazione fallisce
     */
    public function deleteGrade(array $gradeData): array
    {
        // Usa il PHPSESSID salvato nel token (non più credenziali)
        $this->ensureAuthenticated();
        $this->ensurePhpSession();

        // Valida dati obbligatori
        $required = ['evento_id', 'student_id', 'class_id', 'subject_id'];
        foreach ($required as $field) {
            if (!isset($gradeData[$field])) {
                throw new Exception("Campo obbligatorio mancante per cancellazione: {$field}");
            }
        }

        try {
            $url = 'https://web.spaggiari.eu/cvv/app/default/votiio.php';

            // Prepara i dati esattamente come dal HAR
            $postData = [
                'ope' => 'AJDEL',
                'classe_id' => $gradeData['class_id'],
                'gruppo' => '',
                'materia_id' => $gradeData['subject_id'],
                'evento_codice' => 'VG',
                'descrizione_1' => $gradeData['description_code'] ?? 'S1_2_1',
                'evento_id' => $gradeData['evento_id'],
                'studente_id' => $gradeData['student_id']
            ];

            $response = $this->client->post($url, [
                'form_params' => $postData,
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent' => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36',
                    'Accept' => '*/*',
                    'Origin' => 'https://web.spaggiari.eu',
                    'Referer' => 'https://web.spaggiari.eu/cvv/app/default/regvoti.php?classe_id=' . $gradeData['class_id']
                ],
                'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode >= 200 && $statusCode < 300 && trim($body) === 'OK') {
                return [
                    'success' => true,
                    'http_code' => $statusCode,
                    'message' => 'Voto cancellato con successo',
                    'evento_id' => $gradeData['evento_id']
                ];
            } else {
                throw new Exception("HTTP $statusCode - Body: $body");
            }

        } catch (GuzzleException $e) {
            throw new Exception("Errore cancellazione voto: " . $e->getMessage());
        }
    }

    /**
     * Cancella un'annotazione (nota personale) dal registro ClasseViva
     *
     * @param array $annotationData Dati dell'annotazione da cancellare:
     *   - evento_id: ID evento dell'annotazione (obbligatorio)
     *   - student_id: ID studente (obbligatorio)
     *   - date: Data in formato Y-m-d (obbligatorio)
     *   - class_id: ID classe (opzionale, usato per gruppo_id)
     * @return array Risultato cancellazione
     * @throws Exception Se cancellazione fallisce
     */
    public function deleteAnnotation(array $annotationData): array
    {
        // Usa il PHPSESSID salvato nel token
        $this->ensureAuthenticated();
        $this->ensurePhpSession();

        // Valida dati obbligatori
        $required = ['evento_id', 'student_id', 'date'];
        foreach ($required as $field) {
            if (!isset($annotationData[$field])) {
                throw new Exception("Campo obbligatorio mancante per cancellazione annotazione: {$field}");
            }
        }

        try {
            $url = 'https://web.spaggiari.eu/cvv/app/default/regclasse_io.php';

            // Converti data da Y-m-d a d-m-Y
            $date = \DateTime::createFromFormat('Y-m-d', $annotationData['date']);
            if (!$date) {
                throw new Exception("Formato data non valido: {$annotationData['date']}. Usa Y-m-d");
            }
            $eventoData = $date->format('d-m-Y');

            // Prepara i parametri query come da HAR
            $queryParams = [
                'new_evento' => $annotationData['evento_id'],
                'action' => 'REMOVE_NOTA_PERSONALE',
                'studente_id' => $annotationData['student_id'],
                'evento_data' => $eventoData,
                'gruppo_id' => $annotationData['group_id'] ?? ''
            ];

            $response = $this->client->get($url, [
                'query' => $queryParams,
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent' => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36',
                    'Accept' => '*/*',
                    'Origin' => 'https://web.spaggiari.eu',
                    'Referer' => 'https://web.spaggiari.eu/cvv/app/default/gioprof_note.php?classe_id=' . ($annotationData['class_id'] ?? '') . '&gruppo_id='
                ],
                'timeout' => 30
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            // ClasseViva risponde con "OK" o stringa vuota in caso di successo
            if ($statusCode >= 200 && $statusCode < 300 && (trim($body) === 'OK' || trim($body) === '')) {
                return [
                    'success' => true,
                    'http_code' => $statusCode,
                    'message' => 'Annotazione cancellata con successo',
                    'evento_id' => $annotationData['evento_id']
                ];
            } else {
                throw new Exception("HTTP $statusCode - Body: $body");
            }

        } catch (GuzzleException $e) {
            throw new Exception("Errore cancellazione annotazione: " . $e->getMessage());
        }
    }

    /**
     * Recupera le annotazioni (note personali) di uno studente per una classe via web scraping
     * Questo metodo restituisce l'evento_id necessario per la cancellazione (a differenza dell'API REST)
     *
     * @param string $studentId ID studente ClasseViva
     * @param string $classId ID classe ClasseViva
     * @param string|null $date Filtra per data specifica (Y-m-d), null per tutte
     * @return array Lista di annotazioni con evento_id, text, date, color
     * @throws Exception Se il recupero fallisce
     */
    public function getStudentAnnotationsWeb(string $studentId, string $classId, ?string $date = null): array
    {
        $this->ensureAuthenticated();
        $this->ensurePhpSession();

        try {
            // La pagina gioprof_note.php mostra tutte le note della classe
            $url = 'https://web.spaggiari.eu/cvv/app/default/gioprof_note.php';

            $response = $this->client->get($url, [
                'query' => [
                    'classe_id' => $classId,
                    'gruppo_id' => ''
                ],
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                ],
                'timeout' => 30
            ]);

            $html = (string) $response->getBody();
            $annotations = [];
            $foundEventIds = [];

            // Pattern 1: Cerca link/URL con new_evento e studente_id (usato per delete)
            // Esempio: regclasse_io.php?new_evento=14341622&action=REMOVE_NOTA_PERSONALE&studente_id=8789608
            if (preg_match_all('/new_evento=(\d+)[^"\']*studente_id=' . preg_quote($studentId, '/') . '/i', $html, $matches)) {
                foreach ($matches[1] as $eventoId) {
                    if (!in_array($eventoId, $foundEventIds)) {
                        $foundEventIds[] = $eventoId;
                    }
                }
            }

            // Pattern 2: Cerca URL con evento_id e studente_id (usato per visualizzazione)
            // Esempio: regclasse_note_alunno.php?evento_id=14341622&nota_privata=1&studente_id=8789608
            if (preg_match_all('/evento_id=(\d+)[^"\']*studente_id=' . preg_quote($studentId, '/') . '/i', $html, $matches)) {
                foreach ($matches[1] as $eventoId) {
                    if (!in_array($eventoId, $foundEventIds)) {
                        $foundEventIds[] = $eventoId;
                    }
                }
            }

            // Pattern 3: Ordine inverso - studente_id prima di evento_id
            if (preg_match_all('/studente_id=' . preg_quote($studentId, '/') . '[^"\']*evento_id=(\d+)/i', $html, $matches)) {
                foreach ($matches[1] as $eventoId) {
                    if (!in_array($eventoId, $foundEventIds)) {
                        $foundEventIds[] = $eventoId;
                    }
                }
            }

            // Pattern 4: Cerca in attributi data- o onclick
            if (preg_match_all('/["\']' . preg_quote($studentId, '/') . '["\'][^>]*["\'](\d{8,})["\']|["\'](\d{8,})["\'][^>]*["\']' . preg_quote($studentId, '/') . '["\']/i', $html, $matches)) {
                foreach (array_merge($matches[1], $matches[2]) as $eventoId) {
                    if (!empty($eventoId) && strlen($eventoId) >= 8 && !in_array($eventoId, $foundEventIds)) {
                        $foundEventIds[] = $eventoId;
                    }
                }
            }

            // Pattern 5: Cerca qualsiasi numero lungo vicino allo studente_id (evento_id sono tipicamente 8 cifre)
            $studentPattern = preg_quote($studentId, '/');
            if (preg_match_all('/(?:' . $studentPattern . '.{0,100}?(\d{8,})|(\d{8,}).{0,100}?' . $studentPattern . ')/is', $html, $matches)) {
                foreach (array_merge($matches[1], $matches[2]) as $eventoId) {
                    if (!empty($eventoId) && strlen($eventoId) >= 8 && strlen($eventoId) <= 10 && !in_array($eventoId, $foundEventIds)) {
                        $foundEventIds[] = $eventoId;
                    }
                }
            }

            // Crea array di annotazioni dagli evento_id trovati
            foreach ($foundEventIds as $eventoId) {
                // Cerca la data associata a questo evento_id
                $annoDate = null;

                // Pattern per data nel formato dd-mm-yyyy
                if (preg_match('/evento_id=["\']?' . $eventoId . '["\']?[^"\']{0,50}?evento_data=["\']?(\d{2}-\d{2}-\d{4})["\']?/i', $html, $dateMatch)) {
                    $dateParts = explode('-', $dateMatch[1]);
                    if (count($dateParts) === 3) {
                        $annoDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0]; // Y-m-d
                    }
                }
                // Pattern per data nel formato yyyy-mm-dd
                elseif (preg_match('/evento_id=["\']?' . $eventoId . '["\']?[^"\']{0,50}?evento_data=["\']?(\d{4}-\d{2}-\d{2})["\']?/i', $html, $dateMatch)) {
                    $annoDate = $dateMatch[1];
                }

                // Filtra per data se specificata
                if ($date !== null && $annoDate !== null && $annoDate !== $date) {
                    continue;
                }

                $annotations[] = [
                    'evento_id' => $eventoId,
                    'student_id' => $studentId,
                    'text' => '',
                    'date' => $annoDate,
                    'color' => null
                ];
            }

            // Log per debug
            if (empty($annotations)) {
                error_log("getStudentAnnotationsWeb: Nessuna annotazione trovata per studente {$studentId} in classe {$classId}. HTML length: " . strlen($html));
            }

            return $annotations;

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero annotazioni studente: " . $e->getMessage());
        }
    }

    /**
     * Invia notifica email per voto pubblicato
     *
     * @param array $gradeData Dati del voto
     * @param string $valoreDisplay Valore del voto da mostrare
     * @return void
     */
    private function sendGradeNotification(array $gradeData, string $valoreDisplay): void
    {
        try {
            // Recupera informazioni studente
            $studentInfo = $this->getStudentInfo($gradeData['student_id'], $gradeData['class_id']);

            if (!$studentInfo) {
                error_log("NotificationManager: Studente non trovato per ID {$gradeData['student_id']}");
                return;
            }

            // Crea NotificationManager
            $notificationManager = new \App\Core\NotificationManager($this->fullConfig);

            // Prepara dati per email
            $emailNotes = $gradeData['notes_2'] ?? ($gradeData['notes'] ?? ($gradeData['description'] ?? ''));
            $emailData = array_merge(
                [
                    'subject_name' => 'Valutazione',
                    'grade_type'   => 'orale',
                    'date'         => date('Y-m-d'),
                    'notes'        => $emailNotes,
                ],
                $gradeData,
                [
                    'grade_value' => $valoreDisplay
                ]
            );

            // Invia notifica
            $notificationManager->sendGradePublishedNotification($emailData, $studentInfo);

        } catch (\Exception $e) {
            // Log errore ma non bloccare l'esecuzione
            error_log("Errore invio notifica email voto: " . $e->getMessage());
        }
    }

    /**
     * Recupera informazioni studente da ClasseViva
     *
     * @param string $studentId ID studente
     * @param string $classId ID classe
     * @return array|null Informazioni studente
     */
    private function getStudentInfo(string $studentId, string $classId): ?array
    {
        try {
            $students = $this->getStudentiClasse($classId);

            foreach ($students as $student) {
                if ($student['id'] == $studentId) {
                    return [
                        'id' => $student['id'],
                        'nome' => $student['nome'] ?? '',
                        'cognome' => $student['cognome'] ?? '',
                        'email' => $student['email'] ?? null,
                        'email_genitori' => $student['email_genitori'] ?? null
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            error_log("Errore recupero info studente: " . $e->getMessage());
            return null;
        }
    }

}

