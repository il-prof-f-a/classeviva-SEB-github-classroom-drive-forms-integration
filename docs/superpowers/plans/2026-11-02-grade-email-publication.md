# Pubblicazione voti via email - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** aggiungere alla pagina di gestione voti un metodo alternativo che invii per email le valutazioni selezionate allo studente, con copia al docente, senza modificare la pubblicazione sul registro.

**Architecture:** il flusso riusa la selezione dei voti di `uda_grades.php`, risolve le email solo durante la richiesta dai roster attivi o dal template scolastico configurato e delega SMTP a `NotificationManager`. Un builder puro compone oggetto e corpo della mail, usando `VOTI.giudizio` come fonte primaria delle note specifiche del voto e `descrizione` solo come fallback storico.

**Tech Stack:** PHP 8+, PDO/database adapter provider-neutral, PHPMailer, Bootstrap 5, test PHP statici/unitari esistenti.

**Spec:** approvazione conversazionale del 2026-11-02; il messaggio non deve contenere ID interni, codici UDA, codici studente o riferimenti tecnici.

## Global Constraints

- Il pulsante “Pubblica su Registro” resta presente e invariato.
- Il nuovo flusso non imposta `VOTI.pubblicato` e non usa `MAIL_TEST_RECIPIENT` come destinatario reale.
- Le email degli studenti non vengono persistite nel database; vengono risolte in memoria dalla richiesta.
- Il pulsante resta visibile ma disattivo quando nessun voto selezionabile ha un destinatario valido.
- Le operazioni POST del nuovo flusso devono verificare CSRF e ownership tramite il database già scoped per utente.

## Review Focus

- Email mancante o non valida: riga non selezionabile e invio rifiutato anche se il POST viene alterato.
- Gruppo con sola ClasseViva: usare il template scolastico solo se dominio e nomi sono configurati; altrimenti disattivare.
- Più voti dello stesso studente: un’unica mail riepilogativa per studente.
- Note: `giudizio` prevale sempre su `descrizione`; non inserire la descrizione generale dell’UDA.
- Invio parzialmente fallito: mostrare conteggio di successo/errore senza segnare voti come pubblicati sul registro.

---

### Task 1: Messaggio email specifico per le valutazioni

**Files:**
- Create: `src/Core/GradeEmailMessageBuilder.php`
- Test: `tests/domain/grade_email_message_builder.php`

**Interfaces:**
- Produces `GradeEmailMessageBuilder::build(string $activityTitle, array $student, array $grades): array{subject:string,html:string,text:string}`.

- [ ] Scrivere il test fallente per oggetto, voto, tipo prova, data, `giudizio`, fallback `descrizione` ed esclusione degli identificativi tecnici.
- [ ] Eseguire il test e verificare il fallimento perché il builder non esiste.
- [ ] Implementare il builder con escaping HTML e testo alternativo leggibile.
- [ ] Rieseguire il test e verificare il passaggio.

### Task 2: Risoluzione runtime degli indirizzi degli studenti

**Files:**
- Create: `src/Core/GradeEmailRecipientResolver.php`
- Test: `tests/domain/grade_email_recipient_resolver.php`

**Interfaces:**
- Produces `resolve(string $groupId, string $studentId): ?array{email:string,nome:string,cognome:string,source:string}`.

- [ ] Scrivere test con roster Google Classroom, identità interna e fallback a template scolastico; verificare che il destinatario di test non venga usato.
- [ ] Eseguire il test e verificare il fallimento atteso.
- [ ] Implementare lookup scoped su integrazioni attive e identità, con cache per richiesta; priorità email reale Google Classroom, poi email generata dal profilo scolastico.
- [ ] Rieseguire il test e verificare il passaggio.

### Task 3: Invio SMTP con CC al docente

**Files:**
- Modify: `src/Core/NotificationManager.php`
- Test: `tests/public/grade_email_smtp.php`

**Interfaces:**
- Adds `sendGradesEmail(string $to, string $cc, string $activityTitle, array $student, array $grades): bool`.

- [ ] Aggiungere il test statico che verifichi PHPMailer, `addAddress`, `addCC` e il builder.
- [ ] Eseguire il test e verificare il fallimento atteso.
- [ ] Implementare l’invio usando la configurazione SMTP esistente, fallendo chiuso se destinatario/CC non sono validi.
- [ ] Rieseguire il test e verificare il passaggio.

### Task 4: UI e flusso POST in `uda_grades.php`

**Files:**
- Modify: `public/uda_grades.php`
- Modify: `tests/public/uda_grades_publish_summary.php` oppure nuovo `tests/public/uda_grades_email_publication.php`

**Interfaces:**
- Adds POST action `publish_to_email`, token CSRF, selezione per voto e raggruppamento per studente.

- [ ] Scrivere test statico per pulsante, modal, action POST, token CSRF, resolver, `giudizio`, CC e assenza di modifica a `pubblicato`.
- [ ] Eseguire il test e verificare il fallimento atteso.
- [ ] Arricchire ogni voto con stato/email runtime, mostrare il pulsante sempre ma disattivo quando non esistono destinatari validi, disabilitare le singole righe non risolvibili.
- [ ] Implementare preflight dei voti selezionati, raggruppamento per studente, invio e messaggio di riepilogo senza aggiornare lo stato registro.
- [ ] Rieseguire il test e verificare il passaggio.

### Task 5: Verifica completa

- [ ] Eseguire lint PHP sui file modificati.
- [ ] Eseguire i test mirati e la suite completa `php tests/run.php`.
- [ ] Eseguire `git diff --check` e controllare che non siano presenti segreti o file `.env` nel diff.
- [ ] Se tutte le verifiche sono verdi, creare il commit con messaggio `feat: publish grades by email`.
