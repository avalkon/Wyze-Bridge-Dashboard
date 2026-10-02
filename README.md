## Wyze Recording Dashboard

A lightweight add-on Apache/PHP dashboard for `docker-wyze-bridge`. Runs perfectly fine on old hardware. Requires https://github.com/mrlt8/docker-wyze-bridge. Not tested with any other forks.

Features:

- Login screen with PHP sessions and password hashing
- Multi-user with one admin and x number of viewers, management of users in the web UI
- Video grid with secure MP4 streaming (recordings stay outside the web root)
- Motion event history
- Recording schedules by day/time
- Adjustable clip length, rolling retention, and motion protection window
- Start/stop recording per camera
- Restart `wyze-bridge` and `motion-recorder` from the UI
- Container status display
- Motion-event thumbnails and event-to-video links
- Timeline-style events page
- Protect/unprotect recordings from automatic deletion
- Manual download/delete controls
- Disk usage display and high-usage warning
- No Docker socket exposed to Apache


## Architecture

```
Browser
   |
Apache/PHP (:80 or :443)
   |
   +---- reads recordings/events
   |
   +---- localhost HTTP ----> wyze-dashboard-helper (systemd, root)
                                  |
                                  +---- writes dashboard-settings.json
                                  +---- allowlisted docker compose restart

docker-wyze-bridge
   |
   +---- RTSP ------> motion-recorder (ffmpeg -c copy)
   |
   +---- motion webhook ------> motion-recorder
```

The recorder does not perform image analysis. It uses Wyze motion events, so CPU use stays low.

## Important security notes

Do **not** add `www-data` to the `docker` group and do **not** mount
`/var/run/docker.sock` into Apache/PHP. Docker control is effectively root
access. This project uses a small localhost-only helper with an allowlist.

Put the dashboard behind HTTPS if it is reachable beyond your trusted LAN.

## Assumptions

These directions assume:

- Debian/Ubuntu-style Apache paths
- PHP 8.x
- Docker Compose v2 (`docker compose`)
- Wyze-Bridge is named `wyze-bridge`
- Video recorder is named `motion-recorder`

Adjust paths for other distributions. Includes inside of various files, probably want to check most of them.

## Docker requirements:

docker-ce 
docker-ce-cli 
containerd.io 
docker-buildx-plugin 
docker-compose-plugin

## Get the files and build the web UI side

```bash
cd ~/Downloads/
git clone https://github.com/avalkon/Wyze-Bridge-Dashboard.git ./wyze-dashboard
cd wyze-dashboard
```

If you already have Docker-Wyze-Bridge running, you should rename either your original docker-compose.yml, or the 
one included here before copying into the directory. Copy the motion-recorder section into your wyze-bridge yml, change 
any necessary settings. Or just use the included one if you don't have any custom settings.

```bash
sudo apt install apache2 php libapache2-mod-php php-curl ffmpeg python3
sudo mkdir -p /var/www/wyze-dashboard
sudo cp -a web/. /var/www/wyze-dashboard/
sudo chown -R root:www-data /var/www/wyze-dashboard
sudo find /var/www/wyze-dashboard -type d -exec chmod 750 {} \;
sudo find /var/www/wyze-dashboard -type f -exec chmod 640 {} \;

sudo nano /etc/apache2/sites-available/wyze-dashboard.conf
```
Inside:

```apache
<VirtualHost *:80>
    ServerName cameras.local
    DocumentRoot /var/www/wyze-dashboard

    <Directory /var/www/wyze-dashboard>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    # Keep config/state directories outside DocumentRoot.
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/wyze-dashboard-error.log
    CustomLog ${APACHE_LOG_DIR}/wyze-dashboard-access.log combined
</VirtualHost>
```

Reload Apache

```bash
sudo a2ensite wyze-dashboard.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Create the helper relay service:

```bash
sudo cp ./systemd/wyze-dashboard-helper.service /etc/systemd/system/wyze-dashboard-helper.service
sudo systemctl daemon-reload
sudo systemctl enable --now wyze-dashboard-helper
```

## And now the app files

```bash
sudo cp -r ./opt-wyze /opt/wyze
sudo cp -r ./etc-wyze-dashboard /etc/wyze-dashboard
sudo cp -r ./opt-wyze-dashboard /opt/wyze-dashboard
sudo cp -r ./var-lib-wyze-dashboard /var/lib/wyze-dashboard
sudo cp -r ./var-www-wyze-dashboard /var/www/wyze-dashboard

sudo groupadd -f wyze-recordings
sudo usermod -aG wyze-recordings www-data
sudo usermod -aG wyze-recordings yourusername

sudo chown -R root:www-data /var/www/wyze-dashboard
sudo find /var/www/wyze-dashboard -type d -exec chmod 750 {} \;
sudo find /var/www/wyze-dashboard -type f -exec chmod 640 {} \;

sudo chown root:www-data /var/lib/wyze-dashboard
sudo chmod 770 /var/lib/wyze-dashboard
sudo chown root:www-data /var/lib/wyze-dashboard/users.json
sudo chmod 660 /var/lib/wyze-dashboard/users.json

sudo chown root:www-data -r /etc/wyze-dashboard
sudo chmod 750 /etc/wyze-dashboard
sudo chmod 600 /etc/wyze-dashboard/helper.env
sudo chmod 640 /etc/wyze-dashboard/dashboard.ini

sudo chown root:www-data -r /opt/wyze-dashboard/
sudo chmod 750 /opt/wyze-dashboard/helper.py

sudo chown -R root:root -r /opt/wyze
sudo chmod 754 -r /opt/wyze
sudo chown -R root:wyze-recordings /opt/wyze/recordings
sudo find /opt/wyze/recordings -type d -exec chmod 2750 {} \;
sudo find /opt/wyze/recordings -type f -exec chmod 640 {} \;
```

## Now the config

Create a random token:

```bash
openssl rand -hex 32
```

Copy your random token and open

```bash
sudo nano /etc/wyze-dashboard/dashboard.ini
```

```ini
recordings_dir = /opt/wyze/recordings
events_file = /opt/wyze/motion-state/events.json
settings_file = /opt/wyze/config/dashboard-settings.json
helper_url = http://127.0.0.1:8765
helper_token = PUT_RANDOM_TOKEN_HERE
timezone = America/New_York
```

```bash
sudo nano /etc/wyze-dashboard/helper.env
```

```bash
DASHBOARD_TOKEN=PUT_RANDOM_TOKEN_HERE
COMPOSE_DIR=/opt/wyze
SETTINGS_FILE=/opt/wyze/config/dashboard-settings.json
HOST=127.0.0.1
PORT=8765
RECORD_ROOT=/opt/wyze/recordings
PROTECTED_FILE=/opt/wyze/motion-state/protected.json
```

Create your password hash:

```bash
#Include the ''
sudo php /var/www/wyze-dashboard/make-password.php 'your-long-password'
#Copy it
sudo nano /var/lib/wyze-dashboard/users.json
#Paste it, and change your username. You can change the other users from the web interface later
```

Set your camera names:

```bash
sudo nano /opt/wyze/config/dashboard-settings.json
```

```json
{
  "segment_seconds": 300,
  "delete_unprotected_after_minutes": 30,
  "motion_before_seconds": 300,
  "motion_after_seconds": 300,
  "keep_motion_days": 30,
  "cameras": {
    "cameraname": {"enabled": true, "schedule": [{"days": [0,1,2,3,4,5,6], "start": "00:00", "end": "23:59"}]}
  }
}
```

Build and run it all

```bash
docker compose up -d --build
sudo systemctl restart apache2
```

Check port 5000 for wyze-bridge and your apache port(80 if unchanged) for the dashboard If you're already serving a site through apache, add the virtualhost to your existing site.
