# Anabix ↔ Ecomail Sync

Synchronizace kontaktů a aktivit mezi Anabix CRM a Ecomail.

## Skripty

### 1. `contacts-anabix-to-ecomail.php` — Kontakty: Anabix → Ecomail

Hlavní synchronizační skript. Načte kontakty z Anabixu, transformuje je a hromadně importuje do Ecomail listu.

**Klíčové funkce:**
- Delta sync (pouze změny od posledního běhu) s lookback oknem
- Fallback na full export pokud delta vrátí 0
- Organizace: paralelní fetch (curl_multi) + JSON cache
- Členství v seznamech → Ecomail tagy
- Pretitle/surtitle extrakce z Anabix `title` pole
- Owner mapa (idOwner → jméno) pro custom field `projectManager`
- Konfigurovatelné custom fields přes `ANABIX_CF_*` env vars
- Hromadný import (subscribe-bulk) v dávkách

```bash
php contacts-anabix-to-ecomail.php
```

### 2. `activities-ecomail-to-anabix.php` — Aktivity: Ecomail → Anabix

Čte kampaně a email eventy z Ecomailu, mapuje je na kontakty v Anabixu přes `*|anabixId|*` custom field a vytváří aktivity.

**Mapování eventů:**
| Ecomail event | Anabix typ aktivity |
|--------------|-------------------|
| send | sent newsletter |
| open | opened newsletter |
| click | clicked link in newsletter |
| hard_bounce, soft_bounce, unsub, spam, spam_complaint | note |

**Formát aktivity:**
- Title: `{předmět} - {event}` (u `open`/`click` navíc `(N×)`)
- Body:
  ```
  Kampaň: {interní název kampaně} (viz Ecomail)
  Stav: {event}                (u open/click: "{event} (N×)")
  Předmět: {předmět kampaně}
  Od: {from_name} | {from_email}
  {archive_url}

  Datum odeslání kampaně: {DD.MM.YYYY}
  ```

```bash
php activities-ecomail-to-anabix.php
php activities-ecomail-to-anabix.php --dry-run
```

### 3. `sync-automation-events.php` — Automatizace: Ecomail → Anabix

Zrcadlo skriptu č. 2, ale nad automation pipelines (autoresponder, welcome série, opuštěný košík…). Pro každou pipeline načte `/pipelines/{id}/stats-detail` a per subscriber vytvoří aktivity v Anabixu.

**Mapování eventů:**
| Ecomail event | Anabix typ aktivity |
|--------------|-------------------|
| send | sent autoresponder |
| open | opened autoresponder |
| click | clicked link in autoresponder |
| hard_bounce, soft_bounce, unsub, spam | note |

**Formát aktivity:**
- Title: `{název automatizace} - {event}` (u `open`/`click` navíc `(N×)`)
- Body:
  ```
  Automatizace: {název automatizace} (viz Ecomail)
  Stav: {event}                (u open/click: "{event} (N×)")
  ```

```bash
php sync-automation-events.php
php sync-automation-events.php --dry-run
php sync-automation-events.php --pipeline=43453
```

### 4. `sync-subscriber-events.php` — Web tracker: Ecomail → Anabix

Iteruje subscribery v Ecomail listu, načítá jejich `/subscribers/{email}/events` (web tracker — návštěvy webu, košík, nákup) a vytváří `note`-aktivity typu "Návštěva webu {domain}" v Anabixu.

Newsletter events (send/open/click) řeší skript č. 2, automation events řeší skript č. 3 — tento skript se stará **výhradně** o web tracker.

```bash
php sync-subscriber-events.php
php sync-subscriber-events.php --dry-run
php sync-subscriber-events.php --email=test@example.com
```

### 5. `sync-sheets.php` — Google Sheets → Anabix aktivity

Čte řádky z veřejné Google tabulky a vytváří aktivity (poznámky) u kontaktů v Anabixu.

```bash
php sync-sheets.php
```

## Instalace

1. Zkopírujte `.env.example` do `.env`:
   ```bash
   cp .env.example .env
   ```

2. Vyplňte konfiguraci — minimálně:
   - `ANABIX_USERNAME`, `ANABIX_TOKEN`, `ANABIX_API_URL`
   - `ECOMAIL_API_KEY`, `ECOMAIL_LIST_ID`

3. Spusťte sync:
   ```bash
   php contacts-anabix-to-ecomail.php
   ```

Pro pravidelný sync přidejte do cronu:
```
*/15 * * * * cd /path/to/project && php contacts-anabix-to-ecomail.php >> /dev/null 2>&1
```

## Struktura

```
├── contacts-anabix-to-ecomail.php   # Sync kontaktů (Anabix → Ecomail)
├── activities-ecomail-to-anabix.php # Sync newsletter aktivit (Ecomail → Anabix)
├── sync-automation-events.php       # Sync automation/autoresponder aktivit
├── sync-subscriber-events.php       # Sync web tracker aktivit
├── sync-sheets.php                  # Google Sheets → Anabix
├── src/
│   ├── AnabixClient.php       # Anabix API (kontakty, seznamy, organizace, aktivity)
│   ├── EcomailClient.php      # Ecomail API (subscribe-bulk, kampaně, pipelines, events)
│   ├── Transformer.php        # Mapování Anabix → Ecomail (pretitle, custom fields, tagy)
│   ├── SyncState.php          # Delta sync state (timestamp + lookback)
│   ├── GoogleSheetsClient.php # Čtení veřejných Google tabulek
│   ├── Logger.php             # Logování do souborů
│   └── env.php                # .env loader
├── storage/
│   ├── logs/                  # Denní logy
│   └── state/                 # Sync state, org cache, dedup klíče
├── .env.example               # Vzorová konfigurace
└── anabix_api_manual-2025.pdf # Anabix API manuál
```

## Mapování polí (contacts-anabix-to-ecomail)

| Ecomail pole | Anabix zdroj |
|-------------|-------------|
| name | firstName |
| surname | lastName |
| email | email |
| phone | phoneNumber |
| gender | sex |
| pretitle | title (extrakce před firstName) |
| surtitle | title (extrakce za lastName) |
| company | [organizations] title |
| street | [organizations] billingStreet |
| city | [organizations] billingCity |
| zip | [organizations] billingCode |
| country | [organizations] billingCountry |
| birthday | idCustomField=5 |
| tags | [lists] title |
| `*\|vip\|*` | vip (0/1) |
| `*\|primaryContact\|*` | primaryContact (0/1) |
| `*\|projectManager\|*` | idOwner (přes ANABIX_OWNER_* mapu) |
| `*\|prvniObchod\|*` | idCustomField=10 |
| `*\|anabixId\|*` | idContact |

## API Reference

### Anabix
- Auth: `username` + `token` v těle requestu
- Format: POST multipart/form-data s `json` polem
- URL: `https://FIRMA.anabix.cz/api`

### Ecomail
- Docs: https://docs.ecomail.cz/
- Auth: Header `key: API_KEY`
- Base URL: `https://api2.ecomailapp.cz`
