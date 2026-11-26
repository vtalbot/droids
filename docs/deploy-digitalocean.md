# Deploy Droids on a DigitalOcean droplet

This guide runs Droids on a single Ubuntu droplet with Nginx, PHP-FPM, SQLite and a cron-driven scheduler, then connects it to GitHub through the GitHub App. Expect about 30 minutes.

What you need before you start:

- A DigitalOcean account.
- A domain or subdomain you can point at the droplet, for example `droids.example.com`. GitHub can only deliver webhooks to a public HTTPS address.
- An SMTP provider (Postmark, Resend, Mailgun or similar) so the app can send the email verification and password reset messages. You can skip this at first and verify your account from the command line (step 9).

Throughout the guide, replace `droids.example.com` with your domain.

## 1. Create the droplet

1. In DigitalOcean, go to **Create > Droplets**.
2. Pick **Ubuntu 24.04 (LTS) x64**.
3. Pick a size. The app itself is light, but `npm run build` needs memory. Choose **2 GB RAM** (Basic, Regular, about $12 a month). A 1 GB droplet works if you add swap (step 2).
4. Add your SSH key and create the droplet.
5. At your DNS provider, add an **A record** for `droids.example.com` that points to the droplet's IPv4 address.

## 2. Prepare the server

SSH in as root:

```bash
ssh root@droids.example.com
```

Update the system and turn on the firewall:

```bash
apt update && apt upgrade -y
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
```

On a 1 GB droplet only, add 2 GB of swap so the front-end build does not run out of memory:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Ubuntu 24.04 ships PHP 8.3, so install PHP 8.5 from the widely used [ondrej/php PPA](https://launchpad.net/~ondrej/+archive/ubuntu/php):

```bash
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update
```

Then install PHP 8.5, the extensions Laravel needs, Nginx, SQLite and Git:

```bash
apt install -y nginx git unzip sqlite3 \
  php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-mbstring php8.5-xml \
  php8.5-curl php8.5-zip php8.5-intl php8.5-bcmath
php -v   # should print PHP 8.5.x
```

OPcache is built into PHP 8.5, so there is no separate `php8.5-opcache` package to install. If `php -v` still shows another version, switch the default with `update-alternatives --set php /usr/bin/php8.5`.

Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Node.js is installed with nvm for the deploy user in the next step.

## 3. Create a deploy user and get the code

Run the app as its own user. That user belongs to the `www-data` group so PHP-FPM can write logs and the database.

```bash
adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy
mkdir -p /var/www && chown deploy:www-data /var/www
```

The repository is private, so give the server read access with a deploy key:

```bash
sudo -u deploy mkdir -p -m 700 /home/deploy/.ssh
sudo -u deploy ssh-keygen -t ed25519 -N "" -f /home/deploy/.ssh/id_ed25519
cat /home/deploy/.ssh/id_ed25519.pub
```

On GitHub, open **vtalbot/droids > Settings > Deploy keys > Add deploy key**, paste the key and leave **Allow write access** off.

From here on, work as the deploy user. A login shell loads nvm once it's installed:

```bash
su - deploy
```

Install [nvm](https://github.com/nvm-sh/nvm). Check its releases page for the newest version number and use it in the URL:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.nvm/nvm.sh
```

Clone the app, then install the Node version it asks for. The repo's `.nvmrc` says `lts/*`, so `nvm install` picks the current LTS release, the same one CI tests on:

```bash
git clone git@github.com:vtalbot/droids.git /var/www/droids
cd /var/www/droids
nvm install
nvm alias default "lts/*"
node -v
```

## 4. Configure the environment

Still as `deploy`:

```bash
cd /var/www/droids
cp .env.example .env
nano .env
```

Change these values and leave the rest as they are:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://droids.example.com

LOG_LEVEL=warning

DB_CONNECTION=sqlite

SESSION_SECURE_COOKIE=true

# Mail: fill in your provider. With "log", emails only go to storage/logs.
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="droids@example.com"

# Keep sign-up closed after your own account exists.
DROIDS_OPEN_REGISTRATION=false
```

Some notes on these values:

- **`APP_URL` must be the final `https://` address before you create the GitHub App.** Droids uses it to decide whether GitHub can reach the webhook. With a `localhost`, `.test` or `.local` address, the App is created with its webhook turned off.
- **SQLite** is enough for a single-person control room, and the database lives in `database/database.sqlite`. If you prefer MySQL or Postgres, set the `DB_*` keys instead.
- There are **no GitHub keys in `.env`**. The App's ID, private key, client secret and webhook secret are created in step 10 and stored in the database, encrypted with `APP_KEY`.

## 5. Install, build and migrate

Still as `deploy`:

```bash
cd /var/www/droids
composer install --no-dev --optimize-autoloader
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
npm ci
npm run build
php artisan optimize
```

Run `migrate` without `--seed`. The seeders add a test user and the sample projects from the design prototype, which you don't want in production.

Back up `APP_KEY` from `.env` somewhere safe, such as a password manager. If it changes or is lost, the stored GitHub App credentials can no longer be decrypted, and you would need to create a new App.

Let PHP-FPM (`www-data`) write to storage, the cache and the database:

```bash
cd /var/www/droids
chgrp -R www-data storage bootstrap/cache database
chmod -R ug+rwX storage bootstrap/cache database
```

SQLite needs write access to the `database` folder as well as the file, because it creates a journal file next to the database.

Type `exit` to go back to root for the next steps.

## 6. Nginx

Create `/etc/nginx/sites-available/droids`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name droids.example.com;
    root /var/www/droids/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;
    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it:

```bash
ln -s /etc/nginx/sites-available/droids /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

## 7. HTTPS with Let's Encrypt

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d droids.example.com --redirect -m you@example.com --agree-tos -n
```

Certbot adds the TLS settings to the server block and renews the certificate automatically.

## 8. Scheduler

Droids pulls from GitHub every 5 minutes (`github:sync`) as a safety net for missed webhooks. Laravel's scheduler drives that, so it needs a cron entry:

```bash
crontab -u www-data -e
```

Add this line:

```cron
* * * * * cd /var/www/droids && php artisan schedule:run >> /dev/null 2>&1
```

It runs as `www-data` so that files it creates belong to the same user as the web app.

No queue worker is needed yet, because nothing is queued today. Phase 4 (droid runners) will add one as a systemd service.

## 9. Create your account

Open `https://droids.example.com/register` and sign up. Registration is open **only until the first account exists**, so do this right after deploying.

Your email address then needs verifying:

- **With SMTP set up**, click the link in the email.
- **Without SMTP**, verify from the server:

    ```bash
    cd /var/www/droids
    sudo -u www-data php artisan tinker --execute="App\Models\User::first()->markEmailAsVerified();"
    ```

You land on the Hangar. It starts empty: no projects, droids or subscriptions.

## 10. Set up the GitHub App

Droids creates the App for you through GitHub's [manifest flow](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-from-a-manifest), so there is nothing to type into GitHub's App form.

1. In Droids, open **Settings > GitHub** and click **Create the GitHub App**.
2. GitHub opens **Create GitHub App for vtalbot** with a name like `Droids droids k3f9` already filled in. You can rename it, because App names are unique across GitHub. Click **Create GitHub App**.
3. GitHub sends you back to Droids. Droids saves the App's ID, private key, client secret and webhook secret (encrypted), then forwards you to GitHub's install page.
4. On the install page, choose **Only select repositories**, pick the repos Droids should work on (for example `vtalbot/droids`), and click **Install**.
5. GitHub sends you back to **Settings > GitHub**, which now lists the App, the installation, and a **Sync now** button.
6. Go to the Hangar, click **Connect repo**, pick a repository from the list and click **Connect**. Droids links the project and runs the first sync. Milestones become the roadmap, issues become board cards, and open PRs bring their reviews and checks.

### What the App is allowed to do

The manifest asks for exactly these permissions. GitHub shows them on the install page.

| Permission      | Access | Used for                                        |
| --------------- | ------ | ----------------------------------------------- |
| Metadata        | Read   | Required by every App                           |
| Contents        | Write  | Squash-merging approved pull requests           |
| Issues          | Write  | Reading issues and creating new tasks as issues |
| Pull requests   | Write  | Reading PRs and reviews, merging                |
| Checks          | Read   | Showing check runs on PRs                       |
| Commit statuses | Read   | Showing legacy status checks                    |
| Actions         | Write  | Re-running failed workflow jobs                 |

The App subscribes to these webhook events: `issues`, `milestone`, `pull_request`, `pull_request_review`, `check_run` and `check_suite`.

The manifest also sets these URLs, all taken from your domain:

| GitHub App setting           | Value                                                  |
| ---------------------------- | ------------------------------------------------------ |
| Homepage URL                 | `https://droids.example.com`                           |
| Webhook URL                  | `https://droids.example.com/github/webhook`            |
| Webhook secret               | Generated by GitHub, stored by Droids                  |
| Callback after creating      | `https://droids.example.com/settings/github/created`   |
| Setup URL (after installing) | `https://droids.example.com/settings/github/installed` |

The App is private, so only your account can install it.

### Checking that it works

- **Webhooks:** on GitHub, open **Settings > Developer settings > GitHub Apps > your App > Advanced**. Recent deliveries should show `202` responses. A `401` means the signature didn't match; the most likely cause is a changed `APP_KEY` or a different App.
- **Manual sync:** run `sudo -u www-data php artisan github:sync` on the server, or press **Sync** on a project's board.
- **Live test:** open an issue in a connected repo. It should appear in that project's Backlog within a few seconds.

### If something goes wrong

- **"This GitHub App setup link has expired"** means the session expired between clicking Create and coming back, or you came back in a different browser. Start again from Settings > GitHub. If GitHub already created the App, delete it under Developer settings first, or just pick a different name.
- **The repository list in Connect repo is empty** when the App is not installed on any repo yet, or every installed repo is already connected. Use **Change repository access** in Settings > GitHub.
- **If you move to a new domain**, update the Webhook URL, Callback URL and Setup URL in the App's settings on GitHub, and update `APP_URL`.

## 11. Deploying updates

Save this as `/var/www/droids/deploy.sh` (it is not tracked by git), make it executable with `chmod +x`, and run it as `deploy` after each merge to `main`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/droids

# nvm isn't loaded in non-interactive shells, so load it here.
export NVM_DIR="$HOME/.nvm"
source "$NVM_DIR/nvm.sh"

php artisan down --retry=15
git pull --ff-only origin main
nvm install   # follows .nvmrc; moves to a new LTS when one is released
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan up

sudo systemctl reload php8.5-fpm
```

The last line reloads PHP-FPM, which clears OPcache. For `deploy` to run it without a password, add a sudoers rule with `visudo -f /etc/sudoers.d/deploy`:

```
deploy ALL=NOPASSWD: /usr/bin/systemctl reload php8.5-fpm
```

## 12. Backups

Two things matter:

- **`database/database.sqlite`** holds everything, including the encrypted GitHub App credentials. Turn on DigitalOcean's droplet backups, or copy the file nightly with `sqlite3 database/database.sqlite ".backup /path/to/backup.sqlite"`.
- **`.env`**, and in particular `APP_KEY`, because the stored secrets cannot be decrypted without it.
