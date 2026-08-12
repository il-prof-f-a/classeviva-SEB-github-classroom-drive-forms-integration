<?php
/**
 * Step 3: Risultato Importazione
 */

$pubblicati = $_SESSION['pubblicati'] ?? [];
$errori = $_SESSION['errori'] ?? [];
$emailSent = $_SESSION['email_sent'] ?? false;
$hasErrors = !empty($errori);

$totale = count($pubblicati);
$sufficienti = count(array_filter($pubblicati, fn($p) => $p['voto'] >= 6));
$insufficienti = $totale - $sufficienti;
$mediaVoti = $totale > 0 ? array_sum(array_column($pubblicati, 'voto')) / $totale : 0;
$percSufficienti = $totale > 0 ? round($sufficienti / $totale * 100, 1) : 0;
$percInsufficienti = $totale > 0 ? round($insufficienti / $totale * 100, 1) : 0;
$tipoVoto = !empty($pubblicati) ? ($pubblicati[0]['tipo_voto'] ?? 'Scritto') : 'Scritto';
$hasImportedVotes = $totale > 0;

$cardBorderClass = $hasErrors ? 'border-warning' : 'border-success';
$cardHeaderClass = $hasErrors ? 'bg-warning text-dark' : 'bg-success text-white';
$cardHeaderIcon = $hasErrors ? 'bi-exclamation-triangle-fill text-warning' : 'bi-check-circle';
$cardHeaderTitle = $hasErrors ? 'Voti importati con eccezioni' : 'Voti importati con successo';
$alertIcon = $hasErrors ? 'bi-exclamation-triangle-fill text-warning' : 'bi-check-circle-fill text-success';
$alertTitle = $hasErrors ? 'Operazione completata con alcune eccezioni' : 'Operazione conclusa con successo';

// Pulisci sessione
unset($_SESSION['form_responses']);
unset($_SESSION['pubblicati']);
unset($_SESSION['errori']);
unset($_SESSION['email_sent']);
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card <?= $cardBorderClass ?>">
            <div class="card-header <?= $cardHeaderClass ?>">
                <h5 class="mb-0">
                    <i class="bi <?= $cardHeaderIcon ?>"></i> <?= $cardHeaderTitle ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="alert <?= $hasErrors ? 'alert-warning' : 'alert-success' ?>">
                    <h5>
                        <i class="bi <?= $alertIcon ?>"></i>
                        <?= $alertTitle ?>
                    </h5>

                    <?php if ($hasImportedVotes): ?>
                        <p class="mb-0">
                            <strong><?= $totale ?> voti</strong> (Tipo: <strong><?= htmlspecialchars($tipoVoto) ?></strong>) sono stati importati nel sistema e sono pronti per la pubblicazione da Gestione Voti.
                            <?php if ($hasErrors): ?>
                                <br>
                                Sono stati segnalati <?= count($errori) ?> errori: controlla l'elenco qui sotto per capire quali voti non sono stati importati.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="mb-0">
                            <strong>Nessun voto e' stato importato.</strong>
                            <?php if ($hasErrors): ?>
                                Controlla l'elenco errori qui sotto per il dettaglio.
                            <?php else: ?>
                                Controlla selezione studenti, mapping e dati del form prima di riprovare.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!$hasErrors && !$emailSent): ?>
                        <p class="mt-2 mb-0 text-warning">
                            L'email riepilogativa non e' stata inviata: verifica la configurazione SMTP.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Statistiche Finali -->
                <div class="stats-box mb-4">
                    <h5 class="mb-3"><i class="bi bi-bar-chart"></i> Riepilogo Statistiche</h5>
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="fs-2 fw-bold"><?= $totale ?></div>
                            <div>Voti Totali</div>
                        </div>
                        <div class="col-md-3">
                            <div class="fs-2 fw-bold"><?= $sufficienti ?></div>
                            <div>Sufficienti</div>
                            <small>(<?= $percSufficienti ?>%)</small>
                        </div>
                        <div class="col-md-3">
                            <div class="fs-2 fw-bold"><?= $insufficienti ?></div>
                            <div>Insufficienti</div>
                            <small>(<?= $percInsufficienti ?>%)</small>
                        </div>
                        <div class="col-md-3">
                            <div class="fs-2 fw-bold"><?= round($mediaVoti, 2) ?></div>
                            <div>Media Classe</div>
                        </div>
                    </div>
                </div>

                <!-- Tabella Voti Importati -->
                <h6 class="mb-3">Voti Importati</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Studente</th>
                                <th class="text-center">Voto</th>
                                <th class="text-center">Percentuale</th>
                                <th class="text-center">Esito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pubblicati)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">Nessun voto importato.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pubblicati as $p): ?>
                                <?php $rowClass = $p['voto'] >= 6 ? 'grade-sufficient' : 'grade-insufficient'; ?>
                                <tr class="<?= $rowClass ?>">
                                    <td><?= htmlspecialchars($p['nome'] ?? $p['email']) ?></td>
                                    <td class="text-center"><strong><?= $p['voto'] ?></strong></td>
                                    <td class="text-center"><?= round($p['percentuale'], 1) ?>%</td>
                                    <td class="text-center">
                                        <?php if ($p['voto'] >= 6): ?>
                                            <span class="badge bg-success">Sufficiente</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Insufficiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($errori)): ?>
                    <div class="alert alert-warning mt-4">
                        <h6><i class="bi bi-exclamation-triangle"></i> Errori Riscontrati (<?= count($errori) ?>)</h6>
                        <ul class="mb-0 small">
                            <?php foreach ($errori as $errore): ?>
                                <li><?= htmlspecialchars($errore) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Azioni -->
                <div class="mt-4 d-flex justify-content-between">
                    <a href="uda_grades.php?id=<?= urlencode($udaId) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye"></i> Visualizza Voti
                    </a>
                    <div>
                        <a href="?step=select_test" class="btn btn-primary me-2">
                            <i class="bi bi-arrow-repeat"></i> Importa Altri Risultati
                        </a>
                        <a href="index.php" class="btn btn-success">
                            <i class="bi bi-house"></i> Torna alla Home
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($hasImportedVotes): ?>
            <?php if ($emailSent): ?>
                <div class="card mt-4 bg-light">
                    <div class="card-body">
                        <h6><i class="bi bi-envelope"></i> Email Inviata</h6>
                        <p class="small mb-0">
                            E' stata inviata un'email riepilogativa con:
                        </p>
                        <ul class="small mb-0">
                            <li>Elenco completo di tutti i voti importati</li>
                            <li>Statistiche della classe (media, sufficienti/insufficienti)</li>
                            <li>Dettagli del test</li>
                            <li>Eventuali errori riscontrati durante l'importazione</li>
                        </ul>
                        <p class="small text-muted mt-3 mb-0">
                            Lo stato mostra il risultato restituito da `mail()`; se l'email non arriva, verifica la configurazione SMTP e i log per capire il motivo.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card mt-4 border-warning">
                    <div class="card-body">
                        <h6><i class="bi bi-envelope-exclamation"></i> Email Non Inviata</h6>
                        <p class="small mb-0 text-warning">
                            L'email riepilogativa non e' stata consegnata. Controlla la configurazione SMTP e verifica i log email.
                        </p>
                        <p class="small text-muted mt-2 mb-0">
                            La funzione `mail()` ha restituito false, quindi il sistema non ha potuto inviare l'email. Assicurati che SMTP sia attivo e raggiungibile.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card mt-4 border-secondary bg-light">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle"></i> Email Riepilogativa</h6>
                    <p class="small mb-0 text-muted">
                        Nessuna email inviata perche' non sono stati importati voti.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
