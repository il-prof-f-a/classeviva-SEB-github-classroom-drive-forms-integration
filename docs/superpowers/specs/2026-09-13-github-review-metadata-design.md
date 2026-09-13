# Specifica: metadati GitHub nella Review degli assignment

## Obiettivo

Estendere la pagina `public/github_assignment_review.php` con issue, branch, tag e riferimenti incrociati dei commit, mantenendo il caricamento dei metadati separato dal voto e senza introdurre persistenza nel database.

## Comportamento

- La Review mostra issue aperte e chiuse della repository; le pull request restituite dall'endpoint Issues vengono escluse.
- Per ogni issue sono mostrati numero, titolo, stato, data di apertura, eventuale data di chiusura, descrizione e i commit collegati dalla timeline, con link GitHub cliccabili.
- Il titolo e il messaggio dei commit riconoscono riferimenti `#numero` e `owner/repo#numero`; ogni riferimento è mostrato come icona issue e link all'issue.
- I tag sono mostrati nel commit corrispondente come label nera con testo bianco.
- La repository mostra il numero e l'elenco dei branch con link GitHub.
- Il branch di origine viene mostrato come `head.ref` di una pull request associata quando disponibile. In assenza, vengono mostrati i branch che hanno il commit come HEAD; questa seconda informazione è qualificata come branch attuale e non come origine certa.
- I metadati vengono caricati in modo asincrono all'apertura dei dettagli repository. Il voto e le LOC restano utilizzabili anche se un endpoint GitHub fallisce.

## API e sicurezza

`GitHubIntegration` espone solo metodi tipizzati per issue, timeline, branch, tag, pull request associate e paginazione commit. Owner/repository, SHA e numero issue sono validati prima di costruire gli endpoint. I dati dinamici sono sottoposti a escaping HTML/JSON e nessun URL esterno viene accettato dalla richiesta del browser.

## Limiti dichiarati

La paginazione continua fino a esaurimento o al limite configurato per evitare di consumare la quota API su repository enormi. Quando il limite viene raggiunto la UI indica che l'elenco è parziale. L'origine di un commit senza PR o senza ref HEAD esposto dall'API non è determinabile in modo affidabile e viene dichiarata come tale.

## Verifica

Fixture locali verificano normalizzazione, deduplica, riferimenti issue, tag e branch. Test statici verificano gli endpoint e il markup della Review; la suite completa `tests/run.php`, lint PHP e smoke HTTP Docker devono restare verdi.
