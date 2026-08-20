# Migrazione classi legacy verso gruppi didattici

`scripts/migrate_legacy_classes_to_groups.php` converte esclusivamente le
coppie classe/materia presenti nelle tabelle legacy `CLASSROOM_MAPPINGS` e
`CLASSI_ASSEGNATE` in:

- `GRUPPI_DIDATTICI`;
- `GRUPPI_INTEGRAZIONI` per ClasseViva e Classroom;
- `UDA_GRUPPI` per le assegnazioni UDA.

Le tabelle legacy non vengono cancellate dal servizio. Gli identificativi dei
gruppi nuovi sono deterministici; se una coppia è già presente tramite
un'integrazione ClasseViva, viene riusato il gruppo esistente anche quando ha
un identificativo storico diverso. Le risorse Classroom già assegnate a un
altro gruppo vengono segnalate come conflitti e non vengono duplicate.

## Esecuzione controllata

```text
php scripts/migrate_legacy_classes_to_groups.php --dry-run --target=local
php scripts/migrate_legacy_classes_to_groups.php --apply --target=local \
  --confirm=MIGRATE-CLASSES-TO-GROUPS
```

Per lo stage l'endpoint temporaneo deve essere caricato fuori da `public`, con
token presente solo per la durata dell'operazione, e deve eseguire in ordine:

1. backup verificato del database;
2. dry-run con conteggi, conflitti e righe saltate;
3. apply con conferma esplicita;
4. seconda pianificazione, che deve avere zero scritture;
5. rimozione delle eventuali tabelle legacy temporanee e dei file helper.

L'operazione stage del 20 agosto 2026 ha prodotto 14 gruppi, 23 integrazioni e
42 assegnazioni al primo passaggio; il secondo passaggio ha prodotto zero
inserimenti. È stato segnalato un solo conflitto Classroom. Lo stato finale
verificato online è di 15 gruppi, 26 integrazioni e 43 assegnazioni; le tabelle
legacy temporanee risultano assenti.
