# JustCreators Bewerbungsportal Pro

Ein WordPress-Plugin für das JustCreators Bewerbungsportal mit Discord-Integration, automatischer Link-Validierung und Status-Synchronisation.

## 📋 Übersicht

Das Plugin verwaltet den kompletten Bewerbungsprozess für JustCreators Season 2:
- Discord OAuth2 Authentifizierung
- Bewerbungsformular mit Social Media Link-Validierung
- Status-Management (pending, accepted, rejected)
- Regeln-Seite für akzeptierte Bewerber
- Integration mit Discord Bot für automatische Synchronisation
- REST API für externe Services

## ✨ Features

### Bewerbungsportal
- ✅ Discord OAuth2 Login
- ✅ Automatische Social Media Link-Validierung (YouTube, Twitch, TikTok)
- ✅ Live Status-Anzeige für Bewerber
- ✅ Responsive Design mit Animationen
- ✅ Echtzeit-Validierung der Formularfelder
- ✅ Mehrere Social Media Kanäle pro Bewerbung (max. 5)

### Admin-Bereich
- ✅ Übersicht aller Bewerbungen mit Statistiken
- ✅ Status-Management (pending, accepted, rejected)
- ✅ Bot API Konfiguration
- ✅ Automatische Synchronisation mit Discord
- ✅ Löschung mit Discord-Sync

### Regeln-Seite
- ✅ Geschützter Bereich nur für akzeptierte Bewerber
- ✅ Discord OAuth Authentifizierung
- ✅ Regeln-Anzeige und Akzeptierung
- ✅ Minecraft Name Eingabe
- ✅ Discord Server Invite nach Abschluss
- ✅ REST API für Member-Export

### Discord Integration
- ✅ Automatische Status-Synchronisation (Discord → WordPress)
- ✅ Auto-Sync bei Löschung (WordPress → Discord)
- ✅ Forum Tags für Bewerbungsstatus
- ✅ Slash Commands Support (`/accept`, `/reject`)

## 🚀 Installation

1. **Plugin hochladen**
   ```bash
   # Plugin-Dateien in wp-content/plugins/justcreators-bewerbungsportal/ hochladen
   ```

2. **Plugin aktivieren**
   - Im WordPress Admin-Bereich unter "Plugins" aktivieren

3. **Datenbank-Tabellen erstellen**
   - Die Tabellen werden automatisch bei der Aktivierung erstellt:
     - `wp_jc_discord_applications` - Bewerbungen
     - `wp_jc_members` - Mitglieder (wird bei Bedarf erstellt)

## ⚙️ Konfiguration

### Discord OAuth Setup

1. **Discord Application erstellen**
   - Gehe zu https://discord.com/developers/applications
   - Erstelle eine neue Application
   - Notiere dir `Client ID` und `Client Secret`

2. **OAuth2 Redirect URI konfigurieren**
   - In der Discord Application unter "OAuth2" → "Redirects" hinzufügen:
     - `https://deine-domain.de/bewerbung?discord_oauth=1`
     - `https://deine-domain.de/regeln`

3. **Plugin-Konfiguration**
   
   **Für Bewerbungsportal** (`justcreators-bewerbungsportal.php`):
   ```php
   define( 'JC_DISCORD_CLIENT_ID', 'DEINE_CLIENT_ID' );
   define( 'JC_DISCORD_CLIENT_SECRET', 'DEIN_CLIENT_SECRET' );
   define( 'JC_REDIRECT_URI', 'https://deine-domain.de/bewerbung?discord_oauth=1' );
   define( 'JC_TEMP_DISCORD_INVITE', 'https://discord.gg/DEIN_INVITE' );
   ```
   
   **Für Regeln-Seite** (`justcreators_rules_page.php`):
   ```php
   define( 'JC_RULES_CLIENT_ID', 'DEINE_CLIENT_ID' );
   define( 'JC_RULES_CLIENT_SECRET', 'DEIN_CLIENT_SECRET' );
   define( 'JC_RULES_REDIRECT_URI', 'https://deine-domain.de/regeln' );
   define( 'JC_RULES_API_SECRET', 'DEIN_API_SECRET' );
   ```

### Bot API Setup

1. **Bot API konfigurieren**
   - Im WordPress Admin: **Einstellungen** → **JC Bot Setup**
   - Bot API URL eingeben (z.B. `http://localhost:3000`)
   - API Secret eingeben (muss mit Bot `.env` übereinstimmen)
   - Verbindung testen

2. **Bot API Secret**
   - Muss identisch mit dem `API_SECRET` in der Bot `.env` Datei sein

## 📝 Verwendung

### Shortcodes

**Bewerbungsformular:**
```
[discord_application_form]
```
- Erstellt auf der Seite `/bewerbung` einfügen

**Regeln-Seite:**
```
[jc_rules]
```
- Erstellt auf der Seite `/regeln` einfügen

### Admin-Bereich

**Bewerbungen verwalten:**
- **WordPress Admin** → **Bewerbungen**
- Übersicht aller Bewerbungen mit Status
- Status ändern (pending, accepted, rejected)
- Bewerbungen löschen (mit Discord-Sync)

**Bot Setup:**
- **WordPress Admin** → **Einstellungen** → **JC Bot Setup**
- Bot API URL und Secret konfigurieren
- Verbindung testen

## 🔌 REST API Endpoints

### Status-Sync (Discord → WordPress)
```
POST /wp-json/jc/v1/status-sync
Authorization: Bearer {API_SECRET}
Content-Type: application/json

{
  "discord_id": "123456789",
  "status": "accepted"
}
```

### Member Export
```
GET /wp-json/jc/v1/export-members
Authorization: Bearer {JC_RULES_API_SECRET}
```

### Member Check
```
GET /wp-json/jc/v1/check-member/{discord_id}
Authorization: Bearer {JC_RULES_API_SECRET}
```

## 📁 Dateistruktur

```
jc-wp-plugin/
├── justcreators-bewerbungsportal.php  # Haupt-Plugin (Bewerbungsportal)
├── justcreators_rules_page.php        # Regeln-Seite & Member-Management
└── README.md                          # Diese Datei
```

## 🗄️ Datenbankstruktur

### `wp_jc_discord_applications`
- `id` - Eindeutige ID
- `discord_id` - Discord User ID (UNIQUE)
- `discord_name` - Discord Username
- `applicant_name` - Bewerber Name
- `age` - Alter
- `social_channels` - JSON Array mit Social Media Links
- `social_activity` - Aktivitätsbeschreibung
- `motivation` - Motivationsschreiben
- `forum_post_id` - Discord Forum Post ID
- `status` - Status (pending, accepted, rejected)
- `created_at` - Erstellungsdatum

### `wp_jc_members`
- `id` - Eindeutige ID
- `discord_id` - Discord User ID (UNIQUE)
- `discord_name` - Discord Username
- `minecraft_name` - Minecraft Java Name
- `rules_accepted` - Regeln akzeptiert (0/1)
- `rules_accepted_at` - Akzeptierungsdatum
- `profile_completed` - Profil vollständig (0/1)
- `created_at` - Erstellungsdatum

## 🔒 Sicherheit

- ✅ Nonce-Verification für alle Formulare
- ✅ SQL Prepared Statements
- ✅ Input Sanitization
- ✅ Output Escaping
- ✅ Bearer Token Authentication für API
- ✅ Session-basierte Authentifizierung
- ✅ HTTPS-only Cookies

## 🎨 Features im Detail

### Social Media Link-Validierung
- Unterstützt: YouTube, Twitch, TikTok
- Automatische URL-Validierung
- Platform-Icons werden angezeigt
- Handles (@username) sind nicht erlaubt

### Status-Synchronisation
- Automatische Sync von Discord → WordPress
- Bot kann Status via REST API ändern
- Live Status-Anzeige für Bewerber

### Responsive Design
- Mobile-optimiert
- Moderne Animationen
- Dark Theme
- Discord-ähnliches Design

## 🐛 Debugging

Das Plugin loggt wichtige Events in die WordPress Debug-Log:
- OAuth Prozess
- API Requests
- Datenbank-Operationen
- Session Status

Aktiviere WordPress Debug-Logging in `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Logs findest du in: `wp-content/debug.log`

## 📋 Voraussetzungen

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+
- HTTPS (für OAuth)
- Discord Bot (optional, für vollständige Integration)

## 🔄 Workflow

1. **Bewerbung einreichen**
   - User meldet sich mit Discord an
   - Füllt Bewerbungsformular aus
   - Bewerbung wird in DB gespeichert
   - Bot erstellt Discord Forum Post

2. **Status ändern**
   - Admin ändert Status im WordPress Admin
   - ODER Bot ändert Status via `/accept`/`/reject`
   - Status wird synchronisiert

3. **Regeln akzeptieren** (nur für accepted)
   - User meldet sich auf `/regeln` an
   - Liest Regeln
   - Gibt Minecraft Name ein
   - Akzeptiert Regeln
   - Erhält Discord Server Invite

## 📞 Support

Bei Fragen oder Problemen:
- GitHub Issues erstellen
- Discord Server kontaktieren

## 📄 Lizenz

GPL2

## 👥 Credits

Entwickelt für JustCreators Season 2

---

**Version:** 6.1  
**Letzte Aktualisierung:** 2025
