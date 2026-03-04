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

## Fáze 2 (plánováno)

- Sledování aktivit (otevřené emaily, kliknuté linky)
- Zápis aktivit do Anabixu (endpoint `activities.create`)
- Webhook z Ecomailu pro campaign aktivity
