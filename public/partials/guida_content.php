<?php
/**
 * Contenuto riutilizzabile della guida portale
 *
 * Parametri:
 * - $section: 'intro', 'setup', 'workflow', 'full' (default: 'full')
 * - $containerClass: classe CSS per il container (default: 'container-fluid')
 * - $assetsPath: percorso base per gli assets (default: 'assets', usare 'public/assets' dalla root)
 */

$section = $section ?? 'full';
$containerClass = $containerClass ?? 'container-fluid';
$assetsPath = $assetsPath ?? 'assets'; // Percorso base per gli assets (può essere sovrascritto)
$showIntro = in_array($section, ['intro', 'full']);
$showSetup = in_array($section, ['setup', 'full']);
$showWorkflow = in_array($section, ['workflow', 'full']);
?>

<div class="<?= htmlspecialchars($containerClass) ?>">
    <?php if ($showIntro): ?>
    <!-- Introduzione -->
    <div class="alert alert-info mb-4">
        <h5 class="alert-heading"><i class="bi bi-info-circle"></i> Dal Contenuto al Voto: Automatizza l'Intera UDA</h5>
        <div class="text-center my-3">
            <img src="<?= htmlspecialchars($assetsPath) ?>/img/uda-system.webp" alt="Flusso Sistema UDA - Dal Contenuto al Voto" class="img-fluid rounded shadow" style="max-width: 100%; height: auto;">
        </div>
        <p class="mb-2">
            <strong>Il problema: troppi strumenti, dati duplicati</strong><br>
            Chi lavora a scuola lo sa bene: progettare una UDA, preparare materiali, creare test, raccogliere risultati,
            inserire voti sul registro elettronico e restituire feedback agli studenti richiede <strong>tempo, energie e
            una grande quantità di "copia e incolla"</strong> tra strumenti diversi:
        </p>
        <ul class="mb-2 small">
            <li><strong>Google Classroom</strong> per classi virtuali e compiti</li>
            <li><strong>Google Moduli</strong> per test, verifiche e sondaggi</li>
            <li><strong>Registro elettronico</strong> (ClasseViva e simili) per voti e comunicazioni</li>
            <li><strong>GitHub Classroom</strong> per attività di programmazione e progetti</li>
            <li><strong>Secure Exams Browser (SEB)</strong> per prove in ambiente protetto</li>
            <li><strong>Kahoot</strong> e altre piattaforme per quiz e attività interattive</li>
        </ul>
        <p class="mb-2">
            <strong>Il problema</strong> è che questi strumenti non "parlano" tra loro in modo naturale. Le stesse informazioni
            vengono inserite più volte: obiettivi, domande, voti, rubriche, materiali. Il risultato è una <strong>perdita di tempo</strong>
            e, spesso, una maggiore probabilità di errore.
        </p>
        <p class="mb-2">
            <strong>La soluzione: il "collante" che integra tutto</strong><br>
            Questa applicazione è nata proprio da qui: <strong>automatizzare il flusso dei dati dall'inizio alla fine</strong>,
            in modo che l'insegnante possa <strong>concentrarsi sull'aspetto più importante del proprio lavoro</strong>:
            l'analisi del percorso degli studenti e la <strong>qualità della didattica</strong>.
        </p>
        <div class="bg-white rounded p-3 small mb-2">
            <strong>📚 Materiali</strong> → <strong>🎯 Obiettivi</strong> → <strong>📝 Test AI</strong> →
            <strong>📊 Valutazioni</strong> → <strong>📖 Registro Elettronico</strong>
        </div>
        <p class="mb-2">
            <strong>Come funziona:</strong><br>
            La piattaforma si collega tramite API ai principali strumenti che utilizziamo ogni giorno e trasforma
            la UDA in un vero <strong>flusso di lavoro automatizzato</strong>:
        </p>
        <ul class="small mb-2">
            <li>✅ <strong>Automatizzato</strong> dove possibile (pubblicazione materiali, calcolo voti, sincronizzazione)</li>
            <li>🎯 <strong>Guidato</strong> dove serve il controllo del docente (valutazioni orali, correzioni)</li>
            <li>🔍 <strong>Trasparente e tracciabile</strong> in ogni passaggio (marker voti, cronologia modifiche)</li>
        </ul>
        <div class="alert alert-success small mb-0">
            <strong>📊 Vantaggi didattici concreti:</strong>
            <ul class="mb-0">
                <li><strong>Più tempo per l'analisi:</strong> Meno tempo a spostare dati = più tempo per capire come lavorano gli studenti</li>
                <li><strong>Valutazione più coerente:</strong> Obiettivi, domande, rubriche e voti fanno parte di un unico sistema tracciabile</li>
                <li><strong>Feedback più rapido:</strong> Con voti che passano automaticamente dal test al registro, puoi restituire feedback in tempi più brevi</li>
                <li><strong>Riduzione degli errori:</strong> Meno inserimenti manuali = meno possibilità di sbagliare voti, nomi o associamenti</li>
                <li><strong>Maggiore flessibilità:</strong> Creando e pubblicando rapidamente nuovi test (Google Moduli o SEB) e nuove attività,
                    è più semplice adattare la UDA in corso sulla base dei bisogni reali della classe</li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showSetup): ?>
    <!-- Configurazione Iniziale -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-gear-fill"></i> 1. Setup Integrazioni</h5>
        </div>
        <div class="card-body">
            <p><strong>Prima di iniziare</strong>, configura le integrazioni con le piattaforme esterne tramite la <a href="user_integrations.php">pagina Integrazioni</a>:</p>
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-success"><i class="bi bi-1-circle-fill"></i> Obbligatorie</h6>
                    <ul class="small">
                        <li>
                            <strong>Profilo e Scuola</strong>: Nome docente, scuola, dominio email
                            <br><a href="user_integrations.php#profile-section" class="text-muted small">→ Configura profilo</a>
                        </li>
                        <li>
                            <strong>Classe Viva</strong>: Token REST API auto-rinnovabile
                            <br><a href="user_integrations.php#classeviva-section" class="text-muted small">→ Configura ClasseViva</a>
                        </li>
                        <li>
                            <strong>Google OAuth</strong>: Autorizzazione Drive, Classroom, Forms
                            <br><a href="google_auth.php" class="text-muted small">→ Autorizza Google</a>
                        </li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="text-info"><i class="bi bi-star"></i> Opzionali</h6>
                    <ul class="small">
                        <li>
                            <strong>GitHub Classroom</strong>: Per progetti programmazione
                            <br><a href="user_integrations.php#github-section" class="text-muted small">→ Configura GitHub</a>
                        </li>
                        <li>
                            <strong>Email/SMTP</strong>: Notifiche automatiche voti
                            <br><a href="user_integrations.php#email-section" class="text-muted small">→ Configura email</a>
                        </li>
                        <li>
                            <strong>AI (Claude/GPT-4)</strong>: Generazione contenuti
                            <br><a href="user_integrations.php#ai-section" class="text-muted small">→ Configura AI</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="d-grid mt-3">
                <a href="user_integrations.php" class="btn btn-success">
                    <i class="bi bi-gear"></i> Configura Integrazioni
                </a>
            </div>
            <div class="alert alert-warning small mt-3 mb-0">
                <h6 class="mb-2"><i class="bi bi-exclamation-triangle"></i> Test iniziali consigliati (prime 4 settimane)</h6>
                <p class="mb-2">
                    Il sistema e' in fase di test. Nelle prime 4 settimane di utilizzo prova le integrazioni insieme agli studenti
                    e <strong>ricontrolla sempre</strong> tutte le azioni svolte dalla piattaforma, soprattutto all'inizio.
                    <strong>Ricontrolla sempre.</strong>
                </p>
                <ul class="mb-2">
                    <li><strong>Test prima delle automazioni:</strong> esegui i test prima di attivare operazioni automatiche
                        su ClasseViva e Google Classroom. Sono strumenti di valutazione con valore legale e il proprietario
                        non si assume alcuna responsabilita'.
                    </li>
                    <li><strong>ClasseViva puo' essere personalizzato</strong> dalla scuola e puo' comportarsi in modo diverso
                        durante quadrimestri, trimestri, pentamestri o bimestri: ricontrolla sempre i risultati.
                    </li>
                    <li>Se la piattaforma non funziona correttamente, contatta lo sviluppatore per supporto tecnico e per migliorare la piattaforma.</li>
                </ul>
                <a href="test_api_integrations.php" class="btn btn-warning btn-sm">
                    <i class="bi bi-plug"></i> Apri Test Integrazioni API
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showWorkflow): ?>
    <!-- Workflow Completo -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-diagram-3-fill"></i> 2. Flusso di Lavoro Completo</h5>
        </div>
        <div class="card-body">
            <div class="accordion" id="workflowAccordion">
                <!-- Step 1: Associazione Studenti -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                            <strong>A. Associazione Studenti (Classroom ↔ ClasseViva)</strong>
                        </button>
                    </h2>
                    <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Crea le mappature tra corsi Google Classroom e ClasseViva (Classe + Materia) e associa gli studenti:</p>
                            <ul class="small">
                                <li>
                                    <strong>Mappa corsi Google Classroom → ClasseViva</strong>: Associa ogni corso di Google Classroom
                                    alla corrispondente Classe e Materia su ClasseViva
                                </li>
                                <li>
                                    <strong>Associa studenti</strong>: Mappatura automatica (match tramite email) o manuale per ogni corso
                                </li>
                                <li>
                                    <strong>Un'unica interfaccia</strong>: Entrambe le operazioni sono gestite dalla
                                    <a href="map_classes.php" class="text-primary">pagina di mappatura classi</a>
                                </li>
                            </ul>
                            <div class="alert alert-warning small mb-0">
                                <i class="bi bi-exclamation-triangle"></i> <strong>Importante:</strong> Questo passaggio è
                                <strong>obbligatorio</strong> per pubblicare voti automaticamente sul registro elettronico.
                                Senza la mappatura, i voti non possono essere associati agli studenti corretti.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Creazione UDA -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                            <strong>B. Creazione UDA (Unità di Apprendimento)</strong>
                        </button>
                    </h2>
                    <div id="step2" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Crea una nuova UDA usando il <a href="uda_create.php"><strong>Wizard guidato</strong></a> in 7 step:</p>
                            <ol class="small">
                                <li><strong>Informazioni generali</strong>: Titolo, argomento, disciplina, metodologie didattiche (flipped classroom, PBL, inquiry-based...)</li>
                                <li><strong>Classi assegnate</strong>: Seleziona da elenco ClasseViva (classe + materia)</li>
                                <li><strong>Materiali didattici</strong>: Drive Picker integrato o link esterni
                                    <br><a href="https://drive.google.com" target="_blank" class="text-muted small">→ Google Drive</a>
                                </li>
                                <li><strong>Obiettivi didattici/disciplinari</strong>: Selezione multipla o import massivo (Excel/CSV)
                                    <br><a href="obiettivi_import.php?action=download_template" class="text-muted small">→ Scarica template obiettivi Excel</a>
                                </li>
                                <li><strong>Domande per interrogazioni</strong>: Import massivo o <strong>generazione AI automatica</strong>
                                    <br><a href="download_template_domande.php?format=json" class="text-muted small">→ Template JSON</a>
                                    | <a href="download_template_domande.php?format=csv" class="text-muted small">CSV</a>
                                    | <a href="download_template_domande.php?format=xlsx" class="text-muted small">Excel</a>
                                </li>
                                <li><strong>Test e valutazioni</strong>: Crea modulo Google precompilato con le domande importate</li>
                                <li><strong>Riepilogo e salvataggio</strong>: Verifica tutti i dati prima della pubblicazione</li>
                            </ol>
                            <div class="bg-light rounded p-2 small mb-2">
                                <i class="bi bi-robot"></i> <strong>AI Generativa:</strong> Claude o GPT-4 possono generare automaticamente:
                                <ul class="mb-0 mt-1">
                                    <li>Domande multiple/aperte basate sui materiali</li>
                                    <li>Obiettivi didattici allineati alla tassonomia di Bloom</li>
                                    <li>Rubriche di valutazione personalizzate</li>
                                </ul>
                            </div>
                            <a href="uda_create.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-circle"></i> Avvia Wizard UDA
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Test e Pubblicazione -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                            <strong>C. Creazione Test Automatizzati</strong>
                        </button>
                    </h2>
                    <div id="step3" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Genera test <strong>automaticamente con AI</strong> dal paniere di domande dell'UDA e pubblicali su:</p>
                            <ul>
                                <li>
                                    <strong><a href="https://docs.google.com/forms" target="_blank">Google Moduli</a></strong>:
                                    Con supporto <strong><a href="https://docs.google.com/presentation/d/e/2PACX-1vQUA4bGHzy6tjqhNrASlRVpyluJtuHxKo-moY4yBki3Tp5ueYuZWyklc0e6y96I9F5xDvX4dBIUNp0k/pub?start=true&loop=false&delayms=3000" target="_blank">CBM</a></strong> (Certainty-Based Marking) facoltativo.
                                    I link possono essere esportati come <strong>file Secure Exam Browser (.seb)</strong> per esami protetti.
                                </li>
                                <li><strong><a href="https://kahoot.com" target="_blank">Kahoot</a></strong>: Quiz interattivi per la classe</li>
                                <li><strong><a href="https://www.socrative.com" target="_blank">Socrative</a></strong>: Valutazioni formative rapide</li>
                                <li>
                                    <strong><a href="https://safeexambrowser.org" target="_blank">Secure Exam Browser (SEB)</a></strong>:
                                    Applicazione che blocca l'accesso ad altre risorse durante gli esami online.
                                    Il sistema genera automaticamente file .seb con il link al test incorporato.
                                    <a href="https://safeexambrowser.org/about_overview.html" target="_blank" class="small">
                                        <i class="bi bi-box-arrow-up-right"></i> Documentazione ufficiale SEB
                                    </a>
                                </li>
                            </ul>
                            <div class="bg-light rounded p-2 small mt-2">
                                <i class="bi bi-robot"></i> <strong>Generazione AI:</strong> Le domande possono essere create automaticamente
                                da Claude o GPT-4 analizzando i materiali caricati e gli obiettivi dell'UDA. Il sistema genera domande
                                calibrate sulla tassonomia di Bloom e ottimizzate per il tipo di test scelto.
                            </div>
                            <p class="small text-muted mb-0 mt-2">
                                <i class="bi bi-info-circle"></i> I wizard per la creazione e pubblicazione dei test sono accessibili
                                dalla pagina di visualizzazione di ogni singola UDA.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Pubblicazione Materiali -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step4">
                            <strong>D. Pubblicazione su Classroom e GitHub</strong>
                        </button>
                    </h2>
                    <div id="step4" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Pubblica automaticamente:</p>
                            <ul>
                                <li>
                                    <strong>Materiali UDA</strong> su <a href="https://classroom.google.com" target="_blank">Google Classroom</a>
                                    (con topic dedicato per argomento)
                                </li>
                                <li><strong>Test e compiti</strong> con deadline configurabile (link Google Moduli o file .seb)</li>
                                <li>
                                    <strong>Repository <a href="https://classroom.github.com" target="_blank">GitHub Classroom</a></strong>:
                                    Creazione guidata con template, invitation link e tracking automatico.
                                    <br>
                                    <a href="https://docs.github.com/en/education/manage-coursework-with-github-classroom" target="_blank" class="small">
                                        <i class="bi bi-box-arrow-up-right"></i> Documentazione GitHub Classroom
                                    </a>
                                    |
                                    <a href="https://docs.google.com/presentation/d/e/2PACX-1vRcM5zErT6R7xHib-49h1P81K14NGVQwSbVIjUtNEjSz4trJMmd3Yqbg8Y9GMicR1trECwrLQiuEyoN/pub?start=false&loop=false&delayms=3000&slide=id.p1" target="_blank" class="small">
                                        <i class="bi bi-box-arrow-up-right"></i> GitHub Classroom come strumento didattico (guida pratica per docenti)
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Import Voti -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step5">
                            <strong>E. Import Voti dalle Piattaforme</strong>
                        </button>
                    </h2>
                    <div id="step5" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Importa automaticamente i voti da:</p>
                            <ul>
                                <li>
                                    <strong><a href="https://classroom.google.com" target="_blank">Google Classroom</a></strong>:
                                    Compiti consegnati e valutati
                                </li>
                                <li>
                                    <strong><a href="https://docs.google.com/forms" target="_blank">Google Moduli</a></strong>:
                                    Con calcolo automatico e supporto <strong><a href="https://docs.google.com/presentation/d/e/2PACX-1vQUA4bGHzy6tjqhNrASlRVpyluJtuHxKo-moY4yBki3Tp5ueYuZWyklc0e6y96I9F5xDvX4dBIUNp0k/pub?start=true&loop=false&delayms=3000" target="_blank">CBM</a></strong> (Certainty-Based Marking)
                                </li>
                                <li>
                                    <strong><a href="https://kahoot.com" target="_blank">Kahoot</a></strong>:
                                    Export risultati con conversione voto automatica
                                </li>
                                <li>
                                    <strong><a href="https://classroom.github.com" target="_blank">GitHub Classroom</a></strong>:
                                    Analisi commit, LOC (Lines of Code), griglia valutazione Git workflow
                                </li>
                            </ul>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-magic"></i> Il sistema converte automaticamente i punteggi in voti 1-10 secondo le soglie configurabili.
                                Le funzioni di import sono accessibili dalla pagina di visualizzazione di ogni singola UDA.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Valutazioni -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step6">
                            <strong>F. Valutazioni con Griglie e +/-</strong>
                        </button>
                    </h2>
                    <div id="step6" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <h6>Valutazione Orale con Rubrica</h6>
                            <ul>
                                <li>Griglia con <strong>indicatori fissi</strong> (Esposizione, Linguaggio, Organizzazione)</li>
                                <li><strong>Indicatori personalizzati</strong> basati sugli argomenti UDA</li>
                                <li><strong>5 livelli</strong> di valutazione (Gravemente Insufficiente → Eccellente)</li>
                                <li>Calcolo automatico voto finale e pubblicazione su Classe Viva</li>
                                <li>Paniere domande predefinite dall'UDA per guidare l'interrogazione</li>
                            </ul>
                            <h6 class="mt-3">Valutazione Laboratorio (+/-)</h6>
                            <ul>
                                <li>Registra <strong>evidenze positive (+) e negative (-)</strong> in tempo reale durante le attività pratiche</li>
                                <li><strong>Finestra modificabile di 2 ore</strong>: Puoi correggere evidenze inserite per errore</li>
                                <li>Calcolo automatico voto statistico finale basato sul rapporto +/-</li>
                                <li>Competenze trasversali predefinite (comunicazione, problem solving, teamwork, autonomia)</li>
                                <li>Coda automatica PLUSMINUS_QUEUE con pubblicazione schedulata su Classe Viva</li>
                            </ul>
                            <p class="small text-muted mb-2 mt-2">
                                <i class="bi bi-info-circle"></i> Le griglie di valutazione sono accessibili dalla dashboard principale
                                nella sezione "Studenti & Valutazioni".
                            </p>
                            <div class="mt-3 text-center">
                                <img src="<?= htmlspecialchars($assetsPath) ?>/img/piuomeno.webp" alt="Schermata Valutazione Laboratorio +/-" class="img-fluid rounded shadow-sm" style="max-width: 100%; height: auto;">
                                <p class="small text-muted mt-2">Esempio di interfaccia di valutazione laboratorio con sistema +/-</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 7: Pubblicazione Voti -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step7">
                            <strong>G. Revisione e Pubblicazione su Classe Viva</strong>
                        </button>
                    </h2>
                    <div id="step7" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Gestisci tutti i voti prima della pubblicazione finale su <strong><a href="https://web.spaggiari.eu" target="_blank">Classe Viva</a></strong> (registro elettronico):</p>
                            <ul>
                                <li><strong>Visualizza tutti i voti</strong> inseriti/importati per l'UDA</li>
                                <li><strong>Modifica voti</strong> se necessario (correzioni, arrotondamenti)</li>
                                <li><strong>Pubblica in batch</strong> su Classe Viva con un click tramite API REST</li>
                                <li><strong>Tracciabilità</strong>: Marker univoco <code>&lt;ID_VOTO&gt;</code> nelle note per identificare i voti della piattaforma</li>
                                <li><strong>Email di riepilogo al docente</strong>: Conferma automatica via email dei voti pubblicati sul registro (opzionale, configurabile via SMTP)</li>
                            </ul>
                            <div class="alert alert-success small mt-2 mb-2">
                                <i class="bi bi-shield-check"></i> <strong>Tracciabilità completa:</strong> Ogni voto salvato include un
                                marker univoco <code>&lt;ID_VOTO&gt;</code> nelle note ClasseViva. Questo permette di:
                                <ul class="mb-0 mt-1">
                                    <li>Verificare quali voti provengono dalla piattaforma</li>
                                    <li>Evitare duplicati durante le sincronizzazioni</li>
                                    <li>Risalire alla fonte originale (test/rubrica/laboratorio)</li>
                                </ul>
                            </div>
                            <div class="d-grid gap-2">
                                <a href="manage_grades.php" class="btn btn-primary btn-sm">
                                    <i class="bi bi-card-checklist"></i> Gestisci e Pubblica Voti
                                </a>
                                <a href="verify_grades.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-search"></i> Verifica Voti Pubblicati
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 8: Statistiche -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step8">
                            <strong>H. Tracciamento e Statistiche</strong>
                        </button>
                    </h2>
                    <div id="step8" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                        <div class="accordion-body">
                            <p>Monitora l'andamento delle UDA con strumenti di analisi e reporting:</p>
                            <ul class="small">
                                <li>
                                    <strong>Resoconto voti registro</strong>: Confronta voti piattaforma vs voti manuali
                                    <br><a href="verify_grades.php" class="text-primary small">→ verify_grades.php</a>
                                </li>
                                <li>
                                    <strong>Statistiche sommarie</strong>: Medie per classe, distribuzione voti, trend temporali
                                    <br><span class="text-muted small">Dashboard principale (grafici automatici)</span>
                                </li>
                                <li>
                                    <strong>Analisi competenze</strong>: Verifica raggiungimento obiettivi didattici per studente
                                    <br><span class="text-muted small">Report per UDA e per classe</span>
                                </li>
                                <li>
                                    <strong>Export dati</strong>: Esportazione completa per documentazione/portfolio
                                    <br><span class="text-muted small">Formati: Excel, JSON, PDF</span>
                                </li>
                                <li>
                                    <strong>Test <a href="https://docs.google.com/presentation/d/e/2PACX-1vQUA4bGHzy6tjqhNrASlRVpyluJtuHxKo-moY4yBki3Tp5ueYuZWyklc0e6y96I9F5xDvX4dBIUNp0k/pub?start=true&loop=false&delayms=3000" target="_blank">CBM</a> Analysis</strong>: Analisi accuratezza vs confidenza per ogni domanda/studente
                                    <br><span class="text-muted small">Accessibile dalla pagina di ogni test CBM</span>
                                </li>
                            </ul>
                            <a href="index.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-bar-chart"></i> Dashboard Statistiche
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Link Rapidi -->
    <div class="card border-primary">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-rocket-takeoff"></i> Inizia Subito</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <a href="user_integrations.php" class="btn btn-outline-success btn-sm w-100">
                                <i class="bi bi-gear"></i> Configura Integrazioni
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="uda_create.php" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-plus-circle"></i> Crea Nuova UDA
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="map_classes.php" class="btn btn-outline-info btn-sm w-100">
                                <i class="bi bi-people"></i> Associa Studenti
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <a href="manage_grades.php" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="bi bi-card-checklist"></i> Gestisci Voti
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="guida_portale.php" target="_blank" class="btn btn-outline-warning btn-sm w-100">
                                <i class="bi bi-book"></i> Guida Completa
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="system_status.php" class="btn btn-outline-dark btn-sm w-100">
                                <i class="bi bi-speedometer2"></i> Stato Sistema
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
