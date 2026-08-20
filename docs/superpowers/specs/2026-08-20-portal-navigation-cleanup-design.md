# Pulizia navigazione studenti, voti e stato sistema

## Obiettivo

Rimuovere dal portale la pagina standalone di sincronizzazione studenti ormai
sostituita dai mapping dei gruppi didattici, rinominare la verifica dei voti
ClasseViva con un'etichetta esplicita e togliere dalla pagina avanzata di stato
la sezione di azioni che punta a test non più presenti.

## Decisioni

- Eliminare `public/studenti_sync.php` e il relativo link dalla dashboard.
- Conservare `StudentiManager` e la sincronizzazione roster usata da
  `public/teaching_groups.php`: la rimozione è solo della UI legacy.
- Lasciare invariato l'URL `verify_grades.php` per compatibilità con bookmark e
  rinominare le etichette visibili in `Gestione voti Classe Viva`.
- Eliminare integralmente la sezione `Azioni Disponibili` da
  `public/system_status.php`, inclusi i due pulsanti di test e il ritorno alla
  dashboard.

## Verifica

Livello test: **light**. Un test statico verificherà che la pagina studenti e i
suoi riferimenti di navigazione non siano più presenti, che la nuova etichetta
Classe Viva sia presente nella navigazione dei voti e che `system_status.php`
non contenga più la sezione o i link diagnostici rimossi.

## Fuori ambito

- Non vengono rimossi i servizi applicativi di sincronizzazione roster.
- Non viene rimosso `verify_grades.php`.
- Non vengono modificate le azioni diagnostiche della pagina distinta
  `public/status.php`.
