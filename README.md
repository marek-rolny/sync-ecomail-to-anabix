# Sync Ecomail to Anabix

Synchronizace kontaktů z Ecomailu (webform/seznam) do Anabix CRM.

## Funkce

- **Polling sync** (`sync.php`): Načte všechny kontakty z Ecomail seznamu a vytvoří/aktualizuje je v Anabixu
- **Webhook receiver** (`webhook.php`): Přijímá webhooky z Ecomailu při přidání nového kontaktu
- **Delta sync**: Sleduje, které kontakty již byly synced (neposílá duplicity)
- **Automatické zařazení** do skupin v Anabixu (konfigurovatelné)

## Instalace

1. Zkopírujte `.env.example` do `.env`:
   ```bash
   cp .env.example .env
   ```

2. Vyplňte konfiguraci v `.env`:
   - `ECOMAIL_API_KEY` - API klíč z Ecomailu (Nastavení > Integrace)
   - `ECOMAIL_LIST_ID` - ID seznamu v Ecomailu
   - `ANABIX_API_USER` - Uživatelské jméno pro Anabix API
   - `ANABIX_API_TOKEN` - Token pro Anabix API
   - `ANABIX_API_URL` - URL vašeho Anabix účtu (`https://FIRMA.anabix.cz/api`)
   - `ANABIX_GROUP_IDS` - ID skupin v Anabixu (volitelné, oddělené čárkou)

## Použití

### CLI sync (cron)

```bash
php sync.php
```

Výstup je JSON report:
```json
{
    "status": "ok",
    "created": 5,
    "updated": 2,
    "skipped": 10,
    "failed": 0,
    "errors": []
}
```

Pro pravidelné spouštění přidejte do cronu:
```
*/15 * * * * cd /path/to/project && php sync.php >> /dev/null 2>&1
```

### Webhook

Nastavte URL `https://your-server.com/webhook.php` v Ecomailu:
- Jděte do nastavení seznamu v Ecomailu
- Na konci stránky nastavte webhook URL
- Ecomail pošle POST request při každém novém odběrateli

## Struktura

```
.
├── src/
│   ├── EcomailClient.php    # Klient pro Ecomail API
│   ├── AnabixClient.php     # Klient pro Anabix API
│   ├── Transformer.php      # Mapování polí Ecomail → Anabix
│   ├── SyncState.php        # Tracking synchronizovaných kontaktů
│   ├── Logger.php           # Logování do souborů
│   └── env.php              # Načítání .env konfigurace
├── storage/
│   ├── logs/                # Logy synchronizace
│   └── state/               # Stav synchronizace (JSON)
├── sync.php                 # CLI skript pro sync
├── webhook.php              # Webhook endpoint
├── .env.example             # Vzorová konfigurace
└── README.md
```

## API Reference

### Ecomail
- Docs: https://ecomailczv2.docs.apiary.io/
- Auth: Header `key: API_KEY`
- Base URL: `https://api2.ecomail.cz`

### Anabix
- Docs: https://www.anabix.cz/category/napojene-systemy/api/
- Auth: `username` + `token` v těle requestu
- Format: JSON POST na `https://FIRMA.anabix.cz/api`

### Import poznámek z Campaign Monitoru (`sync-notes.php`)

Importuje data z Google tabulky (export z Campaign Monitoru) a vytvoří poznámky u kontaktů v Anabixu.

**Formát Google tabulky:**
| Sloupec A | Sloupec B | Sloupec C |
|-----------|-----------|-----------|
| email     | datum     | důvod     |
| jan@example.cz | 2024-05-15 | MarkedAsSpam |
| petra@example.cz | 2024-06-01 | Unsubscribed |

**Způsoby spuštění:**

```bash
# Z lokálního CSV souboru
php sync-notes.php /cesta/k/export.csv

# Z publikované Google tabulky (CSV URL)
php sync-notes.php "https://docs.google.com/spreadsheets/d/.../pub?output=csv"

# Nebo nastavte GOOGLE_SHEET_CSV_URL v .env a spusťte bez argumentů
php sync-notes.php

# Náhled bez zápisu do Anabixu (dry-run)
php sync-notes.php --dry-run
php sync-notes.php /cesta/k/export.csv --dry-run
```

**Jak publikovat Google tabulku jako CSV:**
1. Otevřete tabulku v Google Sheets
2. Soubor > Sdílet > Publikovat na webu
3. Vyberte list a formát **CSV**
4. Zkopírujte URL a vložte do `.env` jako `GOOGLE_SHEET_CSV_URL`

Skript automaticky:
- Přeskočí hlavičku tabulky
- Validuje emaily a data
- Zabraňuje duplicitnímu vytváření poznámek (state tracking)
- Respektuje rate limiting API (200ms pauza mezi requesty)

## Fáze 2 (plánováno)

- Sledování aktivit (otevřené emaily, kliknuté linky)
- Webhook z Ecomailu pro campaign aktivity
