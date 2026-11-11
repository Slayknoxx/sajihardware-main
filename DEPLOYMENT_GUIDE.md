# SAJI Hardware POS Deployment Guide

This guide explains how to deploy the SAJI Hardware POS system in two modes:

1. Online deployment for a hosted server or VPS
2. Offline deployment for a single computer or local network

The system is PHP-based and uses SQLite for local storage, so it can work well both offline and online. The KCB M-Pesa STK feature requires internet access, but the POS itself can still run offline for cash sales and local stock management.

---

## 1. System overview

This project is designed for:

- POS sales and stock tracking
- Product management
- Customer and supplier records
- Quotations and held sales
- Cash and sales reports
- KCB STK push / M-Pesa integration
- Local SQLite database

Files important to deployment:

- [router.php](router.php) - main request router
- [api.php](api.php) - main API entry point
- [public/index.php](public/index.php) - frontend entry point
- [config.php](config.php) - app settings
- [lib.php](lib.php) - shared logic and DB setup
- [.env](.env) - environment configuration
- [data/saji_pos.sqlite3](data/saji_pos.sqlite3) - local SQLite database

---

## 2. Recommended deployment options

### Option A: Offline single computer
Use this when:

- the business is running on one PC
- no internet hosting is needed
- local sales and stock operations are enough
- you want a simple setup without cloud hosting

Best choice:

- XAMPP on Windows
- Apache + PHP + SQLite

### Option B: Online hosted deployment
Use this when:

- multiple staff need access from different devices
- you want remote access from another PC or browser
- you want to keep the app on a VPS or cloud server

Best choice:

- Ubuntu/Debian VPS
- Nginx or Apache
- PHP 8.2+
- SQLite or MariaDB/MySQL
- SSL certificate

---

## 3. Offline deployment on one computer (recommended for local use)

### Step 1: Install XAMPP

1. Download XAMPP for Windows.
2. Install it at:
   - C:\xampp
3. Make sure Apache and PHP are installed.
4. Open XAMPP Control Panel and start Apache.

### Step 2: Place the project in the web root

Copy the project folder into:

- C:\xampp\htdocs\sarihardware

Your project should look like this:

- C:\xampp\htdocs\sarihardware\
  - router.php
  - api.php
  - public/
  - data/
  - lib.php
  - config.php
  - .env

### Step 3: Check PHP configuration

1. Open:
   - C:\xampp\php\php.ini
2. Confirm these are correctly set:
   - extension_dir = "C:/xampp/php/ext"
   - pdo_sqlite enabled
   - sqlite3 enabled
   - curl enabled
   - openssl enabled
3. Save the file.
4. Restart Apache from XAMPP.

### Step 4: Run the app

Use the project root router, not the public folder directly.

Open a terminal in the project folder and run:

```powershell
cd "C:\xampp\htdocs\sarihardware"
& "C:\xampp\php\php.exe" -c ".\php.ini" -S localhost:8000 router.php
```

Then open:

```text
http://localhost:8000/
```

This is the safest way to serve the app because [router.php](router.php) handles both:

- the web interface
- the API routes
- callback routes

### Step 5: Log in

Default admin user:

- Username: admin
- Password: admin123

Change it after the first login.

### Step 6: Offline use

This build works offline because it uses SQLite locally and does not require an online database server.

For full offline operations:

- keep Apache running on the machine
- keep the database file in the project folder
- do not depend on the KCB callback endpoint unless internet is available

> Note: M-Pesa STK push will require internet connectivity and the KCB credentials to work. Cash sales and local stock management still work normally offline.

---

## 4. Online deployment to a VPS or hosting server

### Step 1: Prepare the server

Use any Linux server with:

- Ubuntu 22.04 or Debian 12 recommended
- PHP 8.2+
- Apache or Nginx
- SQLite support or MariaDB/MySQL
- SSL certificate

Install required packages:

```bash
sudo apt update
sudo apt install -y apache2 php8.2 php8.2-sqlite3 php8.2-curl php8.2-xml unzip curl
```

If using Nginx instead:

```bash
sudo apt install -y nginx php8.2-fpm php8.2-sqlite3 php8.2-curl
```

### Step 2: Upload the project files

Upload the full project folder to the server, for example:

```text
/var/www/saji-pos/
```

Make sure the project includes:

- [router.php](router.php)
- [api.php](api.php)
- [public/index.php](public/index.php)
- [data/](data/)
- [.env](.env)

### Step 3: Configure the web root

If using Apache, set the DocumentRoot to the project folder or use a virtual host.

Example:

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/saji-pos
    <Directory /var/www/saji-pos>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable the site:

```bash
sudo a2enmod rewrite
sudo a2ensite yoursite.conf
sudo systemctl reload apache2
```

### Step 4: Enable PHP and route requests correctly

Because the app uses a front controller, make sure the server routes all requests to [router.php](router.php).

For Apache, ensure the project has a .htaccess file or rewrite rules.

Example:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

If the project is served directly with Apache, the app should still work if the root folder is mapped to the project and [router.php](router.php) is used as the front controller.

### Step 5: Set file permissions

Set permissions for the app and database:

```bash
sudo chown -R www-data:www-data /var/www/saji-pos
sudo chmod -R 775 /var/www/saji-pos/data
sudo chmod -R 755 /var/www/saji-pos
```

This ensures the PHP process can read and write the SQLite database and logs.

### Step 6: Configure environment variables

Open [.env](.env) and set values for:

- APP_NAME
- COMPANY_NAME
- COMPANY_EMAIL
- COMPANY_LOCATION
- KRA_PIN
- ETIMS settings if needed
- KCB credentials if M-Pesa is enabled

Example:

```env
ETIMS_ENABLED=false
KRA_PIN=YOUR_KRA_PIN_HERE
KCB_CONSUMER_KEY=YOUR_KEY
KCB_CONSUMER_SECRET=YOUR_SECRET
KCB_ORG_SHORT_CODE=174379
KCB_CALLBACK_TOKEN=YOUR_CALLBACK_SECRET
```

### Step 7: Enable HTTPS

Use Let’s Encrypt to secure production traffic:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

This is strongly recommended for online use.

### Step 8: Test the site

Open the server in a browser:

```text
https://yourdomain.com/
```

Then login with the admin account and make a test sale.

---

## 5. Online + offline hybrid setup

This is the best model for a business shop that needs both local and hosted access.

### Recommended hybrid design

- Local machine runs the POS in offline mode using XAMPP
- A shared hosted copy runs the same app online for administration or remote monitoring
- Each environment keeps its own SQLite database file or the same DB is copied periodically

### Example business pattern

- Daily sales happen on the local machine
- The local computer stays online or offline depending on internet connectivity
- Backups are exported to a shared folder or cloud storage
- The system is restored from backup when needed

This gives you:

- offline reliability
- local operation without downtime
- optional online access for reporting or central management

---

## 6. Backup strategy

Back up the SQLite database often.

### Backup command

The project already includes backup helpers and backup directory support.

Recommended approach:

- Daily backup at midnight
- Copy [data/saji_pos.sqlite3](data/saji_pos.sqlite3) to a safe folder
- Keep at least 7 days of backups
- Save one backup outside the server or computer

Example:

```bash
cp /var/www/saji-pos/data/saji_pos.sqlite3 /backup/saji-pos-$(date +%F).sqlite3
```

On Windows:

```powershell
Copy-Item "C:\xampp\htdocs\sarihardware\data\saji_pos.sqlite3" "D:\Backups\saji-pos-backup.sqlite3"
```

---

## 7. KCB / M-Pesa online requirements

The KCB STK push feature works only when:

- the server has internet access
- PHP cURL is enabled
- the correct KCB credentials are in [.env](.env) or config files
- the callback URL is reachable publicly
- the callback secret matches the configured value

If the system is offline, cash sales and stock operations still work, but M-Pesa STK will not function until internet is available.

---

## 8. Security checklist before going online

Before deployment:

- Change the default admin password
- Use HTTPS
- Restrict file permissions
- Keep the database file outside public access if possible
- Add firewall rules
- Use a strong callback secret
- Keep backups encrypted if possible
- Only expose necessary ports

---

## 9. Quick deployment summary

### Offline computer

1. Install XAMPP
2. Place the project under htdocs
3. Start Apache
4. Run the PHP router on localhost:8000
5. Log in with admin/admin123
6. Use the app normally

### Online server

1. Deploy PHP app to a VPS or hosted server
2. Configure Apache/Nginx and PHP
3. Set DocumentRoot to the project directory
4. Enable rewrite/routing with [router.php](router.php)
5. Configure [.env](.env)
6. Enable HTTPS
7. Test login and sales flow
8. Set up backup automation

---

## 10. Final recommendation

For a shop that needs reliability:

- use the XAMPP local install as the main offline working environment
- keep an online VPS copy for remote access and staging
- use regular SQLite backups
- activate KCB M-Pesa only on the online environment with proper HTTPS and callback URL setup

This gives you strong reliability and a smooth path from local to public deployment.
