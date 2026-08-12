<?php

namespace App\Core\Database;

use Exception;

/**
 * DatabaseFactory - Factory per creare l'adapter database corretto
 *
 * Analizza la configurazione e istanzia l'adapter appropriato
 * (Excel locale, Google Sheets, SQL database, ecc.)
 */
class DatabaseFactory
{
    /**
     * Crea un'istanza dell'adapter database basandosi sulla configurazione
     *
     * @param array $config Configurazione del database
     * @return DatabaseAdapterInterface Istanza dell'adapter
     * @throws Exception Se il tipo di database non è supportato o la configurazione è invalida
     */
    public static function create(array $config): DatabaseAdapterInterface
    {
        // Determina il tipo di database dalla configurazione
        $dbType = $config['database']['type'] ?? 'excel';

        switch (strtolower($dbType)) {
            case 'excel':
                return new ExcelDatabaseAdapter($config);

            case 'google_sheets':
            case 'sheets':
                return new GoogleSheetsDatabaseAdapter($config);

            case 'sqlite':
            case 'sqlite3':
                return new SQLiteDatabaseAdapter($config);

            case 'mysql':
                return new MySQLDatabaseAdapter($config);

            // Estendibile in futuro per altri tipi:
            // case 'postgresql':
            //     return new PostgreSQLDatabaseAdapter($config);
            //
            // case 'mongodb':
            //     return new MongoDBDatabaseAdapter($config);

            default:
                throw new Exception(
                    "Tipo di database '{$dbType}' non supportato. " .
                    "Tipi supportati: excel, google_sheets, sqlite, mysql"
                );
        }
    }

    /**
     * Crea un'istanza dell'adapter con auto-inizializzazione
     *
     * Crea l'adapter e verifica/inizializza il database se necessario
     *
     * @param array $config Configurazione del database
     * @param bool $autoInitialize Se true, inizializza automaticamente il database se necessario
     * @return DatabaseAdapterInterface Istanza dell'adapter
     * @throws Exception Se la creazione o l'inizializzazione fallisce
     */
    public static function createWithInitialization(array $config, bool $autoInitialize = true): DatabaseAdapterInterface
    {
        $adapter = self::create($config);

        if ($autoInitialize) {
            // Valida il database
            $validation = $adapter->validate();

            // Se ci sono errori o warnings, tenta di riparare
            if (!$validation['valid'] || !empty($validation['warnings'])) {
                try {
                    // Se il database è completamente rotto, inizializza
                    if (!$validation['valid']) {
                        $adapter->initialize();
                    } else {
                        // Altrimenti ripara solo i problemi minori
                        $adapter->repair();
                    }
                } catch (Exception $e) {
                    throw new Exception(
                        "Impossibile inizializzare/riparare il database: " . $e->getMessage()
                    );
                }
            }
        }

        // In contesto web, se c'è un utente loggato wrappiamo l'adapter
        // con UserScopedDatabaseAdapter per applicare i filtri per-utente.
        if (php_sapi_name() !== 'cli'
            && session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION['user_id'])
        ) {
            $fqcn = __NAMESPACE__ . '\\UserScopedDatabaseAdapter';
            if (class_exists($fqcn)) {
                $adapter = new $fqcn($adapter, (string)$_SESSION['user_id']);
            }
        }

        return $adapter;
    }

    /**
     * Verifica se un tipo di database è supportato
     *
     * @param string $dbType Tipo di database (excel, google_sheets, etc.)
     * @return bool True se supportato
     */
    public static function isSupported(string $dbType): bool
    {
        $supportedTypes = ['excel', 'google_sheets', 'sheets', 'sqlite', 'sqlite3', 'mysql'];
        return in_array(strtolower($dbType), $supportedTypes);
    }

    /**
     * Ritorna la lista dei tipi di database supportati
     *
     * @return array Array di tipi supportati
     */
    public static function getSupportedTypes(): array
    {
        return [
            'excel' => [
                'name' => 'Excel Locale',
                'description' => 'File Excel (.xlsx) memorizzato localmente sul server',
                'requirements' => 'phpoffice/phpspreadsheet',
                'pros' => 'Facile da usare, compatibile con Office',
                'cons' => 'Performance limitata, non scalabile'
            ],
            'google_sheets' => [
                'name' => 'Google Sheets',
                'description' => 'Foglio di calcolo Google Sheets online',
                'requirements' => 'google/apiclient, credenziali OAuth2/Service Account',
                'pros' => 'Cloud-based, collaborazione real-time, backup automatico',
                'cons' => 'Richiede connessione internet, API rate limits'
            ],
            'sqlite' => [
                'name' => 'SQLite',
                'description' => 'Database SQL leggero in un singolo file',
                'requirements' => 'PDO SQLite (incluso in PHP)',
                'pros' => 'Velocissimo, query SQL native, transazioni ACID, indici',
                'cons' => 'File binario (non editabile manualmente)'
            ],
            'mysql' => [
                'name' => 'MySQL/MariaDB',
                'description' => 'Database SQL server-based (MySQL o MariaDB)',
                'requirements' => 'PDO MySQL (pdo_mysql abilitato), server MySQL accessibile',
                'pros' => 'Scalabile, multi-utente, performance, strumenti di amministrazione standard',
                'cons' => 'Richiede un server e una configurazione aggiuntiva'
            ]
        ];
    }

    /**
     * Crea un adapter per test/sviluppo con database in memoria
     *
     * Utile per unit testing senza toccare il database reale
     *
     * @return DatabaseAdapterInterface Istanza dell'adapter di test
     * @throws Exception Se la creazione fallisce
     */
    public static function createForTesting(): DatabaseAdapterInterface
    {
        // Crea config temporaneo per SQLite in memoria
        $config = [
            'database' => [
                'type' => 'sqlite',
                'sqlite' => [
                    'file' => ':memory:' // Database in RAM
                ]
            ]
        ];

        $adapter = new SQLiteDatabaseAdapter($config);
        $adapter->initialize();

        return $adapter;
    }
}

