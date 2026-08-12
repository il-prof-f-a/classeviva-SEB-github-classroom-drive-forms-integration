# Database Abstraction Layer

Questa cartella contiene il Database Abstraction Layer per il sistema UDA.

## Struttura

```
Database/
├── DatabaseAdapterInterface.php    # Interface per tutti gli adapter
├── ExcelDatabaseAdapter.php        # Adapter per Excel locale
├── GoogleSheetsDatabaseAdapter.php # Adapter per Google Sheets
├── SQLiteDatabaseAdapter.php       # Adapter per SQLite
├── DatabaseFactory.php             # Factory per creare adapter
├── DatabaseInitializer.php         # Utility per manutenzione
└── README.md                       # Questo file
```

## Componenti

### DatabaseAdapterInterface
Interface che definisce il contratto comune per tutti gli adapter di database.

**Metodi principali:**
- `findAll(string $sheetName): array`
- `findWhere(string $sheetName, array $where): array`
- `insertRow(string $sheetName, array $data): bool`
- `updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool`
- `deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool`
- `initialize(): array`
- `validate(): array`
- `repair(): array`

### ExcelDatabaseAdapter
Implementazione per file Excel locali (.xlsx).

**Dipendenze:**
- `phpoffice/phpspreadsheet`
- `App\Core\DatabaseManager` (wrappato)

**Caratteristiche:**
- Legge/scrive file .xlsx locali
- Backup automatici
- Auto-creazione fogli mancanti
- Riparazione struttura

### GoogleSheetsDatabaseAdapter
Implementazione per Google Sheets online.

**Dipendenze:**
- `google/apiclient`
- Credenziali Google (Service Account o OAuth2)

**Caratteristiche:**
- Accesso a Google Sheets via API v4
- Backup tramite copia foglio
- Formattazione automatica header
- Gestione permessi

### SQLiteDatabaseAdapter
Implementazione per database SQLite locale.

**Dipendenze:**
- PDO SQLite (incluso in PHP)
- SQLite 3.7+

**Caratteristiche:**
- Performance elevate con indici SQL
- Transazioni ACID per integrità dati
- Query SQL native
- Modalità WAL per accessi concorrenti
- Backup atomico del file .db
- PRAGMA ottimizzazioni (cache, journal mode)

### DatabaseFactory
Factory per creare l'adapter corretto basandosi sulla configurazione.

**Metodi:**
- `create(array $config): DatabaseAdapterInterface`
- `createWithInitialization(array $config, bool $autoInitialize = true): DatabaseAdapterInterface`
- `isSupported(string $dbType): bool`
- `getSupportedTypes(): array`

**Tipi supportati:**
- `excel` - File Excel locale (.xlsx)
- `google_sheets` o `sheets` - Google Sheets online
- `sqlite` o `sqlite3` - Database SQLite locale

### DatabaseInitializer
Utility per operazioni di manutenzione del database.

**Metodi:**
- `initialize(bool $createBackup = false): array`
- `validate(): array`
- `repair(bool $createBackup = true): array`
- `checkAndRepair(bool $autoRepair = true, bool $autoInitialize = false): array`
- `getHealthReport(): array`

## Uso Base

```php
<?php

use App\Core\Database\DatabaseFactory;

// Carica configurazione
$config = require 'bootstrap.php';

// Crea adapter
$db = DatabaseFactory::createWithInitialization($config, true);

// Usa adapter
$udas = $db->findAll('UDA_ANAGRAFICA');
```

## Documentazione Completa

- **Database Abstraction Layer**: `/docs/DATABASE_ABSTRACTION.md`
- **SQLite Setup e Guida**: `/docs/SQLITE_SETUP.md`
- **Google Sheets Setup**: Vedi `DATABASE_ABSTRACTION.md`

## Script Utility

- `/scripts/test_database_adapter.php` - Test del sistema
- `/scripts/db_maintenance.php` - Manutenzione database
- `/scripts/init_sqlite_database.php` - Inizializza database SQLite vuoto
- `/scripts/migrate_to_sqlite.php` - Migra dati da Excel/Sheets a SQLite

## Estensione

Per aggiungere un nuovo tipo di database:

1. Crea nuova classe che implementa `DatabaseAdapterInterface`
2. Aggiungi case in `DatabaseFactory::create()`
3. Aggiorna configurazione in `config/config.example.yaml`
4. Aggiorna documentazione

Esempio:

```php
<?php

namespace App\Core\Database;

class MySQLDatabaseAdapter implements DatabaseAdapterInterface
{
    // Implementa metodi interface
}
```

Poi in `DatabaseFactory`:

```php
case 'mysql':
    return new MySQLDatabaseAdapter($config);
```
