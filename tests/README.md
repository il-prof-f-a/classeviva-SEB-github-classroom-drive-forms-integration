# Test suite

La suite non richiede PHPUnit. Dalla root della repository:

```bash
php tests/run.php
```

Prima di un rilascio eseguire anche la baseline Composer/runtime:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
composer audit --locked
php tests/run.php
```

La baseline di sicurezza richiede PHP 8.2--8.4, le estensioni runtime dichiarate
nel `composer.json`, `vendor/autoload.php` completo e una `ENCRYPTION_KEY` in ogni
ambiente non di test.

Su Windows il runner abilita automaticamente `zip` e `gd` nei processi PHP figli
quando le relative DLL sono presenti nella directory delle estensioni. Nel profilo
full l'assenza di `ZipArchive`, Git o Node non viene considerata un successo: i test
che dipendono da tali prerequisiti devono essere realmente eseguiti.

Copre il contratto di pubblicabilità: file di distribuzione, asset applicativi, lint PHP, include letterali, configurazione `.env`, endpoint diagnostici, URL specifici dello staging, autoload Composer e regole Git. La suite verifica anche il ciclo ClasseViva session-only: scambio token REST verso `PHPSESSID`, assenza di credenziali e token nel database, timeout, rinnovo e logout.

Il test di integrazione MySQL viene eseguito separatamente tramite Docker Compose e `scripts/setup_database.php --validate`.

La suite include anche i cataloghi condivisi del wizard UDA: normalizzazione Google Forms,
Google Classroom e GitHub Classroom, picker JavaScript, markup del wizard e persistenza dei
link/ID esterni. Per eseguire il test JavaScript singolarmente:

```bash
node tests/uda_editor/test_catalog_picker.js
```

La verifica manuale richiede un ambiente locale autenticato: aprire `public/uda_create.php`,
selezionare una classe/materia associata e provare i cataloghi Forms, Classroom e GitHub.
Senza autorizzazione o mappatura il wizard deve mantenere l'inserimento manuale. I fixture
non devono contenere token, password o URL con credenziali.
