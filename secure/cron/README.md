# QuickSite Cron Jobs

## nginx Configuration Reload (`nginx_reload.sh`)

> **Note:** This cron job is a **fallback**. The recommended approach is the
> one-line sudoers setup (see below), which lets QuickSite reload nginx
> directly when needed — no cron required.

### How nginx reloading works

When `setPublicSpace` is called, QuickSite:
1. Updates `secure/nginx/dynamic_routes.conf`
2. Tries `sudo nginx -t && sudo nginx -s reload` directly from PHP
3. If that works → done, instant reload
4. If that fails (no sudoers, `shell_exec` disabled) → sets a `.pending_reload` flag

The cron script handles case 4: it watches for the flag and reloads nginx.

### Recommended setup: sudoers (no cron needed)

Grant the web server user permission to reload nginx:

```bash
echo 'www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx' | sudo tee /etc/sudoers.d/quicksite-nginx
sudo chmod 440 /etc/sudoers.d/quicksite-nginx
```

Replace `www-data` with your PHP process user (check with `ps aux | grep php`).

After this, QuickSite reloads nginx directly — no cron, no polling.

### Fallback setup: cron (if sudoers is not possible)

**Step 1 — Make the script executable:**

```bash
chmod +x /path/to/secure/cron/nginx_reload.sh
```

**Step 2 — Include the config in your nginx server block (one-time):**

Edit your nginx vhost configuration and add:

```nginx
server {
    # ... your existing config ...
    
    include /path/to/secure/nginx/dynamic_routes.conf;
}
```

Then test and reload: `nginx -t && nginx -s reload`

**Step 3 — Add the cron job:**

```bash
sudo crontab -e
```

Add this line (checks every minute):

```
* * * * * /path/to/secure/cron/nginx_reload.sh
```

### How it works

The script uses `dirname` to auto-detect its own location. It finds the flag file at 
`../nginx/.pending_reload` relative to the `cron/` directory. No hardcoded paths needed — 
it works regardless of where QuickSite is installed.

```
secure/
├── cron/
│   ├── nginx_reload.sh    ← this script (added to crontab)
│   └── README.md          ← you are here
├── nginx/
│   ├── dynamic_routes.conf  ← auto-generated nginx config
│   └── .pending_reload      ← flag file (created by QuickSite, removed by cron)
└── logs/
    └── nginx_reload.log     ← reload attempt logs
```

### Logs

Reload attempts (successes and failures) are logged to `secure/logs/nginx_reload.log`.

### If not using cron

**Manual reload:** After any `setPublicSpace` call, you can reload manually:

```bash
nginx -t && nginx -s reload
```

**Sudoers approach:** Grant the web server user permission to reload nginx without a password:

```
# Add to /etc/sudoers.d/quicksite-nginx
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -s reload
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t
```

This would allow future QuickSite versions to reload nginx directly from PHP.

### Apache users

If you're using Apache, you can safely ignore this entire directory. Apache picks up 
`.htaccess` changes automatically without any reload.
