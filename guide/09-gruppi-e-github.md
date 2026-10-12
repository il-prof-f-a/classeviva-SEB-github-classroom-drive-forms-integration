# Gruppi didattici e GitHub

*Capitolo 09 · destinatari, roster, repository e valutazione*

Queste pagine collegano una UDA a gruppi didattici, contesti ClasseViva/Google Classroom e repository GitHub. Contengono potenzialmente nomi, identificativi, username e URL personali: la guida descrive i campi senza riprodurre dati live.

## Gestione dei gruppi didattici

La pagina **Gruppi didattici** consente di creare o filtrare gruppi usando nome, classe, materia, anno e descrizione. Ogni scheda contiene inoltre lo stato Attivo/Disattivo e i selettori dei contesti ClasseViva e Google Classroom.

La scheda di un gruppo permette di:

1. verificare il contesto scolastico e il provider collegato;
2. salvare le modifiche del gruppo;
3. aprire la mappatura degli studenti;
4. controllare quanti studenti restano da mappare.

Non confondere il gruppo didattico con l’UDA: il gruppo è un destinatario riutilizzabile, mentre l’UDA decide quali contenuti e attività associare.

## Mappatura degli studenti

La pagina di mappatura accosta un’identità proveniente da ClasseViva a uno username GitHub. È un’operazione ad alto impatto: un abbinamento errato può assegnare repository o valutazioni allo studente sbagliato.

Prima di salvare:

- verifica la classe e la materia selezionate;
- controlla ogni corrispondenza usando un identificativo interno autorizzato;
- non copiare la tabella in screenshot, issue, README o chat pubbliche;
- usa account GitHub coerenti con il flusso scolastico;
- ricontrolla il conteggio degli studenti non mappati.

## Template e assignment GitHub

La pagina **Template GitHub** permette di registrare nome, URL, linguaggio, categoria, visibilità, descrizione e note del template. I template devono rispettare le regole indicate dall’applicazione; per un repository pubblico non inserire credenziali, roster o file di configurazione personali.

La pagina **Gestione Assignment GitHub** riepiloga UDA, gruppo, organizzazione, studenti accettati/in attesa, data di creazione e repository. Il riepilogo è utile per controllare lo stato senza aprire ogni repository.

La creazione dell’assignment può generare repository, inviti e scadenze su GitHub. Prima dell’invio controlla:

| Verifica | Perché |
| --- | --- |
| Organizzazione | Determina chi possiede e vede i repository. |
| Template | Decide quali file vengono copiati agli studenti. |
| Gruppo | Determina i destinatari. |
| Scadenza | Influenza consegna e valutazione. |
| Stato accettazione | Distingue inviti ancora pendenti da repository disponibili. |

## Revisione e nomi

La pagina di revisione può mostrare nomi, repository e username oppure nasconderli per una revisione cieca. Selezionare la modalità con nomi visibili espone dati personali all’operatore: usa la revisione cieca quando è sufficiente e apri i repository solo per il tempo necessario.

Il salvataggio dei voti e l’apertura dei link repository sono azioni separate. Verifica sempre studente, repository e tipo di valutazione prima di salvare.

## Pagine esplorate

| Pagina | Funzione |
| --- | --- |
| [Gruppi didattici](../public/teaching_groups.php) | Gruppi, contesti provider e accesso alla mappatura. |
| [Mappatura studenti](../public/teaching_groups.php?tab=students) | Associazione tra identità ClasseViva e username GitHub. |
| [Assignment GitHub](../public/github_assignments.php) | Riepilogo di assignment, gruppi, organizzazioni e accettazioni. |
| [Revisione assignment](../public/github_assignment_review.php) | Valutazione dei repository con modalità nominativa o cieca. |
| [Template repository](../public/github_repo_templates.php) | Catalogo dei template selezionabili. |

I link aprono pagine che possono richiedere identificativi presenti nella sessione. Se una pagina risponde con un elenco vuoto, controlla prima gruppo, UDA e autorizzazione GitHub: non è necessariamente un errore del collegamento.

- [← Integrazioni](08-integrazioni.md)
- [Stato e debug →](10-stato-e-debug.md)
