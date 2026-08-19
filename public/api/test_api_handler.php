<?php
/**
 * Handler AJAX per i test delle integrazioni API
 *
 * Gestisce:
 * - Esecuzione test (run_test)
 * - Cleanup risorse create (cleanup)
 * - Caricamento dati per dropdown (load_data)
 */

error_reporting(E_ALL);
ob_start();

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

$config = require_once __DIR__ . '/../../bootstrap.php';

use App\Integration\GoogleDriveAPI;
use App\Integration\GoogleClassroomAPI;
use App\Integration\GoogleFormsBuilder;
use App\Integration\ClasseVivaAPI;
use App\Core\GoogleTokenProvider;
use Google\Client;
use Google\Service\Forms;
use Google\Service\Drive;

header('Content-Type: application/json');

function jsonResponse($success, $message = '', $data = null, $error = null) {
    $response = ['success' => $success];
    if ($message) $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    if ($error) $response['error'] = $error;

    ob_end_clean();
    echo json_encode($response);
    exit;
}

try {
    $action = $_POST['action'] ?? '';

    if ($action === 'load_data') {
        handleLoadData($config);
    } elseif ($action === 'run_test') {
        handleRunTest($config);
    } elseif ($action === 'cleanup') {
        handleCleanup($config);
    } else {
        jsonResponse(false, '', null, 'Azione non valida');
    }
} catch (Exception $e) {
    jsonResponse(false, '', null, $e->getMessage());
}

/**
 * Carica dati per i dropdown della pagina
 */
function handleLoadData($config) {
    $type = $_POST['type'] ?? '';

    switch ($type) {
        case 'drive_folders':
            $driveApi = new GoogleDriveAPI($config);
            $rootFolder = $config['google']['drive']['root_folder_id'] ?? '';

            // Lista cartelle nel root
            $folders = [];
            if ($rootFolder) {
                $files = $driveApi->listFiles($rootFolder, 'mimeType = "application/vnd.google-apps.folder"');
                foreach ($files as $f) {
                    $folders[] = [
                        'id' => $f['id'],
                        'name' => $f['name']
                    ];
                }
            }
            jsonResponse(true, '', $folders);
            break;

        case 'classroom_courses':
            $classroomApi = new GoogleClassroomAPI($config);
            $courses = $classroomApi->getCourses();
            $data = [];
            foreach ($courses as $c) {
                $data[] = [
                    'id' => $c['id'],
                    'name' => $c['name'] ?? $c['id']
                ];
            }
            jsonResponse(true, '', $data);
            break;

        case 'cv_classes':
            $cvApi = new ClasseVivaAPI($config);
            $classes = $cvApi->getClasses();
            $data = [];
            foreach ($classes as $c) {
                $students = [];
                foreach ($c['students'] ?? [] as $s) {
                    $students[] = [
                        'id' => $s['studentId'] ?? $s['id'] ?? '',
                        'name' => trim(($s['firstName'] ?? '') . ' ' . ($s['lastName'] ?? ''))
                    ];
                }

                // Includi anche le materie per questa classe
                $subjects = [];
                foreach ($c['subjects'] ?? [] as $s) {
                    $subjects[] = [
                        'id' => $s['subjectId'] ?? $s['id'] ?? '',
                        'name' => $s['subjectDesc'] ?? $s['subjectName'] ?? $s['name'] ?? ''
                    ];
                }

                $data[] = [
                    'id' => $c['classId'] ?? $c['id'] ?? '',
                    'name' => $c['className'] ?? $c['name'] ?? '',
                    'students' => $students,
                    'subjects' => $subjects
                ];
            }
            jsonResponse(true, '', $data);
            break;

        default:
            jsonResponse(false, '', null, 'Tipo dati non valido');
    }
}

/**
 * Esegue un test specifico
 */
function handleRunTest($config) {
    $platform = $_POST['platform'] ?? '';
    $test = $_POST['test'] ?? '';

    switch ($platform) {
        case 'drive':
            runDriveTest($config, $test);
            break;
        case 'classroom':
            runClassroomTest($config, $test);
            break;
        case 'forms':
            runFormsTest($config, $test);
            break;
        case 'classeviva':
            runClasseVivaTest($config, $test);
            break;
        default:
            jsonResponse(false, '', null, 'Piattaforma non valida');
    }
}

/**
 * Test Google Drive
 */
function runDriveTest($config, $test) {
    $driveApi = new GoogleDriveAPI($config);
    $folderId = $_POST['folder_id'] ?? $config['google']['drive']['root_folder_id'] ?? '';

    switch ($test) {
        case 'list':
            $files = $driveApi->listFiles($folderId);
            $count = count($files);
            jsonResponse(true, "Trovati {$count} file/cartelle", [
                'count' => $count,
                'files' => array_slice($files, 0, 10) // Max 10 per risposta
            ]);
            break;

        case 'upload':
            // Crea un file di test temporaneo
            $tempFile = tempnam(sys_get_temp_dir(), 'test_api_');
            $testContent = "Test API Upload - " . date('Y-m-d H:i:s') . "\n";
            $testContent .= "Questo file e' stato creato automaticamente per testare l'integrazione con Google Drive.\n";
            $testContent .= "Puoi eliminarlo in sicurezza.\n";
            file_put_contents($tempFile, $testContent);

            $fileName = 'TEST_API_' . date('Ymd_His') . '.txt';

            // Usa la stessa chiamata di ajax_upload_drive_file.php (linee 31-36)
            $result = $driveApi->uploadFile(
                $tempFile,
                $fileName,
                $folderId ?: null,
                'text/plain'
            );

            unlink($tempFile);

            $viewUrl = $result['viewLink'] ?? '';
            $fileId = $result['id'] ?? '';

            jsonResponse(true, "File caricato: {$fileName}", [
                'resourceId' => $fileId,
                'resourceName' => $fileName,
                'verifyUrl' => $viewUrl ?: "https://drive.google.com/file/d/{$fileId}/view"
            ]);
            break;

        case 'folder':
            $folderName = 'TEST_API_FOLDER_' . date('Ymd_His');

            // Usa createFolder come in GoogleDriveAPI.php
            $result = $driveApi->createFolder($folderName, $folderId ?: null);

            $folderId = $result['id'] ?? '';
            $viewUrl = $result['webViewLink'] ?? "https://drive.google.com/drive/folders/{$folderId}";

            jsonResponse(true, "Cartella creata: {$folderName}", [
                'resourceId' => $folderId,
                'resourceName' => $folderName,
                'verifyUrl' => $viewUrl
            ]);
            break;

        default:
            jsonResponse(false, '', null, 'Test Drive non valido');
    }
}

/**
 * Test Google Classroom
 */
function runClassroomTest($config, $test) {
    $classroomApi = new GoogleClassroomAPI($config);
    $courseId = $_POST['course_id'] ?? '';

    switch ($test) {
        case 'courses':
            $courses = $classroomApi->getCourses();
            $count = count($courses);
            jsonResponse(true, "Trovati {$count} corsi", ['count' => $count]);
            break;

        case 'topic':
            if (empty($courseId)) {
                jsonResponse(false, '', null, 'Seleziona un corso prima di eseguire il test');
            }

            $topicName = 'TEST_API_TOPIC_' . date('Ymd_His');

            // Usa la stessa chiamata di uda_publish.php (linee 272-276)
            $result = $classroomApi->findOrCreateTopic($courseId, $topicName);

            $topicId = $result['id'] ?? $result['topicId'] ?? '';

            jsonResponse(true, "Argomento creato: {$topicName}", [
                'resourceId' => $topicId,
                'resourceName' => $topicName,
                'verifyUrl' => GoogleClassroomAPI::getCourseWorksUrl($courseId)
            ]);
            break;

        case 'material':
            if (empty($courseId)) {
                jsonResponse(false, '', null, 'Seleziona un corso prima di eseguire il test');
            }

            // Usa la stessa struttura di uda_publish.php (linee 335-344)
            $materialData = [
                'title' => 'TEST_API_MATERIAL_' . date('Ymd_His'),
                'description' => 'Materiale di test creato automaticamente per verificare l\'integrazione API. Puoi eliminarlo in sicurezza.',
                'materials' => [
                    ['url' => 'https://example.com/test-api-material']
                ],
                'state' => 'DRAFT'
            ];

            $result = $classroomApi->createMaterial($courseId, $materialData);

            $materialId = $result['id'] ?? '';

            jsonResponse(true, "Materiale creato (BOZZA): {$materialData['title']}", [
                'resourceId' => $materialId,
                'resourceName' => $materialData['title'],
                'verifyUrl' => GoogleClassroomAPI::getCourseWorksUrl($courseId)
            ]);
            break;

        case 'assignment':
            if (empty($courseId)) {
                jsonResponse(false, '', null, 'Seleziona un corso prima di eseguire il test');
            }

            // Usa la stessa struttura di uda_publish.php (linee 408-419)
            $assignmentData = [
                'title' => 'TEST_API_ASSIGNMENT_' . date('Ymd_His'),
                'description' => 'Compito di test creato automaticamente per verificare l\'integrazione API. Puoi eliminarlo in sicurezza.',
                'workType' => 'ASSIGNMENT',
                'state' => 'DRAFT',
                'maxPoints' => 10
            ];

            $result = $classroomApi->createAssignment($courseId, $assignmentData);

            $assignmentId = $result['id'] ?? '';

            jsonResponse(true, "Compito creato (BOZZA): {$assignmentData['title']}", [
                'resourceId' => $assignmentId,
                'resourceName' => $assignmentData['title'],
                'verifyUrl' => GoogleClassroomAPI::getCourseWorksUrl($courseId)
            ]);
            break;

        case 'students':
            if (empty($courseId)) {
                jsonResponse(false, '', null, 'Seleziona un corso prima di eseguire il test');
            }

            $students = $classroomApi->getCourseStudents($courseId);
            $count = count($students);
            jsonResponse(true, "Trovati {$count} studenti nel corso", ['count' => $count]);
            break;

        default:
            jsonResponse(false, '', null, 'Test Classroom non valido');
    }
}

/**
 * Test Google Forms
 */
function runFormsTest($config, $test) {
    switch ($test) {
        case 'create':
            // Crea un form di test con una domanda a scelta multipla
            $formsBuilder = new GoogleFormsBuilder($config);

            $domande = [
                [
                    'testo' => 'Domanda di test API - Qual e\' la capitale dell\'Italia?',
                    'tipo' => 'multipla',
                    'opzioni' => ['Roma', 'Milano', 'Napoli', 'Torino'],
                    'risposta_corretta' => 'Roma',
                    'punti' => 1
                ]
            ];

            $titolo = 'TEST_API_FORM_' . date('Ymd_His');
            $descrizione = 'Form di test creato automaticamente per verificare l\'integrazione API. Puoi eliminarlo in sicurezza.';

            $rootFolderId = $config['google']['drive']['root_folder_id'] ?? null;

            $result = $formsBuilder->createForm($domande, $titolo, $descrizione, $rootFolderId);

            $formId = $result['formId'] ?? '';
            $editUrl = $result['editUrl'] ?? '';
            $responderUrl = $result['responderUrl'] ?? '';

            jsonResponse(true, "Form creato: {$titolo}", [
                'resourceId' => $formId,
                'resourceName' => $titolo,
                'verifyUrl' => $editUrl ?: "https://docs.google.com/forms/d/{$formId}/edit",
                'responderUrl' => $responderUrl,
                'templateCopyError' => $result['templateCopyError'] ?? null,
                'usedTemplate' => $result['usedTemplate'] ?? false,
            ]);
            break;

        case 'responses':
            $formId = $_POST['form_id'] ?? '';

            if (empty($formId)) {
                jsonResponse(false, '', null, 'Form ID mancante. Crea prima un form di test.');
            }

            // Recupera le risposte dal form
            $client = new Client();
            $client->setApplicationName('UDA System');
            $client->setScopes([Forms::FORMS_RESPONSES_READONLY, Forms::FORMS_BODY_READONLY]);
            $client->setAuthConfig(ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json'));

            $tokenData = GoogleTokenProvider::getToken($config);
            if (empty($tokenData)) {
                jsonResponse(false, '', null, 'Token Google mancante');
            }
            $client->setAccessToken($tokenData);

            $formsService = new Forms($client);

            // Recupera info form
            $form = $formsService->forms->get($formId);
            $formTitle = $form->getInfo()->getTitle();
            $items = $form->getItems() ?? [];
            $numQuestions = count($items);

            // Recupera risposte
            $responsesObj = $formsService->forms_responses->listFormsResponses($formId);
            $responses = $responsesObj->getResponses() ?? [];
            $numResponses = count($responses);

            // Prepara riepilogo risposte
            $responseSummary = [];
            foreach ($responses as $response) {
                $email = $response->getRespondentEmail() ?? 'Anonimo';
                $answers = $response->getAnswers() ?? [];
                $totalScore = $response->getTotalScore() ?? 0;

                $responseSummary[] = [
                    'email' => $email,
                    'answers' => count($answers),
                    'score' => $totalScore,
                    'timestamp' => $response->getLastSubmittedTime() ?? $response->getCreateTime()
                ];
            }

            jsonResponse(true, "Form: {$formTitle} - {$numResponses} risposte, {$numQuestions} domande", [
                'formTitle' => $formTitle,
                'numQuestions' => $numQuestions,
                'numResponses' => $numResponses,
                'responses' => $responseSummary,
                'verifyUrl' => "https://docs.google.com/forms/d/{$formId}/edit#responses"
            ]);
            break;

        default:
            jsonResponse(false, '', null, 'Test Forms non valido');
    }
}

/**
 * Test ClasseViva
 */
function runClasseVivaTest($config, $test) {
    $cvApi = new ClasseVivaAPI($config);

    $classId = $_POST['class_id'] ?? '';
    $studentId = $_POST['student_id'] ?? '';
    $subjectId = $_POST['subject_id'] ?? '';
    $subjectName = $_POST['subject_name'] ?? '';

    switch ($test) {
        case 'login':
            // Valida il token come in test_plusminus_web.php (linee 51-57)
            $cvApi->validateToken(true);
            jsonResponse(true, "Token ClasseViva valido e sessione attiva");
            break;

        case 'classes':
            $classes = $cvApi->getClasses();
            $count = count($classes);
            $totalStudents = 0;
            foreach ($classes as $c) {
                $totalStudents += count($c['students'] ?? []);
            }
            jsonResponse(true, "Trovate {$count} classi con {$totalStudents} studenti totali", [
                'count' => $count,
                'students' => $totalStudents
            ]);
            break;

        case 'grade':
            if (empty($studentId) || empty($subjectId)) {
                jsonResponse(false, '', null, 'Seleziona classe, studente e materia prima di eseguire il test');
            }

            $gradeType = $_POST['grade_type'] ?? 'orale';
            $gradeValue = $_POST['grade_value'] ?? '7';
            $testTimestamp = date('Ymd_His');
            $testMarker = "TEST_API_{$testTimestamp}";

            // Usa la stessa struttura di ClasseVivaAPI::publishGrade (linee 1038-1266)
            // Aggiungiamo notes (commento 1) e notes_2 (commento 2 - visibile alle famiglie)
            $gradeData = [
                'student_id' => $studentId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'grade_type' => $gradeType,
                'grade_value' => $gradeValue,
                'date' => date('Y-m-d'),
                'notes' => "{$testMarker} - VOTO DI TEST API - DA CANCELLARE",
                'notes_2' => "{$testMarker} - Voto inserito per test integrazione API. Sara cancellato automaticamente."
            ];

            $result = $cvApi->publishGrade($gradeData);

            // Dopo la pubblicazione, recupera l'evento_id per permettere il cleanup
            $eventoId = null;
            $descriptionCode = null;
            try {
                $grades = $cvApi->getStudentGrades($studentId, $classId, $subjectId, $gradeData['date']);
                // Cerca il voto appena inserito (stesso tipo, valore e data)
                $typeKey = $gradeType; // orale, scritto, pratico
                if (!empty($grades[$typeKey])) {
                    foreach ($grades[$typeKey] as $grade) {
                        // Cerca il voto con la nota che contiene il nostro marker
                        if (!empty($grade['notes']) && strpos($grade['notes'], $testMarker) !== false) {
                            $eventoId = $grade['evento_id'];
                            $descriptionCode = $grade['description_code'];
                            break;
                        }
                        // Fallback: cerca per valore e data recente
                        if ($eventoId === null && $grade['date'] === $gradeData['date']) {
                            $gradeVal = str_replace(',', '.', $grade['value'] ?? '');
                            $expectedVal = str_replace(',', '.', $gradeValue);
                            if (abs((float)$gradeVal - (float)$expectedVal) < 0.01) {
                                $eventoId = $grade['evento_id'];
                                $descriptionCode = $grade['description_code'];
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Se non riusciamo a recuperare l'evento_id, il cleanup sarà manuale
                error_log("Test API: impossibile recuperare evento_id per cleanup: " . $e->getMessage());
            }

            $canCleanup = !empty($eventoId);
            $cleanupMsg = $canCleanup ? ' (cancellazione automatica disponibile)' : ' (cancellazione manuale richiesta)';
            jsonResponse(true, "Voto {$gradeValue} ({$gradeType}) inserito con successo{$cleanupMsg}", [
                'resourceId' => $eventoId ?? '',
                'resourceName' => "Voto {$gradeValue} {$gradeType}",
                'verifyUrl' => "https://web.spaggiari.eu/cvv/app/default/regvoti.php?classe_id={$classId}&gruppo_id=&materia_id={$subjectId}",
                'gradeData' => $gradeData,
                'evento_id' => $eventoId,
                'description_code' => $descriptionCode,
                'canCleanup' => $canCleanup
            ]);
            break;

        case 'annotation':
            if (empty($studentId) || empty($classId)) {
                jsonResponse(false, '', null, 'Seleziona classe e studente prima di eseguire il test');
            }

            $annotationType = $_POST['annotation_type'] ?? 'positive';
            $testTimestamp = date('Ymd_His');
            $testMarker = "TEST_API_{$testTimestamp}";

            // Usa la stessa struttura di pubblica_plusminus.php (linee 175-237)
            // e test_plusminus_web.php (linee 95-101)
            $annotationData = [
                'student_id' => $studentId,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'text' => "{$testMarker} - ANNOTAZIONE DI TEST API - DA CANCELLARE",
                'date' => date('Y-m-d'),
                'type' => $annotationType,
                'visible_to_student' => true
            ];

            // Usa publishAnnotationWeb come in pubblica_plusminus.php
            $result = $cvApi->publishAnnotationWeb($annotationData);

            // Dopo la pubblicazione, recupera l'evento_id per permettere il cleanup
            $eventoId = null;
            $annotationsFound = 0;
            try {
                // Breve pausa per assicurare che l'annotazione sia stata salvata
                usleep(800000); // 0.8 secondi

                // Prima prova: cerca con filtro data
                $annotations = $cvApi->getStudentAnnotationsWeb($studentId, $classId, $annotationData['date']);
                $annotationsFound = count($annotations);

                // Cerca l'annotazione appena inserita (stesso testo con marker)
                foreach ($annotations as $annotation) {
                    if (!empty($annotation['text']) && strpos($annotation['text'], $testMarker) !== false) {
                        $eventoId = $annotation['evento_id'];
                        break;
                    }
                }

                // Fallback 1: prendi la prima annotazione trovata per quella data
                if ($eventoId === null && !empty($annotations)) {
                    $eventoId = $annotations[0]['evento_id'] ?? null;
                }

                // Fallback 2: cerca senza filtro data (tutte le annotazioni dello studente)
                if ($eventoId === null) {
                    $allAnnotations = $cvApi->getStudentAnnotationsWeb($studentId, $classId, null);
                    $annotationsFound = count($allAnnotations);
                    if (!empty($allAnnotations)) {
                        // Prendi l'annotazione con evento_id più alto (presumibilmente la più recente)
                        usort($allAnnotations, function($a, $b) {
                            return ($b['evento_id'] ?? 0) <=> ($a['evento_id'] ?? 0);
                        });
                        $eventoId = $allAnnotations[0]['evento_id'] ?? null;
                    }
                }

                error_log("Test API annotation: trovate {$annotationsFound} annotazioni, evento_id: " . ($eventoId ?? 'null'));
            } catch (Exception $e) {
                // Se non riusciamo a recuperare l'evento_id, il cleanup sarà manuale
                error_log("Test API: impossibile recuperare evento_id annotazione per cleanup: " . $e->getMessage());
            }

            $typeLabel = $annotationType === 'positive' ? 'Positiva (verde)' :
                        ($annotationType === 'negative' ? 'Negativa (rosso)' : 'Neutra');

            $canCleanup = !empty($eventoId);
            $cleanupMsg = $canCleanup ? ' (cancellazione automatica disponibile)' : ' (cancellazione manuale richiesta)';
            jsonResponse(true, "Annotazione {$typeLabel} inserita con successo{$cleanupMsg}", [
                'resourceId' => $eventoId ?? '',
                'resourceName' => "Annotazione {$typeLabel}",
                'verifyUrl' => 'https://web.spaggiari.eu/cvv/app/default/gioprof_note.php?classe_id=' . $classId . '&gruppo_id=',
                'httpCode' => $result['http_code'] ?? '',
                'annotationData' => $annotationData,
                'evento_id' => $eventoId,
                'canCleanup' => $canCleanup
            ]);
            break;

        default:
            jsonResponse(false, '', null, 'Test ClasseViva non valido');
    }
}

/**
 * Cleanup di una risorsa creata
 */
function handleCleanup($config) {
    $platform = $_POST['platform'] ?? '';
    $test = $_POST['test'] ?? '';
    $resourceId = $_POST['resource_id'] ?? '';

    if (empty($resourceId)) {
        jsonResponse(false, '', null, 'ID risorsa mancante');
    }

    switch ($platform) {
        case 'drive':
            $driveApi = new GoogleDriveAPI($config);
            $driveApi->deleteFile($resourceId);
            jsonResponse(true, "Risorsa Drive eliminata");
            break;

        case 'classroom':
            $classroomApi = new GoogleClassroomAPI($config);
            $courseId = $_POST['course_id'] ?? '';

            if (empty($courseId)) {
                jsonResponse(false, '', null, 'Course ID mancante per cleanup');
            }

            if ($test === 'topic') {
                $classroomApi->deleteTopic($courseId, $resourceId);
            } elseif ($test === 'material') {
                $classroomApi->deleteMaterial($courseId, $resourceId);
            } elseif ($test === 'assignment') {
                $classroomApi->deleteAssignment($courseId, $resourceId);
            } else {
                jsonResponse(false, '', null, 'Tipo cleanup Classroom non supportato');
            }
            jsonResponse(true, "Risorsa Classroom eliminata");
            break;

        case 'forms':
            // I Google Forms sono file Drive, si eliminano tramite Drive API
            $driveApi = new GoogleDriveAPI($config);
            $driveApi->deleteFile($resourceId);
            jsonResponse(true, "Form eliminato");
            break;

        case 'classeviva':
            $cvApi = new ClasseVivaAPI($config);

            if ($test === 'grade') {
                // Il cleanup dei voti richiede evento_id e altri parametri
                $studentId = $_POST['student_id'] ?? '';
                $classId = $_POST['class_id'] ?? '';
                $subjectId = $_POST['subject_id'] ?? '';
                $descriptionCode = $_POST['description_code'] ?? '';

                if (empty($resourceId) || empty($studentId) || empty($classId) || empty($subjectId)) {
                    jsonResponse(false, '', null, 'Dati insufficienti per eliminare il voto. Parametri richiesti: evento_id, student_id, class_id, subject_id');
                }

                $deleteData = [
                    'evento_id' => $resourceId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'subject_id' => $subjectId,
                    'description_code' => $descriptionCode ?: 'S1_2_1'
                ];

                $result = $cvApi->deleteGrade($deleteData);
                jsonResponse(true, "Voto eliminato con successo dal registro ClasseViva");
            } elseif ($test === 'annotation') {
                // Il cleanup delle annotazioni richiede evento_id e altri parametri
                $studentId = $_POST['student_id'] ?? '';
                $classId = $_POST['class_id'] ?? '';
                $annotationDate = $_POST['annotation_date'] ?? date('Y-m-d');

                if (empty($resourceId) || empty($studentId)) {
                    jsonResponse(false, '', null, 'Dati insufficienti per eliminare l\'annotazione. Parametri richiesti: evento_id, student_id');
                }

                $deleteData = [
                    'evento_id' => $resourceId,
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'date' => $annotationDate
                ];

                $result = $cvApi->deleteAnnotation($deleteData);
                jsonResponse(true, "Annotazione eliminata con successo dal registro ClasseViva");
            } else {
                jsonResponse(false, '', null, 'Tipo cleanup ClasseViva non supportato');
            }
            break;

        default:
            jsonResponse(false, '', null, 'Piattaforma non valida per cleanup');
    }
}
