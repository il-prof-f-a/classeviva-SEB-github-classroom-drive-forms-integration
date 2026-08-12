<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cvQuickLoginReturnTo = $_SERVER['REQUEST_URI'] ?? '';
$cvQuickLoginReturnTo = $cvQuickLoginReturnTo !== '' ? $cvQuickLoginReturnTo : '';

$cvQuickLoginFlashKey = 'cv_quick_login_flash';
$cvQuickLoginFlash = $_SESSION[$cvQuickLoginFlashKey] ?? null;
$cvQuickLoginFlashMessage = null;
$cvQuickLoginFlashClass = '';
$cvQuickLoginPopupActive = defined('CV_TOKEN_POPUP_ACTIVE') && CV_TOKEN_POPUP_ACTIVE === true;

if (!$cvQuickLoginPopupActive && $cvQuickLoginFlash) {
    $flashReturnTo = (string)($cvQuickLoginFlash['return_to'] ?? '');
    $currentPath = $cvQuickLoginReturnTo;
    $flashMatch = false;

    if ($flashReturnTo === '' && $currentPath !== '') {
        $flashMatch = true;
    } elseif ($flashReturnTo === $currentPath) {
        $flashMatch = true;
    } else {
        $flashParts = parse_url($flashReturnTo);
        if ($flashParts !== false) {
            $flashPath = (string)($flashParts['path'] ?? '');
            $flashQuery = isset($flashParts['query']) ? '?' . $flashParts['query'] : '';
            if ($flashPath . $flashQuery === $currentPath) {
                $flashMatch = true;
            }
        }
    }

    if ($flashMatch) {
        if (!empty($cvQuickLoginFlash['error'])) {
            $cvQuickLoginFlashMessage = (string)$cvQuickLoginFlash['error'];
            $cvQuickLoginFlashClass = 'text-danger';
        } elseif (!empty($cvQuickLoginFlash['success'])) {
            $cvQuickLoginFlashMessage = (string)$cvQuickLoginFlash['success'];
            $cvQuickLoginFlashClass = 'text-success';
        }
        unset($_SESSION[$cvQuickLoginFlashKey]);
    }
}
?>

<?php if ($cvQuickLoginFlashMessage): ?>
    <div class="mt-2 <?= htmlspecialchars($cvQuickLoginFlashClass) ?>">
        <?= htmlspecialchars($cvQuickLoginFlashMessage) ?>
    </div>
<?php endif; ?>

<?php
// Il popup globale del bootstrap è l'unico punto di riautenticazione.
// Questo partial mantiene soltanto eventuali messaggi flash per compatibilità
// con le pagine esistenti e non renderizza un secondo modal.
?>
