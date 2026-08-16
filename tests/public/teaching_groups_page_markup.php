<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/public/teaching_groups.php';
$markup = is_file($path) ? (file_get_contents($path) ?: '') : '';
$failures = [];

foreach ([
    'DatabaseFactory::createWithInitialization',
    'TeachingGroupRepository',
    'TeachingGroupIntegrationRepository',
    'TeachingGroupService',
    'TeachingGroupStudentService',
] as $wiring) {
    if (!str_contains($markup, $wiring)) {
        $failures[] = "wiring dominio mancante: {$wiring}";
    }
}
foreach (['syncRoster', 'matrix', 'linkIdentities', 'unlinkIdentity', 'save_student_mapping', 'sync_students', 'unlink_identity', 'filter_students', 'student_status', 'assertRosterIdentity', 'listAcceptedAssignments', 'syncedCount'] as $studentsNeedle) {
    if (!str_contains($markup, $studentsNeedle)) {
        $failures[] = "gestione studenti incompleta: {$studentsNeedle}";
    }
}
foreach (['teaching_groups_flash', 'student_sync', 'display_names', 'Roster sincronizzato'] as $flashNeedle) {
    if (!str_contains($markup, $flashNeedle)) {
        $failures[] = "flash roster mancante: {$flashNeedle}";
    }
}
foreach (['githubRosterSeen', 'Nessuna selezione: mappatura invariata.', 'teaching_groups.php?tab=students&id='] as $studentsRegressionNeedle) {
    if (!str_contains($markup, $studentsRegressionNeedle)) {
        $failures[] = "regressione studenti mancante: {$studentsRegressionNeedle}";
    }
}
foreach (['tutti', 'mappati', 'non_mappati', 'conflitti', 'external_user_id', 'provider_context', 'id_studente'] as $studentsField) {
    if (!str_contains($markup, $studentsField)) {
        $failures[] = "controllo studenti mancante: {$studentsField}";
    }
}
if (!str_contains($markup, 'ClasseVivaTokenGuard::getTokenState')
    || !str_contains($markup, 'token_valid')) {
    $failures[] = 'gating ClasseViva senza popup mancante';
}
if (!str_contains($markup, "define('REQUIRES_CLASSEVIVA', false)")
    || !str_contains($markup, 'catalogError')) {
    $failures[] = 'pagina provider-neutral (senza gate globale) o stato errore catalogo mancante';
}

foreach ([
    'create_group',
    'update_group',
    'link_provider',
    'unlink_provider',
    'save_student_mapping',
] as $needle) {
    $actionPattern = "/<(?:input|button)\\b(?=[^>]*\\bname=[\"']action[\"'])(?=[^>]*\\bvalue=[\"']"
        . preg_quote($needle, '/') . "[\"'])[^>]*>/i";
    if (!preg_match($actionPattern, $markup)) {
        $failures[] = "azione POST mancante: {$needle}";
    }
}
if (!preg_match("/<form\\b[^>]*\\bmethod=[\"']post[\"']/i", $markup)) {
    $failures[] = 'form POST mancante';
}
$postForms = preg_match_all("/<form\\b[^>]*\\bmethod=[\"']post[\"']/i", $markup);
$csrfFields = preg_match_all("/name=[\"']csrf_token[\"']/i", $markup);
if ($postForms === false || $csrfFields === false || $csrfFields < $postForms) {
    $failures[] = 'CSRF mancante nei form POST';
}
if (!str_contains($markup, 'hash_equals') || !preg_match("/<label\\b[^>]*\\bfor=[\"']/i", $markup)) {
    $failures[] = 'verifica CSRF o label accessibile mancante';
}
if (!str_contains($markup, 'tab=students')) {
    $failures[] = 'tab studenti senza link locale';
}
if (str_contains($markup, 'elseif (false)')) {
    $failures[] = 'ramo legacy studenti disabilitato ancora presente';
}
foreach (['matches[', 'student_id', 'save_student_mapping'] as $rowMappingNeedle) {
    if (!str_contains($markup, $rowMappingNeedle)) {
        $failures[] = "mappatura row-level mancante: {$rowMappingNeedle}";
    }
}
if (!preg_match('/<form\\b[^>]*aria-label=["\']Collega identita studente["\'][\\s\\S]*?name=["\']matches\\[/i', $markup)) {
    $failures[] = 'form row-level senza selezioni matches';
}
if (!str_contains($markup, "\$_GET['id']") || !str_contains($markup, 'http_response_code(404)')) {
    $failures[] = 'deep-link studenti/ownership non hardenizzato';
}
if (!preg_match('/<input\b[^>]*\bname=["\'](?:q|filter)["\']/i', $markup)) {
    $failures[] = 'filtro gruppi mancante';
}
foreach (['nome_gruppo', 'nome_classe', 'nome_materia', 'anno_scolastico'] as $field) {
    if (!preg_match('/\bname=["\']' . preg_quote($field, '/') . '["\']/i', $markup)) {
        $failures[] = "campo base mancante: {$field}";
    }
}
if (!str_contains($markup, 'return_to') || !str_contains($markup, 'LocalReturnUrl::sanitize')) {
    $failures[] = 'return_to non sanitizzato o assente';
}
if (!preg_match("/(?:href|action)=[\"'][^\"']*(?:teaching_groups|uda_create)\\.php[^\"']*[\"']/i", $markup)) {
    $failures[] = 'link locale editor/wizard mancante';
}
foreach (['classeviva', 'google_classroom', 'github_classroom'] as $provider) {
    if (!str_contains($markup, $provider)) {
        $failures[] = "provider mancante nel markup: {$provider}";
    }
}
if (!preg_match('/Autorizz|autorizz|collegat|Non disponibile/i', $markup)) {
    $failures[] = 'stato autorizzazione provider mancante';
}
foreach (['user_integrations.php#classeviva-section', 'user_integrations.php#google-section', 'user_integrations.php#github-section'] as $authLink) {
    if (!str_contains($markup, $authLink)) {
        $failures[] = "link autorizzazione mancante: {$authLink}";
    }
}
if (!str_contains($markup, "'classeviva' => 'classeviva_context'")
    || !str_contains($markup, "'google_classroom' => 'google_course_id'")
    || !str_contains($markup, "'github_classroom' => 'github_classroom_id'")
    || str_contains($markup, 'name="external_context_id"')) {
    $failures[] = 'catalogo provider deve usare select provider-specifici';
}
foreach (['classe_materia', 'course', 'roster'] as $resourceType) {
    if (!str_contains($markup, $resourceType)) {
        $failures[] = "tipo risorsa provider mancante: {$resourceType}";
    }
}
if (!str_contains($markup, 'uda_create.php?integration_updated=1#2')) {
    $failures[] = 'redirect wizard con ancora #2 mancante';
}
foreach (['password', 'client_secret', 'refresh_token'] as $forbidden) {
    $secretPattern = "/name\\s*=\\s*[\\\"']" . preg_quote($forbidden, '/') . "[\\\"']/i";
    if (preg_match($secretPattern, $markup)) {
        $failures[] = "segreto esposto nella pagina: {$forbidden}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: editor gruppi senza segreti e con ritorni locali.\n");
