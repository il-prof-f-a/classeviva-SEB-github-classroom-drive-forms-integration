# Persistenza SQL

La piattaforma usa esclusivamente adapter SQL con lo stesso contratto:

- `SQLiteDatabaseAdapter` per sviluppo locale e installazioni leggere;
- `MySQLDatabaseAdapter` per ambienti condivisi o di preproduzione/produzione;
- `DatabaseFactory` per selezionare il backend dalla configurazione;
- `SchemaMigrationRunner` per applicare automaticamente lo schema versionato;
- `TeachingDomainReset` per il reset controllato del dominio didattico.

## Contratto

`DatabaseAdapterInterface` espone lettura, inserimento, aggiornamento,
cancellazione, validazione e manutenzione delle tabelle. Le pagine applicative
non dipendono dal motore sottostante.

```php
$config = require 'bootstrap.php';
$db = DatabaseFactory::createWithInitialization($config, true);
$udas = $db->findAll('UDA_ANAGRAFICA');
```

## Backend supportati

`DB_TYPE=sqlite` richiede un percorso file (`DB_SQLITE_FILE`); `DB_TYPE=mysql`
richiede host, porta, database, utente e password. Non sono supportati Excel o
Google Sheets come database: i fogli restano solo formati di import/export e
template tramite `SpreadsheetFileService`.

Ogni avvio esegue le migrazioni idempotenti e crea gli indici necessari. Il
reset del dominio (`scripts/reset_teaching_domain.php`) preserva utenti,
integrazioni e cataloghi di riferimento e richiede una conferma esplicita.

## Modello provider-neutral

`GRUPPI_DIDATTICI` e `STUDENTI` sono entità interne. I collegamenti a
ClasseViva, Google Classroom e GitHub Classroom sono registrati rispettivamente
in `GRUPPI_INTEGRAZIONI` e `STUDENTI_IDENTITA_ESTERNE`; nomi ed email degli
studenti non vengono persistiti. Le interfacce legacy ricevono una facciata in
memoria e risolvono i nomi appena prima della visualizzazione.
