# Installing the DSMS and OpenWA on one VPS (Ubuntu 22.04 / 24.04)

Tested plan: Hostinger KVM 2 (2 vCPU, 8 GB RAM). KVM 1 (4 GB) is enough for one WhatsApp session. Commands below were written for Ubuntu
and were **not** run on a Hostinger VPS from the development environment: follow them step by step and read each result.

Why Apache and not Nginx: the project protects `config/`, `includes/`, `cron/`, `tests/`, `migrations/`, `tools/` and `*.sql` with `.htaccess`
files. Nginx ignores `.htaccess`, so on Nginx you would have to recreate those rules by hand.

## 0. Before you start
- A domain or sub-domain (for example `dsms.yourdomain.my`) pointing to the VPS IP (A record). HTTPS needs it.
- Log in with SSH as root once, then work as a normal user with sudo. Use SSH keys, not a password. Never paste passwords into chats or Git.

## 1. Base system and firewall
```
apt update && apt -y upgrade
adduser deploy && usermod -aG sudo deploy          # then log in as deploy
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
sudo timedatectl set-timezone Asia/Kuala_Lumpur
```
Port 2785 (OpenWA) is **not** opened: OpenWA stays on the VPS itself (step 6).

## 2. Apache, PHP, MariaDB
```
sudo apt -y install apache2 mariadb-server php php-mysql php-mbstring php-xml php-curl php-gd php-zip libapache2-mod-php git unzip
sudo a2enmod rewrite headers && sudo systemctl restart apache2
sudo mysql_secure_installation
```
Allow `.htaccess`: in `/etc/apache2/sites-available/000-default.conf` (or your own vhost) inside `<VirtualHost>` add

```
<Directory /var/www/dsms>
    AllowOverride All
    Require all granted
</Directory>
```

## 3. Get the code and the database
```
sudo git clone https://github.com/imanaiman-cs/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR.git /var/www/dsms
sudo chown -R www-data:www-data /var/www/dsms/uploads
sudo mysql
```
In the MariaDB prompt (use your own strong password, not this text):
```
CREATE USER 'dsms'@'localhost' IDENTIFIED BY 'CHANGE-ME';
GRANT ALL PRIVILEGES ON uis_driver_db.* TO 'dsms'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```
`database.sql` creates the database itself; import it as root, then the `dsms` user has access to it:
```
sudo mysql < /var/www/dsms/database.sql
```
Create the server settings (this file is not in Git):
```
sudo cp /var/www/dsms/config/local.sample.php /var/www/dsms/config/local.php
sudo nano /var/www/dsms/config/local.php     # db_user dsms, db_pass, site_url https://dsms.yourdomain.my
sudo chown root:www-data /var/www/dsms/config/local.php && sudo chmod 640 /var/www/dsms/config/local.php
```
The demo accounts in `LOGIN_ACCOUNTS.txt` have well-known passwords: **delete or change them before real use** (the real accounts come from
`tools/import_staff.php`).

## 4. HTTPS
```
sudo apt -y install certbot python3-certbot-apache
sudo certbot --apache -d dsms.yourdomain.my
```
Open `https://dsms.yourdomain.my/login.php` and sign in with a demo account to check.

## 5. Hourly job and e-mail
```
sudo crontab -u www-data -e
# add this line:
0 * * * * /usr/bin/php /var/www/dsms/cron/run_jobs.php >> /var/log/dsms-cron.log 2>&1
```
E-mail: copy `config/mail.sample.php` to `config/mail.php`, fill in the SMTP details (Hostinger e-mail or the university's SMTP) and set `enabled` to true.

## 6. OpenWA (WhatsApp) on the same VPS
```
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker deploy                   # log out and in again
git clone https://github.com/rmyndharis/OpenWA.git ~/OpenWA && cd ~/OpenWA
```
Keep OpenWA reachable only from the VPS itself. Docker publishes ports around `ufw`, so bind the port to localhost explicitly. Create
`docker-compose.override.yml` next to the compose file you use (the service name is `openwa-api` in the OpenWA README; check it):

```
services:
  openwa-api:
    ports: !override
      - "127.0.0.1:2785:2785"
```
Then start it and check from the VPS (not from the internet):
```
docker compose -f docker-compose.yml -f docker-compose.override.yml up -d
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:2785/api/docs      # answers on the VPS
```
From your laptop, `http://<VPS-IP>:2785` must **not** answer. To use the dashboard (scan the QR, create keys) open an SSH tunnel from your laptop:
```
ssh -L 2785:127.0.0.1:2785 deploy@<VPS-IP>        # then browse http://localhost:2785 on the laptop
```
Follow `docs/OPENWA_SETUP.md` from step 2 (admin key, session, QR with a dedicated phone, operator key). Then in `/var/www/dsms/config/whatsapp.php`
(copy of `whatsapp.sample.php`): `base_url` `http://127.0.0.1:2785`, the operator `api_key`, the `session_id`, `enabled` true.

## 7. Backups and updates
- Database: `mysqldump uis_driver_db | gzip > /root/backup/dsms-$(date +%F).sql.gz` from a nightly cron; copy it off the VPS. Also use the Hostinger snapshot/backup option.
- OpenWA data volume (`/app/data` in the container) holds the linked WhatsApp session; losing it means scanning the QR again.
- Update the DSMS: `cd /var/www/dsms && sudo git pull` (new migrations are applied automatically the next time a page is opened).
- `sudo apt update && sudo apt upgrade` regularly; renew of HTTPS is automatic (certbot timer).

## 8. Checklist before real users
- [ ] HTTPS works, `http://` redirects to `https://`
- [ ] `https://dsms.yourdomain.my/config/database.php`, `/database.sql`, `/migrations/` answer **403/404**
- [ ] Demo accounts removed or passwords changed; real staff imported
- [ ] Cron line runs (check `/var/log/dsms-cron.log` after the hour)
- [ ] A test request goes Pending to Confirmed; the e-mail arrives; `email_log` shows `sent`
- [ ] `whatsapp_log` shows `sent` with `redirect_to` set to your own number, then clear `redirect_to`
- [ ] `http://<VPS-IP>:2785` does not answer from outside
- [ ] Backups run and one restore was tried
