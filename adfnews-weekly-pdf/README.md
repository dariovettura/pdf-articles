# ADFNews Weekly PDF

Plugin WordPress che genera un PDF settimanale unico (lunedi-lunedi precedente) da tutti gli articoli pubblicati.

## Cosa fa

- Pianifica un job settimanale con WP-Cron.
- Recupera tutti i post `publish` della settimana precedente.
- Genera un PDF versionato `adfnews-weekly-YYYY-Www.pdf`.
- Aggiorna anche `current.pdf` (link stabile per header).
- Espone:
  - funzione PHP `adfnews_weekly_pdf_url()`
  - funzione PHP `adfnews_weekly_pdf_link()`
  - shortcode `[adfnews_weekly_pdf_link]`

## Installazione

1. Scarica questa cartella plugin.
2. Esegui `composer install --no-dev` nella cartella plugin per creare `vendor/`.
3. Comprimi la cartella `adfnews-weekly-pdf` in ZIP.
4. Da WordPress: Plugin -> Aggiungi nuovo -> Carica plugin.
5. Attiva il plugin.
6. Vai su Impostazioni -> ADFNews Weekly PDF.

## Note operative

- I PDF vengono salvati in `wp-content/uploads/adfnews-weekly/`.
- Il log viene salvato in `wp-content/uploads/adfnews-weekly/logs/weekly-pdf.log`.
- Se Dompdf non e presente in `vendor/`, la generazione fallisce con errore esplicito.
