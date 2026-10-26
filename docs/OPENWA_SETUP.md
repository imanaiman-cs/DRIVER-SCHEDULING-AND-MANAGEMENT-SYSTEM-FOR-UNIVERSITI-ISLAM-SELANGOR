# Setting up OpenWA for driver WhatsApp messages

OpenWA (https://github.com/rmyndharis/OpenWA) is an unofficial WhatsApp gateway. The DSMS uses it only to send a short WhatsApp message
to **drivers**, in addition to the in-system notification and the e-mail. If OpenWA is off or fails, nothing else is affected
(the attempt is written to the table `whatsapp_log`).

**Read first:** WhatsApp can restrict a number that is used by unofficial software. Use a **dedicated number** (never a personal or
main office number), keep volume low (a few messages per trip), and keep e-mail as the guaranteed channel. OpenWA's own README says it
is not approved for regulated or compliance-sensitive deployments; ask your IT/compliance office before using it for university business.

## 1. Run OpenWA (on a PC or server that stays on)
Needs Docker (Docker Desktop on Windows).

```
git clone https://github.com/rmyndharis/OpenWA.git
cd OpenWA
docker compose -f docker-compose.dev.yml up -d
```
Dashboard and API: `http://localhost:2785` (API under `/api`).

## 2. Admin key
```
docker exec openwa-api cat /app/data/.api-key
```
Copy only the key. Use it to sign in to the dashboard.

## 3. Connect the sending phone
In the dashboard: create a session (for example `uis`), press Start, scan the QR code with the dedicated phone
(WhatsApp > Linked devices). Keep the engine `whatsapp-web.js` (lower ban risk). Optional: set `SEND_PACING_ENABLED=true`.
Note the session id shown in the dashboard.

## 4. A key for the DSMS
Create an **Operator** API key limited to that session (not the admin key). Copy it when it is shown: it is shown once.

## 5. Tell the DSMS
Copy `config/whatsapp.sample.php` to `config/whatsapp.php` (this file is not uploaded to GitHub) and fill in:

```php
'enabled'    => true,
'base_url'   => 'http://localhost:2785',
'api_key'    => '<operator key>',
'session_id' => '<session id>',
'redirect_to'=> '60123456789',   // first test: everything goes to your own number; empty it afterwards
```
Driver numbers come from the driver's phone number (users.phone, or drivers.phone). `012-345 6789` becomes `60123456789`.

## 6. Check
Confirm a request (Head of Admin) so a driver is assigned, then look at `whatsapp_log`:
`sent` = delivered to OpenWA, `failed` = OpenWA unreachable or refused (see `error_message`), `skipped` = disabled or no valid number.

## Operations
- Keep the OpenWA data volume (`/app/data`): it holds the linked session; losing it means scanning a new QR.
- Do not expose port 2785 to the internet. If the DSMS runs on another machine, put OpenWA behind a reverse proxy with TLS and an IP allow-list.
- WhatsApp may silently drop a first message to a number that never messaged you; ask drivers to save the number and send it a "hello" once.
