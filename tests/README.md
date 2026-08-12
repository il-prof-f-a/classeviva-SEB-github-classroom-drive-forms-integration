# Test suite

La suite non richiede PHPUnit. Dalla root della repository:

```bash
php tests/run.php
```

Copre il contratto di pubblicabilità: file di distribuzione, asset applicativi, lint PHP, include letterali, configurazione `.env`, endpoint diagnostici, URL specifici dello staging, autoload Composer e regole Git. La suite verifica anche il ciclo ClasseViva session-only: scambio token REST verso `PHPSESSID`, assenza di credenziali e token nel database, timeout, rinnovo e logout.

Il test di integrazione MySQL viene eseguito separatamente tramite Docker Compose e `scripts/setup_database.php --validate`.
