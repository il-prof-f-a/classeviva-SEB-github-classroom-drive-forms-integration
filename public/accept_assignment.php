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
 *  4. "Accetta" -> aggiunge all'org + collaborator, registra, redirect alla repo.
 *  5. Se ha già accettato -> redirect diretto alla repo.
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Utils\EncryptionHelper;
use App\Integration\GitHubIntegration;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function parse_repo(string $url): array {
    $url = trim($url);
    if (preg_match('#^https?://github[.]com/([^/]+)/([^/]+?)(?:[.]git)?/?$#i', $url, $m)) {
        return [strtolower($m[1]), strtolower($m[2])];
    }
    return ['', ''];
}

function find_student_by_email(PDO $pdo, string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    $st = $pdo->prepare('SELECT * FROM test_students WHERE lower(email) = ? LIMIT 1');
    $st->execute([$email]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

$code = trim((string)($_GET['code'] ?? ''));
$assignmentSlug = trim((string)($_GET['assignment'] ?? ''));

// --- DB temporaneo condiviso ---
$tempDb = ROOT_PATH . '/storage/temp/github_assignment_test.db';
$pdo = new PDO('sqlite:' . $tempDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS test_students (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, source TEXT, repo_name TEXT, repo_url TEXT, invite_status TEXT, error TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS test_config (k TEXT PRIMARY KEY, v TEXT)');
try { $pdo->exec('ALTER TABLE test_students ADD COLUMN github_username TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE test_students ADD COLUMN code TEXT'); } catch (Throwable $e) {}

$teacherId = (string)($pdo->query("SELECT v FROM test_config WHERE k = 'teacher_id'")->fetchColumn() ?: '');
$teacherToken = (string)($pdo->query("SELECT v FROM test_config WHERE k = 'teacher_token'")->fetchColumn() ?: '');
$expectedSlug = (string)($pdo->query("SELECT v FROM test_config WHERE k = 'assignment_slug'")->fetchColumn() ?: '');

// --- ricostruisci le credenziali OAuth GitHub del docente ---
$credsError = null;
if ($teacherId !== '') {
    try {
        $db = DatabaseFactory::createWithInitialization($config, true);
        $rows = $db->findWhere('INTEGRAZIONI_UTENTE', ['provider' => 'github', 'id_utente' => $teacherId]);
        $ghCfg = !empty($rows) ? EncryptionHelper::decrypt((string)($rows[0]['config_json'] ?? '')) : null;
        if (is_array($ghCfg) && !empty($ghCfg['client_id'])) {
            $config['github']['client_id'] = (string)$ghCfg['client_id'];
            $config['github']['client_secret'] = (string)($ghCfg['client_secret'] ?? '');
        } else {
            $credsError = 'Configurazione GitHub del docente non trovata.';
        }
    } catch (Throwable $e) {
        $credsError = $e->getMessage();
    }
}

$github = new GitHubIntegration($config);
$github->loadTokenFromSession();

// Riconosce lo studente tramite cookie persistente (evita di richiedere l'accesso ogni volta).
if (!$github->isAuthenticated() && isset($_COOKIE['github_student_login'])) {
    $cookieLogin = (string)$_COOKIE['github_student_login'];
    $st = $pdo->prepare('SELECT * FROM test_students WHERE github_username = ? LIMIT 1');
    $st->execute([$cookieLogin]);
    $cookieStudent = $st->fetch(PDO::FETCH_ASSOC);
    if ($cookieStudent && !empty($cookieStudent['repo_url'])) {
        header('Location: ' . (string)$cookieStudent['repo_url']);
        exit;
    }
}

if (isset($_GET['logout'])) {
    $github->logout();
    setcookie('github_student_login', '', ['expires' => time() - 3600, 'path' => '/']);
    $q = $code !== '' ? '?code=' . urlencode($code) : '?assignment=' . urlencode($assignmentSlug);
    header('Location: ' . $_SERVER['PHP_SELF'] . $q);
    exit;
}

$error = $credsError;
$student = null;
$showAccept = false;
$acceptUrl = '';
$repoUrl = '';

if ($github->isAuthenticated() && $error === null) {
    try {
        $user = $github->getUser();
        $username = (string)($user['login'] ?? '');
        setcookie('github_student_login', $username, ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $emails = $github->getUserEmails();
        $ghEmails = array_values(array_filter(array_map(static fn($e) => strtolower(trim((string)($e['email'] ?? ''))), $emails)));

        if ($code !== '') {
            $st = $pdo->prepare('SELECT * FROM test_students WHERE code = ?');
            $st->execute([$code]);
            $student = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$student) {
                $error = 'Codice non valido o assignment non trovato.';
            } else {
                $expectedEmail = strtolower(trim((string)($student['email'] ?? '')));
                if (!in_array($expectedEmail, $ghEmails, true)) {
                    $error = "La tua email GitHub non corrisponde a quella dell'assignment. Attesa: " . $expectedEmail . " | Nel tuo account GitHub: " . (empty($ghEmails) ? '(nessuna)' : implode(', ', $ghEmails)) . ".";
                }
            }
        } elseif ($assignmentSlug !== '') {
            if ($expectedSlug === '' || $assignmentSlug !== $expectedSlug) {
                $error = 'Assignment non valido.';
            } else {
                foreach ($ghEmails as $ge) {
                    $student = find_student_by_email($pdo, $ge);
                    if ($student !== null) break;
                }
                if ($student === null) {
                    $error = "Non sei nell'elenco di questo assignment. Controlla di usare l'account GitHub con la tua email istituzionale (" . (empty($ghEmails) ? 'nessuna email letta' : implode(', ', $ghEmails)) . ").";
                }
            }
        } else {
            $error = 'Parametri mancanti. Usa il link ricevuto dal docente.';
        }

        if ($student !== null && $error === null) {
            $repoUrl = (string)($student['repo_url'] ?? '');
            if ($repoUrl === '') {
                $error = 'La repository non è ancora pronta. Contatta il docente.';
            } else {
                $accepted = !empty($student['github_username']);
                if ($accepted) {
                    header('Location: ' . $repoUrl);
                    exit;
                }

                $acceptUrl = 'accept_assignment.php' . ($code !== '' ? '?code=' . urlencode($code) : '?assignment=' . urlencode($assignmentSlug)) . '&accept=1';

                if (isset($_GET['accept'])) {
                    [$org, $repo] = parse_repo($repoUrl);
                    if ($org === '' || $repo === '') {
                        $error = 'URL repository non valido.';
                    } elseif ($teacherToken === '') {
                        $error = 'Token docente non disponibile. Il docente deve rilanciare "Crea assignment".';
                    } else {
                        $teacher = new GitHubIntegration($config);
                        $teacher->setAccessToken($teacherToken);
                        $teacher->addOrgMember($org, $username);
                        $teacher->addCollaborator($org, $repo, $username);
                        $pdo->prepare('UPDATE test_students SET github_username = ? WHERE id = ?')->execute([$username, $student['id']]);
                        header('Location: ' . $repoUrl);
                        exit;
                    }
                } else {
                    $showAccept = true;
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Errore: ' . $e->getMessage();
    }
}

$_SESSION['github_student_client_id'] = $config['github']['client_id'] ?? '';
$_SESSION['github_student_client_secret'] = $config['github']['client_secret'] ?? '';

$authUrl = '';
if (!$github->isAuthenticated() && $error === null && ($code !== '' || $assignmentSlug !== '')) {
    $returnTo = $code !== '' ? 'accept_assignment.php?code=' . urlencode($code) : 'accept_assignment.php?assignment=' . urlencode($assignmentSlug);
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

            <?php if ($code === '' && $assignmentSlug === ''): ?>
                <p class="text-danger">Parametri mancanti. Usa il link ricevuto dal docente.</p>

            <?php elseif ($error !== null): ?>
                <div class="alert alert-danger text-start"><?= h($error) ?></div>
                <?php if ($github->isAuthenticated()): ?>
                <form method="get" class="d-inline">
                    <?php if ($code !== ''): ?><input type="hidden" name="code" value="<?= h($code) ?>"><?php endif; ?>
                    <?php if ($assignmentSlug !== ''): ?><input type="hidden" name="assignment" value="<?= h($assignmentSlug) ?>"><?php endif; ?>
                    <button type="submit" name="logout" value="1" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-box-arrow-left"></i> Cambia account GitHub
                    </button>
                </form>
                <?php endif; ?>

            <?php elseif ($showAccept): ?>
                <p class="text-muted">Sei nell'elenco dell'assignment ma non hai ancora accettato la repository.</p>
                <a href="<?= h($acceptUrl) ?>" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle"></i> Accetta e apri la repository
                </a>

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
