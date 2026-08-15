# Migrazione esplicita delle tabelle legacy

La migrazione elimina fisicamente le vecchie tabelle di classi, mapping e
associazioni GitHub. I dati didattici presenti in queste tabelle non vengono
convertiti: il modello provider-neutral usa `GRUPPI_DIDATTICI`,
`GRUPPI_INTEGRAZIONI`, `UDA_GRUPPI`, `STUDENTI` e le tabelle delle identità.

Lo script non viene eseguito automaticamente all'avvio e non deve essere
lasciato pubblicato sul server.

## Locale

```powershell
docker compose run --rm -T --entrypoint php app scripts/migrate_legacy_tables.php --dry-run --target=local
docker compose run --rm -T --entrypoint php app scripts/migrate_legacy_tables.php --apply --target=local --confirm=REMOVE-LEGACY-TABLES
```

In modalità CLI viene prodotto un report JSON in `storage/reports/`.

## Stage

1. Verificare il backup del database e caricare temporaneamente lo script in
   una posizione non indicizzata, oppure in `public/` con un nome casuale.
2. Creare sullo stage `config/.stage_migration_token` con un token casuale;
   il file è ignorato da Git e non deve essere incluso nel deploy pubblico.
3. Eseguire prima il dry-run con `target=staging`.
4. Eseguire l'azione `apply` solo con una richiesta POST contenente:
   `action=apply`, `target=staging`, `confirm=REMOVE-LEGACY-TABLES` e il
   token. Il token non va inserito nell'URL.
5. Salvare il JSON restituito localmente come report dell'operazione.
6. Eliminare immediatamente script e `config/.stage_migration_token` dallo
   stage, quindi verificare i log e l'avvio dell'applicazione.

L'ambiente `production` viene rifiutato anche se viene indicato come target.
La procedura non esegue connessioni FTP e non modifica automaticamente alcun
ambiente remoto.

## Ripristino locale da dump JSON legacy

Per ricostruire il database Docker locale usando un dump storico è disponibile
`scripts/restore_legacy_json.php`. Il comando rifiuta ogni host/database diverso
da `db`/`uda_system` e non è una procedura di deploy.

Prima creare un backup SQL del volume locale, poi simulare l'operazione:

```powershell
docker compose exec -T db mysqldump --single-transaction --no-tablespaces `
  -uuda_user -puda_local_password uda_system `
  > database/backup/local-before-legacy-restore-<timestamp>.sql

docker compose exec -T app php scripts/restore_legacy_json.php `
  --dry-run --source=/percorso/nel/container/dump.json `
  --source-email=<email-sorgente> --target-email=<email-locale>
```

L'applicazione effettiva è intenzionalmente esplicita e sostituisce i dati
didattici del solo database locale:

```powershell
docker compose exec -T app php scripts/restore_legacy_json.php `
  --apply --replace --confirm=RESTORE-LOCAL-DATABASE `
  --source=/percorso/nel/container/dump.json `
  --source-email=<email-sorgente> --target-email=<email-locale>
```

Ogni esecuzione produce un report in `storage/reports/`, compresi i
collegamenti legacy riallineati, i conflitti saltati e gli eventuali studenti
orfani rimossi. Il dump JSON e il report restano esclusi dalla distribuzione
pubblica.
