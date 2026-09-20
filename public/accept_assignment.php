<?php
/**
 * Pagina STUDENTE: accetta l'assignment via OAuth GitHub (senza login al portale).
 *
 * Modalità:
 *  - ?code=XXXX       link personalizzato per studente.
 *  - ?assignment=XXXX link generico di classe.
 *
 * Flusso (caso generico):
 *  1. OAuth GitHub (read:user user:email) -> email.
 *  2. Se l'email non è in elenco -> chiede di cambiare account.
 *  3. Se è in elenco e NON ha ancora accettato -> mostra "Accetta".
 *  4. "Accetta" -> aggiunge all'org + collaborator (token docente), registra, redirect.
 *  5. Se ha già accettato -> redirect diretto alla repo.
 *
 * Le email vengono risolte just-in-time dai roster del gruppo (Google Classroom
 * primario, ClasseViva fallback); il docente può inoltre salvare un override
 * esplicito sulla singola associazione dall'editor dell'assignment.
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentService;
use App\Core\Security\Csrf;
use App\Core\Security\PublicError;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Core\UserIntegrationManager;
use App\Integration\ClasseVivaAPI;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Utils\EncryptionHelper;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function parse_repo(string $url): array {
    $url = trim($url);
    if (preg_match('#^https?://github[.]com/([^/]+)/([^/]+?)(?:[.]git)?/?$#i', $url, $m)) {
        return [strtolower($m[1]), strtolower($m[2])];
    }
    return ['', ''];
}

/**
 * Cerca il link già associato a un account GitHub nello stesso assignment.
 * È un'identificazione secondaria per il link generico: vale solo quando il
 * docente ha già registrato lo username sulla riga dell'assignment e non
 * sostituisce la verifica email del roster per gli studenti non ancora accettati.
 */
function find_assignment_link_by_github_username($db, string $testId, string $username): ?array {
    $testId = trim($testId);
    $username = strtolower(trim($username));
    if ($testId === '' || $username === '') {
        return null;
    }
    foreach ($db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $candidateUsername = strtolower(trim((string)($candidate['github_username'] ?? '')));
        if ($candidateUsername !== '' && hash_equals($candidateUsername, $username)) {
            return $candidate;
        }
    }
    return null;
}

/** Risolve email/nome degli studenti del gruppo (Google Classroom primario, ClasseViva fallback). */
function resolve_group_emails($db, array $config, string $groupId, string $ownerId): array {
    $profile = $config['user_profile'] ?? [];
    $emailTemplate = (string)($profile['school_student_email_template'] ?? '');
    $emailDomain = (string)($profile['school_email_domain'] ?? '');
    $integrations = new TeachingGroupIntegrationRepository($db, $ownerId);
    $gcCourse = '';
    $cvClass = '';
    foreach ($integrations->listForGroup($groupId) as $it) {
        if (($it['stato'] ?? 'attivo') === 'disattivo') continue;
        $p = (string)($it['provider'] ?? '');
        if ($p === 'google_classroom') $gcCourse = (string)($it['external_context_id'] ?? '');
        elseif ($p === 'classeviva') $cvClass = (string)($it['external_context_id'] ?? '');
    }
    $rosters = [];
    if ($gcCourse !== '') {
        try { $rosters['google_classroom'] = (new GoogleClassroomAPI($config))->getCourseStudents($gcCourse); }
        catch (Throwable $e) { $rosters['google_classroom'] = []; }
    }
    if ($cvClass !== '') {
        try { $rosters['classeviva'] = (new ClasseVivaAPI($config))->getStudentiClasse($cvClass); }
        catch (Throwable $e) { $rosters['classeviva'] = []; }
    }
    $matrix = (new TeachingGroupStudentService($db, $ownerId))->matrix($groupId);
    return (new GitHubAssignmentService($emailTemplate, $emailDomain))->resolveStudents($matrix, $rosters, 'google_classroom');
}

$code = trim((string)($_GET['code'] ?? ''));
$slug = trim((string)($_GET['assignment'] ?? ''));

$db = DatabaseFactory::createWithInitialization($config, true);

// --- carica test + link dal dominio reale (TEST + GITHUB_ASSIGNMENT_STUDENT_LINKS) ---
$test = null;
$link = null;
$error = null;
$testId = '';
$studentId = '';
$org = '';
$groupId = '';
$ownerId = '';
$teacherToken = '';

if ($code !== '') {
    $links = $db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['acceptance_code' => $code]);
    $link = $links[0] ?? null;
    if (!$link) {
        $error = 'Codice non valido o assignment non trovato.';
    } else {
        $testId = trim((string)($link['id_assignment'] ?? ''));
        $test = $db->findOne('TEST', 'id_test', $testId);
        if (!$test) $error = 'Assignment non trovato.';
        else $studentId = trim((string)($link['id_studente'] ?? ''));
    }
} elseif ($slug !== '') {
    foreach ($db->findWhere('TEST', ['piattaforma' => 'github']) as $t) {
        $cfg = json_decode((string)($t['github_config_json'] ?? '{}'), true);
        if (is_array($cfg) && (string)($cfg['slug'] ?? '') === $slug) { $test = $t; break; }
    }
    if (!$test) $error = 'Assignment non valido.';
    else $testId = trim((string)($test['id_test'] ?? ''));
} else {
    $error = 'Parametri mancanti. Usa il link ricevuto dal docente.';
}

if ($test !== null && $error === null) {
    $cfg = json_decode((string)($test['github_config_json'] ?? '{}'), true);
    $cfg = is_array($cfg) ? $cfg : [];
    $org = (string)($cfg['org'] ?? '');
    $groupId = trim((string)($test['id_gruppo'] ?? ''));
    $ownerId = trim((string)($test['id_utente'] ?? ''));
    $encToken = (string)($cfg['teacher_token'] ?? '');
    if ($encToken !== '') {
        try { $teacherToken = (string)EncryptionHelper::decrypt($encToken); } catch (Throwable $e) { $teacherToken = ''; }
    }
    // Ricostruisce le configurazioni del docente dai provider per-utente: la pagina è
    // pubblica (studente non loggato), quindi il bootstrap non le ha caricate. Servono in
    // particolare il token Google (per il roster Classroom) e il profilo (template email).
    if ($ownerId !== '') {
        try {
            $uim = new UserIntegrationManager($db, $ownerId);
            $ghCfg = $uim->getConfig('github');
            if (!empty($ghCfg['client_id'])) {
                $config['github']['client_id'] = (string)$ghCfg['client_id'];
                $config['github']['client_secret'] = (string)($ghCfg['client_secret'] ?? '');
            }
            $googleCfg = $uim->getConfig('google');
            if (!empty($googleCfg['token']) && is_array($googleCfg['token'])) {
                $config['google']['oauth_token'] = $googleCfg['token'];
            }
            $cvCfg = $uim->getConfig('classeviva');
            if (!empty($cvCfg)) {
                $config['classeviva'] = array_merge($config['classeviva'] ?? [], $cvCfg);
            }
            $profileCfg = $uim->getConfig('profile');
            if (!empty($profileCfg)) {
                $config['user_profile'] = $profileCfg;
            }
        } catch (Throwable $e) {
            // non bloccante
        }
    }
}

$github = new GitHubIntegration($config);
$github->loadTokenFromSession();

// Cookie persistente: evita di richiedere l'accesso ogni volta.
if (!$github->isAuthenticated() && isset($_COOKIE['github_student_login']) && $link !== null) {
    $cookieLogin = (string)$_COOKIE['github_student_login'];
    $repoUrl = trim((string)($link['student_repository_url'] ?? ''));
    if ((string)($link['github_username'] ?? '') === $cookieLogin && $repoUrl !== '') {
        header('Location: ' . $repoUrl);
        exit;
    }
}

if (isset($_GET['logout'])) {
    $github->logout();
    setcookie('github_student_login', '', ['expires' => time() - 3600, 'path' => '/']);
    $q = $code !== '' ? '?code=' . urlencode($code) : '?assignment=' . urlencode($slug);
    header('Location: accept_assignment.php' . $q);
    exit;
}

$csrfToken = Csrf::token($_SESSION);

$showAccept = false;
$acceptAction = '';
$repoUrl = '';

if ($github->isAuthenticated() && $error === null) {
    try {
        $user = $github->getUser();
        $username = (string)($user['login'] ?? '');
        setcookie('github_student_login', $username, ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $emails = $github->getUserEmails();
        $ghEmails = array_values(array_filter(array_map(static fn($e) => strtolower(trim((string)($e['email'] ?? ''))), $emails)));

        $resolved = ($groupId !== '' && $ownerId !== '') ? resolve_group_emails($db, $config, $groupId, $ownerId) : [];

        if ($code !== '') {
            // L'editor docente può impostare un'email esplicita per la riga
            // dell'assignment. Ha precedenza sul roster runtime: consente di
            // correggere un'email istituzionale senza modificare l'identità
            // persistente dello studente.
            $expectedEmail = strtolower(trim((string)($link['email_studente'] ?? '')));
            if ($expectedEmail === '') {
                foreach ($resolved as $s) {
                    if ((string)($s['id_studente'] ?? '') === $studentId) { $expectedEmail = strtolower(trim((string)($s['email'] ?? ''))); break; }
                }
            }
            if ($expectedEmail === '') {
                // Il codice personale identifica già una sola riga di
                // GITHUB_ASSIGNMENT_STUDENT_LINKS. Per i gruppi ClasseViva il
                // roster può non essere raggiungibile dalla pagina pubblica:
                // il token del docente resta nella sua sessione e non viene
                // persistito. In questo caso il codice è la credenziale
                // personale e non dobbiamo bloccare l’accettazione.
                // I link generici continuano invece a richiedere il roster.
                if (!($code !== '' && $studentId !== '' && $resolved === [])) {
                    $error = 'Email studente non risolta (verifica i roster Google Classroom/ClasseViva del gruppo).';
                }
            } elseif (!in_array($expectedEmail, $ghEmails, true)) {
                $error = "La tua email GitHub non corrisponde a quella dell'assignment. Attesa: " . $expectedEmail . " | Nel tuo account GitHub: " . (empty($ghEmails) ? '(nessuna)' : implode(', ', $ghEmails)) . ".";
            }
        } elseif ($slug !== '') {
            $matched = null;
            foreach ($resolved as $s) {
                $e = strtolower(trim((string)($s['email'] ?? '')));
                if ($e !== '' && in_array($e, $ghEmails, true)) { $matched = $s; break; }
            }
            // Anche il link generico deve riconoscere gli override email
            // salvati dal docente, prima del fallback allo username già
            // accettato.
            if ($matched === null) {
                foreach ($db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $candidate) {
                    if (!is_array($candidate)) continue;
                    $overrideEmail = strtolower(trim((string)($candidate['email_studente'] ?? '')));
                    if ($overrideEmail !== '' && in_array($overrideEmail, $ghEmails, true)) {
                        $matched = [
                            'id_studente' => (string)($candidate['id_studente'] ?? ''),
                            'email' => $overrideEmail,
                        ];
                        $link = $candidate;
                        break;
                    }
                }
            }
            // Se lo studente ha già accettato l'assignment, il suo username è
            // registrato sul link. Questo consente di usare il link generico
            // anche quando il roster ClasseViva non è raggiungibile dalla
            // pagina pubblica; gli studenti non ancora accettati continuano a
            // richiedere la corrispondenza email del roster.
            if ($matched === null) {
                $storedLink = find_assignment_link_by_github_username($db, $testId, $username);
                if ($storedLink !== null) {
                    $matched = [
                        'id_studente' => (string)($storedLink['id_studente'] ?? ''),
                        'email' => '',
                    ];
                    $link = $storedLink;
                }
            }
            if ($matched === null) {
                $error = "Non sei nell'elenco di questo assignment. Controlla di usare l'account GitHub con la tua email istituzionale (" . (empty($ghEmails) ? 'nessuna email letta' : implode(', ', $ghEmails)) . ").";
            } else {
                $studentId = (string)($matched['id_studente'] ?? '');
                if ($link === null) {
                    $links = $db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId, 'id_studente' => $studentId]);
                    $link = $links[0] ?? null;
                }
                if (!$link) $error = 'Assignment non trovato per il tuo account.';
            }
        }

        if ($link !== null && $error === null) {
            $repoUrl = trim((string)($link['student_repository_url'] ?? ''));
            if ($repoUrl === '') {
                $error = 'La repository non è ancora pronta. Contatta il docente.';
            } else {
                $accepted = (trim((string)($link['github_username'] ?? '')) !== '');
                if ($accepted) {
                    header('Location: ' . $repoUrl);
                    exit;
                }
                $acceptAction = 'accept_assignment.php' . ($code !== '' ? '?code=' . urlencode($code) : '?assignment=' . urlencode($slug));
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['accept'])) {
                    try {
                        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
                    } catch (Throwable $e) {
                        $error = PublicError::message($e, 'github acceptance');
                    }
                    if ($error === null) {
                        if ($teacherToken === '') {
                            $error = "Token docente non disponibile. Il docente deve ricreare l'assignment.";
                        } elseif ($org === '') {
                            $error = 'Organizzazione GitHub non configurata per questo assignment.';
                        } else {
                            [$owner, $repoName] = parse_repo($repoUrl);
                            if ($owner === '' || $repoName === '') {
                                $error = 'URL repository non valido.';
                            } else {
                                $teacher = new GitHubIntegration($config);
                                $teacher->setAccessToken($teacherToken);
                                $teacher->addOrgMember($org, $username);
                                $teacher->addCollaborator($owner, $repoName, $username);
                                // id_map può essere NULL nei link creati dalla pagina di creazione,
                                // quindi aggiorniamo per (id_assignment, id_studente), la chiave reale.
                                $pdo = $db->getConnection();
                                if ($pdo instanceof \PDO) {
                                    $stmt = $pdo->prepare(
                                        'UPDATE GITHUB_ASSIGNMENT_STUDENT_LINKS SET github_username = ?, accepted_at = ? WHERE id_assignment = ? AND id_studente = ?'
                                    );
                                    $stmt->execute([$username, date('Y-m-d H:i:s'), (string)($link['id_assignment'] ?? ''), (string)($link['id_studente'] ?? '')]);
                                }
                                header('Location: ' . $repoUrl);
                                exit;
                            }
                        }
                    }
                } else {
                    $showAccept = true;
                }
            }
        }
    } catch (Throwable $e) {
        $error = PublicError::message($e, 'github acceptance');
    }
}

$_SESSION['github_student_client_id'] = $config['github']['client_id'] ?? '';
$_SESSION['github_student_client_secret'] = $config['github']['client_secret'] ?? '';

$authUrl = '';
if (!$github->isAuthenticated() && $error === null && ($code !== '' || $slug !== '')) {
    $returnTo = $code !== '' ? 'accept_assignment.php?code=' . urlencode($code) : 'accept_assignment.php?assignment=' . urlencode($slug);
    $authUrl = $github->getAuthorizationUrl(null, $returnTo, 'read:user user:email');
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accetta assignment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 620px;">
    <div class="card shadow-sm">
        <div class="card-body text-center">
            <h1 class="h4 mb-3"><i class="bi bi-github"></i> Accetta assignment</h1>

            <?php if ($code === '' && $slug === ''): ?>
                <p class="text-danger">Parametri mancanti. Usa il link ricevuto dal docente.</p>

            <?php elseif ($error !== null): ?>
                <div class="alert alert-danger text-start"><?= h($error) ?></div>
                <?php if ($github->isAuthenticated()): ?>
                <form method="get" class="d-inline">
                    <?php if ($code !== ''): ?><input type="hidden" name="code" value="<?= h($code) ?>"><?php endif; ?>
                    <?php if ($slug !== ''): ?><input type="hidden" name="assignment" value="<?= h($slug) ?>"><?php endif; ?>
                    <button type="submit" name="logout" value="1" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-box-arrow-left"></i> Cambia account GitHub
                    </button>
                </form>
                <?php endif; ?>

            <?php elseif ($showAccept): ?>
                <p class="text-muted">Sei nell'elenco dell'assignment ma non hai ancora accettato la repository.</p>
                <form method="post" action="<?= h($acceptAction) ?>">
                    <input type="hidden" name="accept" value="1">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle"></i> Accetta e apri la repository
                    </button>
                </form>

            <?php else: ?>
                <p class="text-muted">Accedi con GitHub per ricevere l'accesso alla tua repository.
                    <br><small>Useremo la tua email per identificare il tuo assignment.</small></p>
                <a href="<?= h($authUrl) ?>" class="btn btn-dark btn-lg">
                    <i class="bi bi-github"></i> Accedi con GitHub
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
