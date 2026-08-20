<?php

namespace App\Integration;

use App\Core\GoogleTokenProvider;
use Google\Client;
use Google\Service\Classroom;
use Google\Service\Classroom\Course;
use Google\Service\Classroom\CourseWork;
use Google\Service\Classroom\CourseWorkMaterial;
use Google\Service\Classroom\Link;
use Google\Service\Classroom\Material;
use Exception;

/**
 * GoogleClassroomAPI - Integrazione con Google Classroom
 */
class GoogleClassroomAPI
{
    private Client $client;
    private Classroom $service;
    private array $config;
    /** Configurazione applicativa completa (include eventualmente google.oauth_token per utente) */
    private array $appConfig = [];
    private array $userProfileCache = [];

    public function __construct(array $config)
    {
        $this->appConfig = $config;
        $this->config = $config['google'] ?? [];
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $this->client = new Client();
        $this->client->setApplicationName('UDA System');
        $this->client->setScopes([
            Classroom::CLASSROOM_COURSES_READONLY,
            Classroom::CLASSROOM_COURSEWORK_ME,
            Classroom::CLASSROOM_COURSEWORK_STUDENTS,
            Classroom::CLASSROOM_COURSEWORKMATERIALS,
            Classroom::CLASSROOM_ROSTERS_READONLY,
            Classroom::CLASSROOM_TOPICS
        ]);
        $this->client->setAuthConfig(ROOT_PATH . '/' . $this->config['credentials_file']);
        $this->client->setAccessType('offline');

        // Carica il token OAuth specifico per l'utente attivo
        $tokenData = $this->appConfig['google']['oauth_token'] ?? null;
        if (empty($tokenData) || !is_array($tokenData)) {
            // Fallback robusto: recupera token utente direttamente dal provider.
            $tokenData = GoogleTokenProvider::getToken($this->appConfig);
            if (!empty($tokenData) && is_array($tokenData)) {
                $this->appConfig['google']['oauth_token'] = $tokenData;
            }
        }

        if (empty($tokenData) || !is_array($tokenData)) {
            throw new Exception(
                "Token Google Classroom non trovato per l'utente corrente. " .
                "Esegui la procedura di autenticazione da google_auth.php."
            );
        }

        $rawScopes = $tokenData['scope'] ?? '';
        $tokenScopes = is_array($rawScopes) ? $rawScopes : preg_split('/\s+/', trim((string)$rawScopes));
        $tokenScopes = array_values(array_filter(array_map('strval', $tokenScopes ?: [])));
        if (!empty($tokenScopes)) {
            $hasCoursesScope = in_array(Classroom::CLASSROOM_COURSES, $tokenScopes, true)
                || in_array(Classroom::CLASSROOM_COURSES_READONLY, $tokenScopes, true);
            $hasCourseworkScope = in_array(Classroom::CLASSROOM_COURSEWORK_ME, $tokenScopes, true)
                || in_array(Classroom::CLASSROOM_COURSEWORK_ME_READONLY, $tokenScopes, true)
                || in_array(Classroom::CLASSROOM_COURSEWORK_STUDENTS, $tokenScopes, true)
                || in_array(Classroom::CLASSROOM_COURSEWORK_STUDENTS_READONLY, $tokenScopes, true);

            if (!$hasCoursesScope || !$hasCourseworkScope) {
                throw new Exception(
                    "Il token Google non include tutti gli scope Classroom necessari " .
                    "(corsi/compiti). Riesegui l'autenticazione da google_auth.php."
                );
            }
        }

        $this->client->setAccessToken($tokenData);

        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
        }

        $this->service = new Classroom($this->client);
    }

    /**
     * Ritorna il client Google per uso esterno
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Ottiene tutti i topic di un corso
     */
    public function getTopics(string $courseId): array
    {
        $topics = [];
        $pageToken = null;

        do {
            $response = $this->service->courses_topics->listCoursesTopics($courseId, [
                'pageToken' => $pageToken
            ]);

            foreach ($response->getTopic() as $topic) {
                $topics[] = [
                    'id' => $topic->topicId,
                    'name' => $topic->name
                ];
            }

            $pageToken = $response->nextPageToken;
        } while ($pageToken);

        return $topics;
    }

    /**
     * Trova o crea un argomento (topic) in un corso
     * Se esiste già un topic con lo stesso nome, lo riutilizza
     */
    public function findOrCreateTopic(string $courseId, string $topicName): array
    {
        // Prima cerca se esiste già
        $existingTopics = $this->getTopics($courseId);

        foreach ($existingTopics as $topic) {
            if (strcasecmp($topic['name'], $topicName) === 0) {
                return $topic; // Topic trovato, riutilizza
            }
        }

        // Non esiste, crea nuovo
        return $this->createTopic($courseId, $topicName);
    }

    /**
     * Crea un argomento (topic) in un corso
     */
    public function createTopic(string $courseId, string $topicName): array
    {
        $topic = new \Google\Service\Classroom\Topic();
        $topic->setName($topicName);

        $result = $this->service->courses_topics->create($courseId, $topic);

        return [
            'id' => $result->topicId,
            'name' => $result->name
        ];
    }

    /**
     * Pubblica materiale in Classroom
     */
    public function createMaterial(string $courseId, array $materialData): array
    {
        // Validazione: CourseWorkMaterial richiede almeno un materiale allegato
        if (empty($materialData['materials']) && empty($materialData['link'])) {
            throw new Exception("Un CourseWorkMaterial richiede almeno un materiale. Fornire 'materials' (array) o 'link' in materialData.");
        }

        // Usa CourseWorkMaterial (non CourseMaterial)
        $courseWorkMaterial = new \Google\Service\Classroom\CourseWorkMaterial();
        $courseWorkMaterial->setTitle($materialData['title']);
        $courseWorkMaterial->setDescription($materialData['description'] ?? '');

        // Imposta stato (DRAFT o PUBLISHED)
        $state = $materialData['state'] ?? 'PUBLISHED';
        $courseWorkMaterial->setState($state);

        // Prepara array di materiali da allegare
        $materials = [];

        // Se è fornito un array 'materials', usalo
        if (!empty($materialData['materials']) && is_array($materialData['materials'])) {
            foreach ($materialData['materials'] as $matItem) {
                $material = new Material();

                // Distingui tra link e file Drive
                if (isset($matItem['type']) && $matItem['type'] === 'drive_file') {
                    // File Drive - usa DriveFile
                    $driveFile = new \Google\Service\Classroom\DriveFile();
                    $driveFile->setId($matItem['drive_file_id']);
                    $driveFile->setTitle($matItem['title'] ?? '');

                    $sharedDriveFile = new \Google\Service\Classroom\SharedDriveFile();
                    $sharedDriveFile->setDriveFile($driveFile);

                    $material->setDriveFile($sharedDriveFile);
                } else {
                    // Link generico
                    $link = new Link();
                    $link->setUrl($matItem['url']);
                    $material->setLink($link);
                }

                $materials[] = $material;
            }
        }
        // Altrimenti usa il vecchio formato 'link' per compatibilità
        else if (!empty($materialData['link'])) {
            $link = new Link();
            $link->setUrl($materialData['link']);
            $material = new Material();
            $material->setLink($link);
            $materials[] = $material;
        }

        $courseWorkMaterial->setMaterials($materials);

        // Imposta topic se specificato (supporta topicId e topic_id)
        if (isset($materialData['topicId'])) {
            $courseWorkMaterial->setTopicId($materialData['topicId']);
        } elseif (isset($materialData['topic_id'])) {
            $courseWorkMaterial->setTopicId($materialData['topic_id']);
        }

        $result = $this->service->courses_courseWorkMaterials->create($courseId, $courseWorkMaterial);

        // alternateLink non è valorizzato per le bozze: ricostruisci l'URL canonico
        // (gli ID nel path sono base64, non i valori numerici grezzi).
        $link = (string)($result->alternateLink ?? '');
        if ($link === '') {
            $b64 = static fn(string $id): string => rtrim(base64_encode($id), '=');
            $link = "https://classroom.google.com/c/" . $b64($courseId) . "/m/" . $b64((string)$result->id) . "/details";
        }

        return [
            'id' => $result->id,
            'title' => $result->title,
            'link' => $link
        ];
    }

    /**
     * Crea un compito (assignment)
     */
    public function createAssignment(string $courseId, array $assignmentData): array
    {
        $courseWork = new CourseWork();
        $courseWork->setTitle($assignmentData['title']);
        $courseWork->setDescription($assignmentData['description'] ?? '');
        $courseWork->setWorkType($assignmentData['workType'] ?? 'ASSIGNMENT');
        $courseWork->setState($assignmentData['state'] ?? 'DRAFT');

        // Imposta scadenza se presente (formato array con year/month/day)
        if (isset($assignmentData['dueDate'])) {
            $dueDate = new \Google\Service\Classroom\Date();
            $dueDate->setYear($assignmentData['dueDate']['year']);
            $dueDate->setMonth($assignmentData['dueDate']['month']);
            $dueDate->setDay($assignmentData['dueDate']['day']);
            $courseWork->setDueDate($dueDate);

            if (isset($assignmentData['dueTime'])) {
                $dueTime = new \Google\Service\Classroom\TimeOfDay();
                $dueTime->setHours($assignmentData['dueTime']['hours']);
                $dueTime->setMinutes($assignmentData['dueTime']['minutes']);
                $courseWork->setDueTime($dueTime);
            }
        }
        // Fallback: formato stringa 'due_date' (per compatibilità con vecchio codice)
        elseif (isset($assignmentData['due_date'])) {
            $dueDate = new \Google\Service\Classroom\Date();
            $dateTime = new \DateTime($assignmentData['due_date']);
            $dueDate->setYear((int)$dateTime->format('Y'));
            $dueDate->setMonth((int)$dateTime->format('m'));
            $dueDate->setDay((int)$dateTime->format('d'));

            $dueTime = new \Google\Service\Classroom\TimeOfDay();
            $dueTime->setHours(23);
            $dueTime->setMinutes(59);

            $courseWork->setDueDate($dueDate);
            $courseWork->setDueTime($dueTime);
        }

        // Punti massimi
        if (isset($assignmentData['maxPoints'])) {
            $courseWork->setMaxPoints($assignmentData['maxPoints']);
        } elseif (isset($assignmentData['max_points'])) {
            $courseWork->setMaxPoints($assignmentData['max_points']);
        }

        // Topic - supporta sia topicId che topic_id
        if (isset($assignmentData['topicId']) && !empty($assignmentData['topicId'])) {
            error_log("Setting topicId: " . $assignmentData['topicId']);
            $courseWork->setTopicId($assignmentData['topicId']);
        } elseif (isset($assignmentData['topic_id']) && !empty($assignmentData['topic_id'])) {
            error_log("Setting topic_id: " . $assignmentData['topic_id']);
            $courseWork->setTopicId($assignmentData['topic_id']);
        } else {
            error_log("WARNING: No topicId provided in assignmentData!");
        }

        // Materiali allegati (link o drive files)
        if (isset($assignmentData['materials']) && !empty($assignmentData['materials'])) {
            $materials = [];
            foreach ($assignmentData['materials'] as $materialData) {
                $material = new Material();

                // Se è una stringa, è un link URL
                if (is_string($materialData)) {
                    $link = new Link();
                    $link->setUrl($materialData);
                    $material->setLink($link);
                }
                // Se è un array con 'driveFile', è un file di Drive
                elseif (is_array($materialData) && isset($materialData['driveFile'])) {
                    $driveFile = new \Google_Service_Classroom_DriveFile();
                    $driveFile->setId($materialData['driveFile']['driveFile']['id']);
                    $driveFile->setTitle($materialData['driveFile']['driveFile']['title']);

                    $sharedDriveFile = new \Google_Service_Classroom_SharedDriveFile();
                    $sharedDriveFile->setDriveFile($driveFile);
                    $sharedDriveFile->setShareMode($materialData['driveFile']['shareMode'] ?? 'VIEW');

                    $material->setDriveFile($sharedDriveFile);
                }

                $materials[] = $material;
            }
            $courseWork->setMaterials($materials);
        }

        $result = $this->service->courses_courseWork->create($courseId, $courseWork);

        // alternateLink non è valorizzato per le bozze: ricostruisci l'URL canonico
        // (gli ID nel path sono base64, non i valori numerici grezzi).
        $link = (string)($result->alternateLink ?? '');
        if ($link === '') {
            $b64 = static fn(string $id): string => rtrim(base64_encode($id), '=');
            $link = "https://classroom.google.com/c/" . $b64($courseId) . "/a/" . $b64((string)$result->id) . "/details";
        }

        return [
            'id' => $result->id,
            'title' => $result->title,
            'link' => $link,
            'state' => $result->state
        ];
    }

    /**
     * Pubblica un compito (da DRAFT a PUBLISHED)
     */
    public function publishAssignment(string $courseId, string $assignmentId): bool
    {
        $courseWork = $this->service->courses_courseWork->get($courseId, $assignmentId);
        $courseWork->setState('PUBLISHED');

        $this->service->courses_courseWork->patch($courseId, $assignmentId, $courseWork, [
            'updateMask' => 'state'
        ]);

        return true;
    }

    /**
     * Recupera i corsi dell'insegnante
     */
    public function getCourses(?array $courseStates = null): array
    {
        $courses = [];
        $pageToken = null;

        do {
            $params = [
                'teacherId' => 'me',
                'pageToken' => $pageToken
            ];
            if (!empty($courseStates)) {
                $params['courseStates'] = array_values($courseStates);
            }

            $response = $this->service->courses->listCourses($params);

            foreach ($response->getCourses() as $course) {
                $courses[] = [
                    'id' => $course->id,
                    'name' => $course->name,
                    'section' => $course->section,
                    'room' => $course->room,
                    'course_state' => $course->courseState ?? null
                ];
            }

            $pageToken = $response->nextPageToken;
        } while ($pageToken);

        return $courses;
    }

    /**
     * Recupera i compiti importabili di un corso, normalizzati per i cataloghi UI.
     *
     * @return list<array<string,mixed>>
     */
    public function getCourseAssignments(string $courseId): array
    {
        $assignments = [];
        $pageToken = null;

        do {
            $params = [
                'orderBy' => 'updateTime desc',
                'courseWorkStates' => ['PUBLISHED', 'DRAFT'],
            ];
            if ($pageToken !== null && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->service->courses_courseWork->listCoursesCourseWork($courseId, $params);
            foreach ($response->getCourseWork() ?? [] as $courseWork) {
                $normalized = ClassroomAssignmentCatalog::normalize($courseWork);
                if (ClassroomAssignmentCatalog::isImportable($normalized)) {
                    $assignments[] = $normalized;
                }
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $assignments;
    }

    /**
     * Elenca gli ID dei Google Form collegati come materiale (form o link) di un
     * corso Classroom. Usato per evidenziare nel catalogo Drive i form già
     * pubblicati nella Classroom associata.
     *
     * @return list<string> ID univoci dei form
     */
    public function listFormIdsInCourse(string $courseId): array
    {
        $formIds = [];

        $extract = static function (string $url) use (&$formIds): void {
            $url = trim($url);
            if ($url === '' || !preg_match('#/forms/d/(?:e/)?([a-zA-Z0-9_-]+)#i', $url, $m)) {
                return;
            }
            $id = (string)($m[1] ?? '');
            if ($id !== '') {
                $formIds[$id] = true;
            }
        };

        $collect = static function (iterable $materials) use ($extract): void {
            foreach ($materials as $material) {
                if (!is_object($material)) {
                    continue;
                }
                // Form allegato come materiale "Form" (formUrl).
                $form = method_exists($material, 'getForm') ? $material->getForm() : null;
                if ($form && method_exists($form, 'getFormUrl')) {
                    $extract((string)($form->getFormUrl() ?? ''));
                }
                // Link che punta a un modulo.
                $link = method_exists($material, 'getLink') ? $material->getLink() : null;
                if ($link && method_exists($link, 'getUrl')) {
                    $extract((string)($link->getUrl() ?? ''));
                }
                // File Drive: un Google Form è anche un file Drive (alternateLink).
                $sharedDriveFile = method_exists($material, 'getDriveFile') ? $material->getDriveFile() : null;
                $driveFile = ($sharedDriveFile && method_exists($sharedDriveFile, 'getDriveFile'))
                    ? $sharedDriveFile->getDriveFile()
                    : null;
                if ($driveFile && method_exists($driveFile, 'getAlternateLink')) {
                    $extract((string)($driveFile->getAlternateLink() ?? ''));
                }
            }
        };

        $pageToken = null;
        do {
            $response = $this->service->courses_courseWork->listCoursesCourseWork($courseId, [
                'pageToken' => $pageToken,
                'courseWorkStates' => ['PUBLISHED', 'DRAFT'],
            ]);
            foreach ($response->getCourseWork() ?? [] as $courseWork) {
                $collect($courseWork->getMaterials() ?? []);
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        $pageToken = null;
        do {
            $response = $this->service->courses_courseWorkMaterials->listCoursesCourseWorkMaterials($courseId, [
                'pageToken' => $pageToken,
                'courseWorkMaterialStates' => ['PUBLISHED', 'DRAFT'],
            ]);
            foreach ($response->getCourseWorkMaterial() ?? [] as $courseWorkMaterial) {
                $collect($courseWorkMaterial->getMaterials() ?? []);
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return array_keys($formIds);
    }

    /**
     * Recupera metadati di un corso specifico
     */
    public function getCourse(string $courseId): array
    {
        $course = $this->service->courses->get($courseId);

        return [
            'id' => $course->id,
            'name' => $course->name,
            'section' => $course->section,
            'room' => $course->room,
            'course_state' => $course->courseState ?? null,
            'alternate_link' => $course->alternateLink ?? null
        ];
    }

    /**
     * Genera l'URL per accedere alla pagina "Lavori del corso" di Google Classroom
     *
     * Google Classroom usa il formato /w/{base64(courseId)}/t/all per la vista lavori.
     * Questo metodo converte l'ID numerico del corso nel formato URL corretto.
     *
     * @param string $courseId ID numerico del corso
     * @return string URL completo per la pagina lavori del corso
     */
    public static function getCourseWorksUrl(string $courseId): string
    {
        $encoded = base64_encode($courseId);
        return "https://classroom.google.com/w/{$encoded}/t/all";
    }

    /**
     * Genera l'URL per accedere alla home page di un corso Google Classroom
     *
     * @param string $courseId ID numerico del corso
     * @return string URL completo per la home del corso
     */
    public static function getCourseHomeUrl(string $courseId): string
    {
        $encoded = base64_encode($courseId);
        return "https://classroom.google.com/c/{$encoded}";
    }

    /**
     * Recupera gli studenti di un corso
     */
    public function getCourseStudents(string $courseId): array
    {
        $students = [];
        $pageToken = null;

        do {
            $response = $this->service->courses_students->listCoursesStudents($courseId, [
                'pageToken' => $pageToken
            ]);

            foreach ($response->getStudents() as $student) {
                $profile = $student->getProfile();
                $students[] = [
                    'id' => $student->userId,
                    'name' => $profile->name->fullName,
                    'email' => $profile->emailAddress
                ];
            }

            $pageToken = $response->nextPageToken;
        } while ($pageToken);

        return $students;
    }

    /**
     * Ottiene dettagli singolo studente (GDPR-compliant)
     *
     * NOTA: Questo metodo ritorna dati personali in real-time dalle API
     * MAI salvare questi dati nel database locale!
     *
     * @param string $courseId ID del corso Google Classroom
     * @param string $studentId ID studente Google
     * @return array Dati studente (id, name, email)
     * @throws Exception Se studente non trovato
     */
    public function getStudente(string $courseId, string $studentId): array
    {
        try {
            // Ottieni studente specifico dal corso
            $student = $this->service->courses_students->get($courseId, $studentId);

            $profile = $student->getProfile();

            return [
                'id' => $student->userId,
                'name' => $profile->name->fullName,
                'email' => $profile->emailAddress
            ];

        } catch (\Google\Service\Exception $e) {
            throw new Exception("Studente ID $studentId non trovato nel corso: " . $e->getMessage());
        }
    }

    /**
     * Aggiorna un assignment esistente
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @param array $updateData Dati da aggiornare
     * @return array Dati aggiornati
     */
    public function updateAssignment(string $courseId, string $assignmentId, array $updateData): array
    {
        $courseWork = $this->service->courses_courseWork->get($courseId, $assignmentId);

        $updateMask = [];

        if (isset($updateData['title'])) {
            $courseWork->setTitle($updateData['title']);
            $updateMask[] = 'title';
        }

        if (isset($updateData['description'])) {
            $courseWork->setDescription($updateData['description']);
            $updateMask[] = 'description';
        }

        if (isset($updateData['due_date'])) {
            $dueDate = new \Google\Service\Classroom\Date();
            $dateTime = new \DateTime($updateData['due_date']);
            $dueDate->setYear((int)$dateTime->format('Y'));
            $dueDate->setMonth((int)$dateTime->format('m'));
            $dueDate->setDay((int)$dateTime->format('d'));

            $dueTime = new \Google\Service\Classroom\TimeOfDay();
            if (isset($updateData['due_time'])) {
                $time = explode(':', $updateData['due_time']);
                $dueTime->setHours((int)$time[0]);
                $dueTime->setMinutes((int)$time[1]);
            } else {
                $dueTime->setHours(23);
                $dueTime->setMinutes(59);
            }

            $courseWork->setDueDate($dueDate);
            $courseWork->setDueTime($dueTime);
            $updateMask[] = 'dueDate';
            $updateMask[] = 'dueTime';
        }

        if (isset($updateData['max_points'])) {
            $courseWork->setMaxPoints($updateData['max_points']);
            $updateMask[] = 'maxPoints';
        }

        if (isset($updateData['state'])) {
            $courseWork->setState($updateData['state']);
            $updateMask[] = 'state';
        }

        $result = $this->service->courses_courseWork->patch($courseId, $assignmentId, $courseWork, [
            'updateMask' => implode(',', $updateMask)
        ]);

        return [
            'id' => $result->id,
            'title' => $result->title,
            'state' => $result->state,
            'link' => $result->alternateLink
        ];
    }

    /**
     * Recupera tutte le submissions (consegne) di un assignment
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @return array Array di submissions con voti
     */
    public function getAssignmentSubmissions(string $courseId, string $assignmentId): array
    {
        $submissions = [];
        $pageToken = null;

        do {
            $response = $this->service->courses_courseWork_studentSubmissions->listCoursesCourseWorkStudentSubmissions(
                $courseId,
                $assignmentId,
                ['pageToken' => $pageToken]
            );

            foreach ($response->getStudentSubmissions() as $submission) {
                $turnedIn = null;
                // Recupera eventuale history per stato TURNED_IN
                if (method_exists($submission, 'getSubmissionHistory')) {
                    $history = $submission->getSubmissionHistory();
                    if (is_iterable($history)) {
                        foreach ($history as $item) {
                            if (method_exists($item, 'getStateHistory')) {
                                $stateHistory = $item->getStateHistory();
                                if ($stateHistory && $stateHistory->getState() === 'TURNED_IN') {
                                    $turnedIn = $stateHistory->getStateTimestamp();
                                }
                            }
                        }
                    }
                }

                $submissions[] = [
                    'id' => $submission->id,
                    'user_id' => $submission->userId,
                    'state' => $submission->state,
                    'assigned_grade' => $submission->assignedGrade ?? null,
                    'draft_grade' => $submission->draftGrade ?? null,
                    'created_time' => $submission->creationTime,
                    'updated_time' => $submission->updateTime,
                    'turned_in_time' => $turnedIn,
                    'late' => $submission->late ?? false
                ];
            }

            $pageToken = $response->nextPageToken;
        } while ($pageToken);

        return $submissions;
    }

    /**
     * Recupera i commenti di una submission
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @param string $submissionId ID della submission
     * @return array Array di commenti
     */
    public function getSubmissionComments(string $courseId, string $assignmentId, string $submissionId): array
    {
        $comments = [];
        $this->lastCommentsDebug = [];

        try {
            $commentsService = $this->service->courses_courseWork_studentSubmissions_comments ?? null;
        } catch (\Throwable $e) {
            $commentsService = null;
        }

        if (!$commentsService) {
            return $this->fetchSubmissionCommentsRaw($courseId, $assignmentId, $submissionId);
        }

        $pageToken = null;
        do {
            try {
                $response = $commentsService->listCoursesCourseWorkStudentSubmissionsComments(
                    $courseId,
                    $assignmentId,
                    $submissionId,
                    ['pageToken' => $pageToken]
                );
            } catch (Exception $e) {
                $this->lastCommentsDebug[] = [
                    'path' => 'api client comments',
                    'status' => null,
                    'error' => $e->getMessage()
                ];
                return $this->fetchSubmissionCommentsRaw($courseId, $assignmentId, $submissionId);
            }

            $commentItems = $response->getComments() ?? [];
            foreach ($commentItems as $comment) {
                $state = method_exists($comment, 'getState') ? $comment->getState() : null;
                if ($state && $state !== 'PUBLISHED') {
                    continue;
                }

                $comments[] = [
                    'id' => $comment->getId(),
                    'author_user_id' => $comment->getAuthorUserId(),
                    'text' => $comment->getText(),
                    'create_time' => $comment->getCreateTime(),
                    'update_time' => $comment->getUpdateTime(),
                    'state' => $state
                ];
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $comments;
    }

    /**
     * Fallback raw call per commenti submission (compatibilita' libreria).
     */
    private function fetchSubmissionCommentsRaw(string $courseId, string $assignmentId, string $submissionId): array
    {
        $comments = [];
        $http = null;

        try {
            $http = $this->client->authorize();
        } catch (\Throwable $e) {
            return $comments;
        }

        if (!$http) {
            return $comments;
        }

        $base = 'https://classroom.googleapis.com/v1';
        $paths = [
            "/courses/{$courseId}/courseWork/{$assignmentId}/studentSubmissions/{$submissionId}/comments",
            "/courses/{$courseId}/courseWork/{$assignmentId}/studentSubmissions/{$submissionId}/privateComments"
        ];

        foreach ($paths as $path) {
            $pageToken = null;
            $collected = [];

            do {
                $query = ['pageSize' => 100];
                if ($pageToken) {
                    $query['pageToken'] = $pageToken;
                }

                try {
                    $response = $http->request('GET', $base . $path, [
                        'query' => $query,
                        'http_errors' => false,
                        'headers' => ['Accept' => 'application/json']
                    ]);
                } catch (\Throwable $e) {
                    $this->lastCommentsDebug[] = [
                        'path' => $path,
                        'status' => null,
                        'error' => $e->getMessage()
                    ];
                    break;
                }

                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                $this->lastCommentsDebug[] = [
                    'path' => $path,
                    'status' => $status,
                    'body' => substr($body, 0, 500)
                ];

                if ($status === 404) {
                    break;
                }

                if ($status < 200 || $status >= 300) {
                    break;
                }

                $data = json_decode($body, true);
                if (!is_array($data)) {
                    break;
                }

                $items = $data['comments'] ?? $data['privateComments'] ?? [];
                foreach ($items as $item) {
                    $collected[] = $this->normalizeCommentItem($item);
                }

                $pageToken = $data['nextPageToken'] ?? null;
            } while ($pageToken);

            if (!empty($collected)) {
                $comments = array_values(array_filter($collected, static fn($c) => !empty($c['text'] ?? '')));
                break;
            }
        }

        return $comments;
    }

    public function getLastCommentsDebug(): array
    {
        return $this->lastCommentsDebug;
    }

    private function normalizeCommentItem(array $item): array
    {
        $text = $item['text'] ?? null;
        if (!$text && isset($item['comment']['text'])) {
            $text = $item['comment']['text'];
        }

        $authorId = $item['authorUserId'] ?? ($item['author']['userId'] ?? null);
        $createTime = $item['createTime'] ?? ($item['creationTime'] ?? null);
        $updateTime = $item['updateTime'] ?? ($item['updateTime'] ?? null);
        $state = $item['state'] ?? null;

        return [
            'id' => $item['id'] ?? null,
            'author_user_id' => $authorId,
            'text' => $text,
            'create_time' => $createTime,
            'update_time' => $updateTime,
            'state' => $state
        ];
    }

    /**
     * Recupera il profilo utente (nome + email) con cache locale
     */
    public function getUserProfile(string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        if (array_key_exists($userId, $this->userProfileCache)) {
            return $this->userProfileCache[$userId];
        }

        try {
            $profilesService = $this->service->userProfiles ?? null;
            if (!$profilesService) {
                $this->userProfileCache[$userId] = null;
                return null;
            }

            $profile = $profilesService->get($userId);
        } catch (Exception $e) {
            $this->userProfileCache[$userId] = null;
            return null;
        }

        $nameObj = $profile->getName();
        $fullName = $nameObj ? $nameObj->getFullName() : null;
        $data = [
            'id' => $profile->getId(),
            'name' => $fullName,
            'email' => $profile->getEmailAddress()
        ];

        $this->userProfileCache[$userId] = $data;
        return $data;
    }

    /**
     * Recupera i dettagli di un singolo assignment
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @return array Dettagli dell'assignment
     */
    public function getAssignment(string $courseId, string $assignmentId): array
    {
        $courseWork = $this->service->courses_courseWork->get($courseId, $assignmentId);

        $dueDateTime = null;
        if ($courseWork->dueDate) {
            $dueDateTime = sprintf(
                '%04d-%02d-%02d',
                $courseWork->dueDate->year,
                $courseWork->dueDate->month,
                $courseWork->dueDate->day
            );

            if ($courseWork->dueTime) {
                $dueDateTime .= sprintf(
                    ' %02d:%02d',
                    $courseWork->dueTime->hours ?? 23,
                    $courseWork->dueTime->minutes ?? 59
                );
            }
        }

        return [
            'id' => $courseWork->id,
            'title' => $courseWork->title,
            'description' => $courseWork->description,
            'state' => $courseWork->state,
            'max_points' => $courseWork->maxPoints,
            'due_date' => $dueDateTime,
            'topic_id' => $courseWork->topicId,
            'link' => $courseWork->alternateLink,
            'creation_time' => $courseWork->creationTime,
            'update_time' => $courseWork->updateTime
        ];
    }

    /**
     * Carica un file su Google Drive e lo allega a un assignment
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @param string $filePath Path locale del file
     * @param string $fileName Nome del file
     * @return array Dati del file caricato
     */
    public function attachFileToDrive(string $filePath, string $folderId = null): array
    {
        // Usa Google Drive API per caricare il file
        $driveService = new \Google\Service\Drive($this->client);

        $fileMetadata = new \Google\Service\Drive\DriveFile();
        $fileMetadata->setName(basename($filePath));

        if ($folderId) {
            $fileMetadata->setParents([$folderId]);
        }

        $content = file_get_contents($filePath);
        $mimeType = mime_content_type($filePath);

        $file = $driveService->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id,name,mimeType,webViewLink'
        ]);

        // Rendi il file accessibile a chiunque abbia il link
        $permission = new \Google\Service\Drive\Permission();
        $permission->setType('anyone');
        $permission->setRole('reader');
        $driveService->permissions->create($file->id, $permission);

        return [
            'id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mimeType,
            'link' => $file->webViewLink
        ];
    }

    /**
     * Elimina un topic da un corso
     *
     * @param string $courseId ID del corso
     * @param string $topicId ID del topic
     * @return bool True se eliminato con successo
     */
    public function deleteTopic(string $courseId, string $topicId): bool
    {
        try {
            $this->service->courses_topics->delete($courseId, $topicId);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Errore eliminazione topic: " . $e->getMessage());
        }
    }

    /**
     * Elimina un materiale da un corso
     *
     * @param string $courseId ID del corso
     * @param string $materialId ID del materiale
     * @return bool True se eliminato con successo
     */
    public function deleteMaterial(string $courseId, string $materialId): bool
    {
        try {
            $this->service->courses_courseWorkMaterials->delete($courseId, $materialId);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Errore eliminazione materiale: " . $e->getMessage());
        }
    }

    /**
     * Elimina un assignment da un corso
     *
     * @param string $courseId ID del corso
     * @param string $assignmentId ID dell'assignment
     * @return bool True se eliminato con successo
     */
    public function deleteAssignment(string $courseId, string $assignmentId): bool
    {
        try {
            $this->service->courses_courseWork->delete($courseId, $assignmentId);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Errore eliminazione assignment: " . $e->getMessage());
        }
    }
}
