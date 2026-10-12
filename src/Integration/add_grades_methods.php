<?php

/**
 * Script obsoleto mantenuto solo per non rompere eventuali riferimenti storici.
 *
 * ClasseViva non consente più di leggere il registro docente tramite PHPSESSID;
 * non è quindi più corretto iniettare metodi di scraping in ClasseVivaAPI.
 */

fwrite(STDERR, "funzione non più supportata a causa di incompatibilità con il sistema\n");
exit(1);
