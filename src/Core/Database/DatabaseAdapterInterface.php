<?php

namespace App\Core\Database;

/**
 * DatabaseAdapterInterface - Interface per adapter di database
 *
 * Definisce i metodi comuni che tutti gli adapter devono implementare.
 * Permette di astrarre la gestione dei dati dal tipo di storage sottostante
 * (Excel locale, Google Sheets, database SQL, ecc.)
 */
interface DatabaseAdapterInterface
{
    /**
     * Legge tutte le righe da un foglio e le restituisce come array
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return array Array di righe, dove ogni riga è un array associativo
     * @throws \Exception Se il foglio non può essere letto
     */
    public function findAll(string $sheetName): array;

    /**
     * Trova righe che soddisfano determinati criteri
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param array $where Criteri di ricerca ['campo' => 'valore', ...]
     * @return array Array di righe che soddisfano i criteri
     * @throws \Exception Se la ricerca fallisce
     */
    public function findWhere(string $sheetName, array $where): array;

    /**
     * Trova una singola riga per valore di chiave
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param string $keyField Nome del campo chiave
     * @param mixed $keyValue Valore della chiave
     * @return array|null Riga trovata o null
     * @throws \Exception Se la ricerca fallisce
     */
    public function findOne(string $sheetName, string $keyField, $keyValue): ?array;

    /**
     * Inserisce una nuova riga nel foglio
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param array $data Dati da inserire (array associativo)
     * @return bool True se l'inserimento ha successo
     * @throws \Exception Se l'inserimento fallisce
     */
    public function insertRow(string $sheetName, array $data): bool;

    /**
     * Aggiorna una riga esistente
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param string $keyField Nome del campo chiave
     * @param mixed $keyValue Valore della chiave da aggiornare
     * @param array $data Nuovi dati (array associativo)
     * @return bool True se l'aggiornamento ha successo
     * @throws \Exception Se l'aggiornamento fallisce
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool;

    /**
     * Elimina una riga
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param mixed $keyValue Valore della chiave da eliminare
     * @param string $keyField Nome del campo chiave (default 'id')
     * @return bool True se l'eliminazione ha successo
     * @throws \Exception Se l'eliminazione fallisce
     */
    public function deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool;

    /**
     * Verifica che un foglio/tabella esista, e lo crea se mancante
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return bool True se il foglio esiste o è stato creato
     * @throws \Exception Se la creazione fallisce
     */
    public function ensureSheetExists(string $sheetName): bool;

    /**
     * Restituisce la connessione/risorsa sottostante per operazioni avanzate
     *
     * @return mixed Spreadsheet, Google Client, PDO, etc.
     * @throws \Exception Se la connessione fallisce
     */
    public function getConnection();

    /**
     * Crea un backup del database
     *
     * @return string Path del file di backup creato
     * @throws \Exception Se il backup fallisce
     */
    public function createBackup(): string;

    /**
     * Inizializza il database creando tutti i fogli necessari
     *
     * @return array Report dell'inizializzazione ['created' => [...], 'existing' => [...]]
     * @throws \Exception Se l'inizializzazione fallisce
     */
    public function initialize(): array;

    /**
     * Valida la struttura del database
     *
     * @return array Report della validazione ['valid' => bool, 'errors' => [...], 'warnings' => [...]]
     */
    public function validate(): array;

    /**
     * Ripara il database correggendo problemi di struttura
     *
     * @return array Report della riparazione ['fixed' => [...], 'failed' => [...]]
     * @throws \Exception Se la riparazione fallisce
     */
    public function repair(): array;

    /**
     * Ottiene le colonne di un foglio/tabella
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return array Array di nomi colonne
     * @throws \Exception Se il foglio non esiste
     */
    public function getColumns(string $sheetName): array;

    /**
     * Verifica se un foglio/tabella esiste
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return bool True se esiste
     */
    public function sheetExists(string $sheetName): bool;

    /**
     * Conta le righe in un foglio/tabella
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return int Numero di righe (escludendo l'header)
     * @throws \Exception Se il conteggio fallisce
     */
    public function count(string $sheetName): int;

    /**
     * Svuota un foglio/tabella mantenendo l'header
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return bool True se lo svuotamento ha successo
     * @throws \Exception Se lo svuotamento fallisce
     */
    public function truncate(string $sheetName): bool;

    /**
     * Carica un file Excel esterno (non il database principale)
     *
     * Utile per import, template, file temporanei.
     * Valida il path per sicurezza.
     *
     * @param string $filePath Path assoluto del file
     * @return mixed Spreadsheet object (PHPSpreadsheet) o equivalente
     * @throws \Exception Se il file non può essere caricato o il path non è sicuro
     */
    // File Excel/CSV gestiti da App\Core\SpreadsheetFileService.

    /**
     * Carica un template Excel dalla directory template
     *
     * @param string $templateName Nome del template (es: 'template_rubrica.xlsx')
     * @return mixed Spreadsheet object
     * @throws \Exception Se il template non esiste o non può essere caricato
     */

    /**
     * Ottiene la lista di tutti i fogli/tabelle presenti nel database
     *
     * @return array Array di nomi dei fogli/tabelle
     * @throws \Exception Se non è possibile leggere la lista
     */
    public function getAllSheetNames(): array;

    /**
     * Svuota completamente un foglio/tabella rimuovendo tutte le righe
     * Mantiene la struttura del foglio (colonne)
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return bool True se lo svuotamento ha successo
     * @throws \Exception Se lo svuotamento fallisce
     */
    public function clearSheet(string $sheetName): bool;

    /**
     * Crea un nuovo foglio/tabella con le colonne specificate
     *
     * @param string $sheetName Nome del foglio/tabella da creare
     * @param array $columns Array di nomi delle colonne
     * @return bool True se la creazione ha successo
     * @throws \Exception Se la creazione fallisce o il foglio esiste già
     */
    public function createSheet(string $sheetName, array $columns = []): bool;
}
