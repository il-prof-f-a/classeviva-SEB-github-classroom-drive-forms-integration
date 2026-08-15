# Dominio didattico indipendente dai provider

## Gruppi

`GRUPPI_DIDATTICI` è l'entità interna classe-materia. Può essere creata con
solo un nome e un anno scolastico. `GRUPPI_INTEGRAZIONI` collega lo stesso
gruppo a uno o più contesti esterni (`classeviva`, `google_classroom`,
`github_classroom`) senza duplicare il gruppo.

Le UDA non puntano più a una classe provider: `UDA_GRUPPI` contiene gli
assegnamenti interni. Le facciate legacy traducono temporaneamente questi
record nel formato atteso dalle pagine esistenti.

## Studenti

`STUDENTI` contiene solo l'identificativo interno e lo stato. Ogni identità
esterna è in `STUDENTI_IDENTITA_ESTERNE`, con provider, contesto e identificativo
esterno. Membership e risorse sono separate in `GRUPPI_STUDENTI` e
`STUDENTI_RISORSE_ESTERNE`.

Nomi, cognomi ed email non vengono scritti in nessuna tabella didattica. Un
servizio di roster può restituire `display_name` soltanto in memoria, mentre le
integrazioni recuperano il nome dal provider immediatamente prima della
visualizzazione.

## Compatibilità

Le pagine ancora basate sui nomi storici (`CLASSROOM_MAPPINGS`,
`MAPPATURA_STUDENTI`, `GITHUB_ASSIGNMENT_STUDENT_MAP`) ricevono dati da gateway
di compatibilità. Questi gateway non ricreano tabelle legacy e non scrivono
identificativi provider nelle tabelle del dominio.

ClasseViva è una capability opt-in: solo le pagine che dichiarano
`REQUIRES_CLASSEVIVA=true` attivano la validazione token e il popup globale.
UDA, Google Classroom e GitHub Classroom possono quindi funzionare anche senza
autorizzazione ClasseViva.
