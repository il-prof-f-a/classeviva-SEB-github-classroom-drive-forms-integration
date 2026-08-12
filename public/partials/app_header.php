<?php
$pageTitle = $pageTitle ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$headerActions = $headerActions ?? '';
$pageActions = $pageActions ?? ''; // Azioni da mostrare in linea con il titolo
$headerContainerClass = $headerContainerClass ?? 'container';
$skipOnboardingBanner = $skipOnboardingBanner ?? false;
$showOnboardingBanner = $showOnboardingBanner ?? null;
$onboardingBannerDays = $onboardingBannerDays ?? 28;
$onboardingBannerTestUrl = $onboardingBannerTestUrl ?? 'test_api_integrations.php';

if (!$skipOnboardingBanner && $showOnboardingBanner === null) {
    $showOnboardingBanner = false;
    $userIdForBanner = (string)($_SESSION['user_id'] ?? '');

    if ($userIdForBanner !== '') {
        $bannerUser = null;

        try {
            if (isset($dbAdapter) && is_object($dbAdapter) && method_exists($dbAdapter, 'findOne')) {
                $bannerUser = $dbAdapter->findOne('UTENTI', 'id_utente', $userIdForBanner);
            } elseif (isset($config) && is_array($config)) {
                $bannerDb = \App\Core\Database\DatabaseFactory::createWithInitialization($config, true);
                $bannerUser = $bannerDb->findOne('UTENTI', 'id_utente', $userIdForBanner);
            }
        } catch (\Throwable $e) {
            $bannerUser = null;
        }

        $consentDate = is_array($bannerUser) ? (string)($bannerUser['privacy_consent_date'] ?? '') : '';
        if ($consentDate !== '') {
            $consentTs = strtotime($consentDate);
            if ($consentTs !== false) {
                $showOnboardingBanner = (time() - $consentTs) <= ($onboardingBannerDays * 86400);
            }
        }
    }
}
?>
<style>
    .uda-app-header {
        background: #0d6efd;
        color: #fff;
    }
    .uda-app-header-inner {
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .uda-brand {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
    }
    .uda-brand img {
        width: 28px;
        height: 28px;
    }
    .uda-app-header-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .uda-app-header-actions a {
        color: #fff;
    }
    .uda-app-header-actions .nav-link {
        color: #fff;
        padding: 0.25rem 0.5rem;
        text-decoration: none;
    }
    .uda-app-header-actions .nav-link:hover {
        text-decoration: underline;
    }
    .uda-app-header-actions .btn {
        border-color: rgba(255, 255, 255, 0.7);
        color: #fff;
    }
    .uda-app-header-actions .btn:hover {
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
    }
    .uda-page-title {
        margin-top: 1rem;
    }
    .uda-page-title h1 {
        margin: 0;
        font-size: 1.5rem;
    }
    .uda-page-subtitle {
        margin-top: 0.25rem;
        color: #6c757d;
    }
    .uda-page-title-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .uda-page-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .onboarding-banner ul {
        padding-left: 1.2rem;
        margin-bottom: 0;
    }
    .onboarding-banner .alert-heading {
        font-size: 1.05rem;
    }
</style>

<header class="uda-app-header">
    <div class="uda-app-header-inner <?= htmlspecialchars($headerContainerClass) ?>">
        <a class="uda-brand" href="index.php">
            <img src="icons/icon-192.png" alt="UDA-System">
            <span>Sistema UDA</span>
        </a>
        <?php if (!empty($headerActions)): ?>
            <div class="uda-app-header-actions">
                <?= $headerActions ?>
            </div>
        <?php endif; ?>
    </div>
</header>

<?php if ($showOnboardingBanner): ?>
    <div class="<?= htmlspecialchars($headerContainerClass) ?> mt-3">
        <div class="alert alert-warning border-warning onboarding-banner">
            <div class="d-flex align-items-start gap-3">
                <div class="fs-3 text-warning"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="alert-heading mb-2"><strong>Sistema in fase di test: fai i test con gli studenti.</strong></div>
                    <p class="mb-2">
                        Nelle prime 4 settimane di utilizzo ti consigliamo di provare le integrazioni insieme agli studenti
                        e di ricontrollare ogni risultato. <strong>Ricontrolla sempre</strong> tutte le azioni svolte dalla piattaforma,
                        soprattutto in una fase iniziale. <strong>Ricontrolla sempre.</strong>
                    </p>
                    <ul class="small mb-2">
                        <li><strong>Test prima delle automazioni:</strong> esegui i test prima di attivare operazioni automatiche
                            su ClasseViva e Google Classroom. Sono strumenti di valutazione con valore legale e il proprietario
                            non si assume alcuna responsabilita'.
                        </li>
                        <li><strong>ClasseViva puo' essere personalizzato</strong> dalla scuola e puo' comportarsi in modo diverso
                            durante quadrimestri, trimestri, pentamestri o bimestri: ricontrolla sempre i risultati.
                        </li>
                        <li>Se qualcosa non funziona correttamente, contatta lo sviluppatore per supporto tecnico e per migliorare la piattaforma.</li>
                    </ul>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= htmlspecialchars($onboardingBannerTestUrl) ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-plug"></i> Apri Test Integrazioni API
                        </a>
                        <a href="guida_portale.php" class="btn btn-outline-dark btn-sm">
                            <i class="bi bi-book"></i> Apri Guida e Note di Test
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($pageTitle !== ''): ?>
    <div class="uda-page-title <?= htmlspecialchars($headerContainerClass) ?>">
        <div class="uda-page-title-row">
            <div>
                <h1><?= $pageTitle ?></h1>
                <?php if ($pageSubtitle !== ''): ?>
                    <div class="uda-page-subtitle"><?= htmlspecialchars($pageSubtitle) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($pageActions !== ''): ?>
                <div class="uda-page-actions">
                    <?= $pageActions ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
