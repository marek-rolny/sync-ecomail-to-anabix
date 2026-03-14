# Architektura aplikace sync-ecomail-to-anabix

## Popis aplikace

Aplikace synchronizuje kontakty z e-mailové marketingové platformy **Ecomail** do CRM systému **Anabix**. Podporuje dva režimy:

1. **Polling (sync.php)** - periodické stahování všech subscriberů z Ecomailu (cron)
2. **Webhook (webhook.php)** - real-time zpracování nových přihlášení z Ecomailu

Klíčové vlastnosti:
- **Delta sync** - sledování již synchronizovaných kontaktů (prevence duplikátů)
- **Upsert logika** - vytvoření nového nebo aktualizace existujícího kontaktu
- **Zařazení do skupin** - automatické přiřazení kontaktů do konfigurovaných skupin v Anabixu
- **Logování** - denní log soubory s kompletním auditním záznamem

---

## 1. Diagram komponent (Component Diagram)

```mermaid
graph TB
    subgraph "Entry Points"
        SYNC["sync.php<br/>(CLI / Cron)"]
        WEBHOOK["webhook.php<br/>(HTTP POST)"]
    end

    subgraph "Core Components (src/)"
        ENV["env.php<br/>loadEnv() / env()"]
        LOG["Logger"]
        STATE["SyncState"]
        TRANS["Transformer"]
        EC["EcomailClient"]
        AC["AnabixClient"]
    end

    subgraph "External APIs"
        ECOMAIL_API["Ecomail API v2<br/>api2.ecomail.cz"]
        ANABIX_API["Anabix CRM API<br/>ACCOUNT.anabix.cz/api"]
    end

    subgraph "Storage"
        LOGS["storage/logs/<br/>sync-YYYY-MM-DD.log"]
        STATEFILE["storage/state/<br/>sync-state.json"]
        ENVFILE[".env"]
    end

    SYNC --> ENV
    SYNC --> LOG
    SYNC --> STATE
    SYNC --> EC
    SYNC --> AC
    SYNC --> TRANS

    WEBHOOK --> ENV
    WEBHOOK --> LOG
    WEBHOOK --> STATE
    WEBHOOK --> EC
    WEBHOOK --> AC
    WEBHOOK --> TRANS

    ENV --> ENVFILE
    LOG --> LOGS
    STATE --> STATEFILE

    EC --> ECOMAIL_API
    AC --> ANABIX_API
```

---

## 2. Diagram tříd (Class Diagram)

```mermaid
classDiagram
    class EcomailClient {
        -string apiKey
        -string baseUrl
        -Logger logger
        +__construct(apiKey, baseUrl, logger)
        +getAllSubscribers(listId, pageSize) array
        +getSubscriber(listId, email) array|null
        -get(endpoint) array|null
    }

    class AnabixClient {
        -string user
        -string token
        -string apiUrl
        -Logger logger
        +__construct(user, token, apiUrl, logger)
        +findContactByEmail(email) array|null
        +createContact(contactData) array|null
        +updateContact(contactId, contactData) array|null
        +addContactToGroup(contactId, groupId) array|null
        +createActivity(contactId, title, body, type) array|null
        -request(requestType, requestMethod, data) array|null
    }

    class Transformer {
        +toAnabixContact(ecomailSubscriber)$ array
        +getEmail(ecomailSubscriber)$ string
        +isValid(ecomailSubscriber)$ bool
    }

    class SyncState {
        -string stateFile
        -array state
        +__construct(stateDir)
        +isSynced(email) bool
        +markSynced(email) void
        +getLastSync() string|null
        +updateLastSync() void
        +save() void
        +getSyncedCount() int
        -load() void
    }

    class Logger {
        -string logDir
        +__construct(logDir)
        +info(message, context) void
        +error(message, context) void
        +warning(message, context) void
        -write(level, message, context) void
    }

    EcomailClient --> Logger : uses
    AnabixClient --> Logger : uses
    SyncState ..> Logger : independent
    Transformer ..> EcomailClient : transforms data from
    Transformer ..> AnabixClient : transforms data for
```

---

## 3. Sekvenční diagram - Polling sync (sync.php)

```mermaid
sequenceDiagram
    participant Cron
    participant sync.php
    participant Env as env.php
    participant Log as Logger
    participant State as SyncState
    participant EC as EcomailClient
    participant Trans as Transformer
    participant AC as AnabixClient
    participant EcoAPI as Ecomail API
    participant AnaAPI as Anabix API

    Cron->>sync.php: spuštění
    sync.php->>Env: loadEnv(".env")
    sync.php->>Log: new Logger()
    sync.php->>State: new SyncState()
    sync.php->>EC: new EcomailClient()
    sync.php->>AC: new AnabixClient()

    sync.php->>EC: getAllSubscribers(listId)
    loop Pro každou stránku
        EC->>EcoAPI: GET /lists/{id}/subscribers?page=N
        EcoAPI-->>EC: {data: [...], last_page: N}
    end
    EC-->>sync.php: [subscribers]

    loop Pro každého subscribera
        sync.php->>Trans: isValid(subscriber)
        Trans-->>sync.php: true/false

        alt Nevalidní email
            sync.php->>Log: warning("Invalid email")
        else Již synchronizován
            sync.php->>State: isSynced(email)
            State-->>sync.php: true → skip
        else Nový/aktualizovaný kontakt
            sync.php->>Trans: toAnabixContact(subscriber)
            Trans-->>sync.php: contactData

            sync.php->>AC: findContactByEmail(email)
            AC->>AnaAPI: POST {getAll, criteria: {email}}
            AnaAPI-->>AC: contact | null
            AC-->>sync.php: existingContact

            alt Kontakt existuje
                sync.php->>AC: updateContact(id, data)
                AC->>AnaAPI: POST {update, data}
                AnaAPI-->>AC: updated contact
            else Nový kontakt
                sync.php->>AC: createContact(data)
                AC->>AnaAPI: POST {create, data}
                AnaAPI-->>AC: new contact {id}
                loop Pro každou skupinu
                    sync.php->>AC: addContactToGroup(id, groupId)
                    AC->>AnaAPI: POST {addToGroup, data}
                end
            end

            sync.php->>State: markSynced(email)
        end
    end

    sync.php->>State: updateLastSync()
    sync.php->>State: save()
    sync.php->>Log: info("Sync completed", report)
    sync.php-->>Cron: JSON report
```

---

## 4. Sekvenční diagram - Webhook (webhook.php)

```mermaid
sequenceDiagram
    participant Ecomail as Ecomail Webhook
    participant WH as webhook.php
    participant Log as Logger
    participant State as SyncState
    participant EC as EcomailClient
    participant Trans as Transformer
    participant AC as AnabixClient
    participant AnaAPI as Anabix API

    Ecomail->>WH: POST {email, status, ...}

    WH->>WH: Ověření POST metody
    WH->>WH: Parsování JSON payloadu
    WH->>WH: Ověření webhook secret

    alt Neplatný request
        WH-->>Ecomail: HTTP 400/401/405
    end

    alt Status != SUBSCRIBED
        WH->>Log: info("Skipping non-subscribe")
        WH-->>Ecomail: HTTP 200 {skipped}
    end

    WH->>State: isSynced(email)
    alt Již synchronizován
        WH-->>Ecomail: HTTP 200 {already synced}
    end

    WH->>EC: getSubscriber(listId, email)
    EC-->>WH: fullData | null

    alt Plná data dostupná
        WH->>Trans: toAnabixContact(fullData)
    else Fallback na webhook payload
        WH->>Trans: toAnabixContact(webhookData)
    end
    Trans-->>WH: contactData

    WH->>AC: findContactByEmail(email)
    AC-->>WH: existingContact | null

    alt Kontakt existuje
        WH->>AC: updateContact(id, data)
    else Nový kontakt
        WH->>AC: createContact(data)
        loop Pro každou skupinu
            WH->>AC: addContactToGroup(id, groupId)
        end
    end

    WH->>State: markSynced(email)
    WH->>State: save()
    WH-->>Ecomail: HTTP 200 {status: ok}
```

---

## 5. Datový tok - Transformace (Data Mapping)

```mermaid
graph LR
    subgraph "Ecomail Subscriber"
        E_EMAIL["email"]
        E_NAME["name"]
        E_SURNAME["surname"]
        E_PHONE["phone"]
        E_COMPANY["company"]
        E_CITY["city"]
        E_STREET["street"]
        E_ZIP["zip"]
        E_COUNTRY["country"]
    end

    subgraph "Transformer::toAnabixContact()"
        FILTER["array_filter()<br/>odstraní prázdné"]
        LOWER["strtolower() + trim()<br/>normalizace emailu"]
    end

    subgraph "Anabix Contact"
        A_EMAIL["email"]
        A_NAME["name"]
        A_SURNAME["surname"]
        A_PHONE["phone"]
        A_COMPANY["company"]
        A_CITY["city"]
        A_STREET["street"]
        A_ZIP["zip"]
        A_COUNTRY["country"]
    end

    E_EMAIL --> LOWER --> A_EMAIL
    E_NAME --> FILTER --> A_NAME
    E_SURNAME --> FILTER --> A_SURNAME
    E_PHONE --> FILTER --> A_PHONE
    E_COMPANY --> FILTER --> A_COMPANY
    E_CITY --> FILTER --> A_CITY
    E_STREET --> FILTER --> A_STREET
    E_ZIP --> FILTER --> A_ZIP
    E_COUNTRY --> FILTER --> A_COUNTRY
```

---

## 6. Stavový diagram - Životní cyklus kontaktu

```mermaid
stateDiagram-v2
    [*] --> Ecomail_Subscriber: Uživatel se přihlásí

    Ecomail_Subscriber --> Validace: sync.php / webhook.php

    Validace --> Přeskočen: Neplatný email
    Validace --> Přeskočen: Již synchronizován
    Validace --> Transformace: Validní & nový

    Transformace --> Hledání_v_Anabixu: Transformer.toAnabixContact()

    Hledání_v_Anabixu --> Aktualizace: Kontakt nalezen
    Hledání_v_Anabixu --> Vytvoření: Kontakt nenalezen

    Vytvoření --> Přiřazení_skupin: createContact()
    Aktualizace --> Synchronizován: updateContact()
    Přiřazení_skupin --> Synchronizován: addContactToGroup()

    Synchronizován --> [*]: markSynced() + save()
    Přeskočen --> [*]: log & skip
```

---

## 7. Diagram nasazení (Deployment)

```mermaid
graph TB
    subgraph "Server (PHP)"
        subgraph "Cron Job"
            CRON["*/15 * * * *<br/>php sync.php"]
        end

        subgraph "Web Server (Apache/Nginx)"
            WH_ENDPOINT["webhook.php<br/>POST /webhook.php"]
        end

        subgraph "Souborový systém"
            ENV_FILE[".env<br/>(konfigurace)"]
            LOG_FILES["storage/logs/<br/>sync-*.log"]
            STATE_FILE["storage/state/<br/>sync-state.json"]
        end
    end

    subgraph "Ecomail Cloud"
        ECOMAIL_APP["Ecomail<br/>E-mail Marketing"]
        ECOMAIL_API2["API v2<br/>api2.ecomail.cz"]
        ECOMAIL_WH["Webhook System"]
    end

    subgraph "Anabix Cloud"
        ANABIX_APP["Anabix<br/>CRM"]
        ANABIX_API2["API<br/>ACCOUNT.anabix.cz/api"]
    end

    CRON -->|"GET /lists/subscribers"| ECOMAIL_API2
    CRON -->|"POST contacts"| ANABIX_API2
    CRON --> LOG_FILES
    CRON --> STATE_FILE
    CRON --> ENV_FILE

    ECOMAIL_WH -->|"POST webhook event"| WH_ENDPOINT
    WH_ENDPOINT -->|"GET subscriber detail"| ECOMAIL_API2
    WH_ENDPOINT -->|"POST contacts"| ANABIX_API2
    WH_ENDPOINT --> LOG_FILES
    WH_ENDPOINT --> STATE_FILE

    ECOMAIL_APP --- ECOMAIL_API2
    ECOMAIL_APP --- ECOMAIL_WH
    ANABIX_APP --- ANABIX_API2
```

---

## 8. Souhrn architektury

| Vrstva | Komponenty | Odpovědnost |
|--------|-----------|-------------|
| **Entry Points** | `sync.php`, `webhook.php` | Orchestrace synchronizace |
| **API Clients** | `EcomailClient`, `AnabixClient` | Komunikace s externími API |
| **Business Logic** | `Transformer` | Mapování dat mezi systémy |
| **Persistence** | `SyncState` | Delta sync, prevence duplikátů |
| **Infrastructure** | `Logger`, `env.php` | Logování, konfigurace |
| **Storage** | `storage/logs/`, `storage/state/` | Soubory stavu a logů |
| **External** | Ecomail API v2, Anabix API | Zdrojový a cílový systém |

### Technologie
- **Jazyk:** PHP (bez frameworku)
- **HTTP klient:** cURL
- **Konfigurace:** `.env` soubor (vlastní parser)
- **Persistence:** JSON soubory (file-based)
- **Scheduling:** Cron (polling) + Ecomail Webhooks (real-time)
