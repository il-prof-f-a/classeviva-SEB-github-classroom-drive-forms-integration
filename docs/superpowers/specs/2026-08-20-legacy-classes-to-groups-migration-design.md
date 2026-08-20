# Migrazione idempotente delle classi legacy verso i gruppi didattici

## Obiettivo

Ricostruire sul database staging il dominio canonico dei gruppi didattici a
partire dalle sole tabelle legacy che descrivono classi-materie:
`CLASSI`, `CLASSROOM_MAPPINGS` e `CLASSI_ASSEGNATE`. La procedura deve poter
essere simulata su una copia locale del dump staging, ripetuta senza creare
duplicati e applicata online con report verificabile.

## Ambito e vincoli

- sorgente dati: `CLASSI`, `CLASSROOM_MAPPINGS`, `CLASSI_ASSEGNATE`;
- destinazioni: `GRUPPI_DIDATTICI`, `GRUPPI_INTEGRAZIONI`, `UDA_GRUPPI`;
- non vengono cancellate o aggiornate le tabelle legacy;
- non vengono migrati in questa attività studenti, voti, rubriche o GitHub;
- gli ID gruppo sono deterministici sulla coppia proprietario/classe/materia;
- tutte le operazioni dati sono limitate a `id_utente` della riga sorgente;
- l’applicazione usa una transazione e interrompe il commit su errore;
- il report non contiene password, token o dati anagrafici non necessari.

## Regole di trasformazione

1. Le coppie distinte `(id_utente, id_classe_cv, id_materia_cv)` sono raccolte
   dall’unione di mapping Classroom e assegnazioni UDA.
2. Per ogni coppia viene usato l’ID
   `GRP_LEGACY_` + prefisso SHA-256 di `id_utente|id_classe_cv|id_materia_cv`.
3. Il gruppo viene creato solo se l’ID non esiste; se esiste viene riusato e
   i dati descrittivi vuoti possono essere completati dai dati legacy.
4. Ogni coppia riceve una integrazione `classeviva` con
   `external_context_id = id_classe_cv` e
   `external_subject_id = id_materia_cv`.
5. Ogni mapping Classroom valido viene collegato al gruppo corrispondente.
   Se lo stesso corso Classroom compare per più gruppi, il primo collegamento
   canonico viene mantenuto e gli altri vengono riportati come conflitti,
   senza duplicare la risorsa esterna.
6. Ogni riga `CLASSI_ASSEGNATE` diventa una riga `UDA_GRUPPI`. La chiave
   logica `(id_utente, id_uda, id_gruppo)` impedisce duplicati; date, stato e
   note vengono mantenuti.
7. Righe prive degli identificativi minimi vengono saltate e indicate nel
   report, senza inventare valori.

## Interfaccia operativa

Lo script dedicato espone due modalità:

- `--dry-run`: legge e calcola il piano senza scrivere;
- `--apply --confirm=...`: esegue il piano in transazione.

In staging l’esecuzione avviene tramite file temporaneo fuori da `public/`,
autenticato con token in header, dopo un backup e un dry-run online. Il file
temporaneo e il token vengono rimossi e verificati assenti al termine.

## Verifica e criteri di successo

- test unitari della pianificazione: 14 coppie attese dal dump, ID stabili,
  righe incomplete saltate e conflitto Classroom non duplicato;
- test di integrazione locale sul dump: creazione dei gruppi, integrazioni e
  assegnazioni UDA con conteggi attesi;
- seconda esecuzione locale: zero nuove righe e conteggi invariati;
- dry-run online: stato e conteggi coerenti con il dump aggiornato;
- applicazione online: transazione completata, conteggi post-migrazione
  verificati e tabelle legacy ancora presenti.

## Fuori ambito

La migrazione degli studenti e delle loro identità esterne resta una fase
separata: questa procedura non crea PII persistente e non modifica le righe di
`STUDENTI`, `MAPPATURA_STUDENTI`, `VOTI` o delle rubriche.
