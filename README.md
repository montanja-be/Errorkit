# Errorkit

Fork van LaraBug die de errors van Munia-websites naar projecten.montanja.be stuurt.

## Installatie

```bash
composer require montanja-be/errorkit
```

Meer is er niet nodig: de package registreert zichzelf bij de exception handler en gebruikt de `FLOWRISE_TOKEN`
die elke Munia-site al heeft voor de heartbeat. Testen:

```bash
php artisan errorkit:test
```

## Wat het doet

- **Alle gerapporteerde exceptions**: via `reportable()` op de exception handler. Wat Laravel of de site zelf niet
  rapporteert (`dontReport`, een `reportable` die `false` teruggeeft, 404's, validatie), komt hier ook niet binnen.
- **Bundelen**: elke error krijgt een fingerprint (klasse, bestand relatief aan het project, lijn en de melding
  zonder ids, getallen, uuids en waarden tussen quotes). Dezelfde error wordt maximaal één keer per `sleep` seconden
  (standaard 60) verstuurd; herhalingen worden geteld en dat aantal (`occurrences`) gaat mee met de volgende melding.
- **Rate limiting**: maximaal `rate_limit.per_minute` (standaard 10) meldingen per minuut per site. Wat erover gaat,
  wordt geteld (`dropped`) en meegestuurd met de volgende melding die wel vertrekt.
- **Backoff**: antwoordt de server 429 of 5xx, of helemaal niet, dan stopt de site met versturen voor de
  `Retry-After` van de server of `backoff` seconden (standaard 60). Een server die plat ligt, kost dus niet elke
  request een time-out.
- **Na de response**: tijdens een webrequest wordt pas verstuurd nadat de bezoeker zijn pagina heeft. Commando's en
  queue workers versturen meteen. Time-outs zijn kort (2 s verbinden, 5 s totaal).
- **Privacy**: van de gebruiker gaan alleen id, modeltype en guard mee; van cookies alleen de namen. Parameters,
  headers en sessiewaarden worden gefilterd op de `blacklist` in de config.

Valt de cache weg (bv. een databasecache terwijl de database plat ligt), dan gelden bundelen en rate limiting per
proces.

## Configuratie

Alles via `.env`, publiceren van de config is niet nodig.

| Variabele         | Standaard                              | Betekenis                                    |
|-------------------|----------------------------------------|----------------------------------------------|
| `EK_KEY`          | `FLOWRISE_TOKEN`                       | token van de website op projecten            |
| `EK_SERVER`       | `https://projecten.montanja.be/api/log` | endpoint                                     |
| `EK_SLEEP`        | `60`                                   | seconden tussen twee meldingen van één error |
| `EK_RATE_LIMIT`   | `10`                                   | meldingen per minuut per site                |
| `EK_BACKOFF`      | `60`                                   | seconden pauze als de server niet antwoordt  |
| `EK_DEFERRED`     | `true`                                 | in webrequests pas na de response versturen  |
| `EK_RELEASE`      | -                                      | release of commit, wordt meegestuurd         |

Alleen de omgevingen in `environments` (standaard `production`) versturen iets.

Queue-monitoring en de CVE-scan van LaraBug staan standaard uit: projecten.montanja.be ontvangt (nog) alleen errors.
JavaScript-errors worden niet opgevangen.
