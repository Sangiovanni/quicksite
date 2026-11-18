# QuickSite Cron Jobs

## nginx Configuration Reload (`nginx_reload.sh`)

> **Note:** This cron job is a **fallback**. The recommended way is a sudoers
> rule (see below) that lets QuickSite reload nginx itself when it needs to,
> with no cron at all.

### How nginx reloading works

When the public space configuration changes (via setup scripts), QuickSite:
1. Updates `secure/nginx/dynamic_routes.conf`
2. Tries `sudo nginx -t && sudo nginx -s reload` directly from PHP
3. If that works → done, instant reload
4. If that fails (no sudoers rule, `shell_exec` disabled) → sets a `.pending_reload` flag

The cron script handles case 4: root runs it every minute; it finds the flag and reloads nginx.

### Recommended setup: sudoers (no cron needed)

Let the web server user run the two nginx commands as root, and nothing else:

```bash
sudo tee /etc/sudoers.d/quicksite-nginx > /dev/null <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t
www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -s reload
EOF
sudo chmod 440 /etc/sudoers.d/quicksite-nginx
sudo visudo -cf /etc/sudoers.d/quicksite-nginx
```

Replace `www-data` with your PHP process user (check with `ps aux | grep php`), and
`/usr/sbin/nginx` with the path `command -v nginx` prints, if it is another.

> ⚠ **Never write the rule without the arguments** (`NOPASSWD: /usr/sbin/nginx` alone).
> sudo reads a command with no arguments as *any* arguments, so the web server user
> could start nginx as root with a configuration of its own choosing.

`sudo -l -U www-data` lists what the user may run: those two lines and nothing else.
After this, QuickSite reloads nginx directly — no cron, no polling.

### Fallback setup: cron (if sudoers is not possible)

Root runs the script, so root runs **its own copy, kept outside the QuickSite folder**.
The script in this folder belongs to the web server user (`setup.sh` gives it the whole
QuickSite folder), which could rewrite it before root's next run: root's crontab must
never name it.

**Step 1 — Install a root-owned copy:**

```bash
sudo install -o root -g root -m 0755 /path/to/secure/cron/nginx_reload.sh /usr/local/sbin/quicksite-nginx-reload
```

Install it again after an update that changes `nginx_reload.sh` (compare first:
`diff /path/to/secure/cron/nginx_reload.sh /usr/local/sbin/quicksite-nginx-reload`).

**Step 2 — Include the config in your nginx server block (one-time):**

Edit your nginx vhost configuration and add:

```nginx
server {
    # ... your existing config ...
    
    include /path/to/secure/nginx/dynamic_routes.conf;
}
```

Then test and reload: `sudo nginx -t && sudo nginx -s reload`

**Step 3 — Add the cron job to root's crontab:**

```bash
sudo crontab -e
```

Add this line (checks every minute), with your install's secure folder as the argument:

```
* * * * * /usr/local/sbin/quicksite-nginx-reload /path/to/secure
```

**Set up under the old instructions?** If root's crontab names
`/path/to/secure/cron/nginx_reload.sh` itself, do steps 1 and 3 and delete that line.
Run as root, `setup.sh` warns while such a line is there.

### How it works

The copy takes the install's secure folder as its argument and looks for the flag at
`<secure>/nginx/.pending_reload`. When the flag is there, as a plain file, it tests the
configuration (`nginx -t`): when the test passes it reloads nginx (`nginx -s reload`) and
removes the flag; when it fails, it keeps the flag for the next run. A link in the flag's
place is refused. It calls nginx by its full path, `/usr/sbin/nginx`, since cron's own
PATH holds no `/usr/sbin`: if yours is elsewhere, change the `NGINX=` line in your copy.

```
secure/
├── cron/
│   ├── nginx_reload.sh    ← what root's copy is installed from
│   └── README.md          ← you are here
└── nginx/
    ├── dynamic_routes.conf  ← auto-generated nginx config
    └── .pending_reload      ← flag file (created by QuickSite, removed by the copy)
```

### Logs

The copy writes nothing in the QuickSite folder. Every reload attempt, success or
failure, goes to the system log, tagged `quicksite-nginx-reload`:

```bash
journalctl -t quicksite-nginx-reload
```

(or `grep quicksite-nginx-reload /var/log/syslog`). A reload QuickSite makes itself,
through sudo, is logged in `secure/logs/nginx_reload.log`.

### If not using cron

**Manual reload:** After any public space change, you can reload manually:

```bash
sudo nginx -t && sudo nginx -s reload
```

### Apache users

If you're using Apache, you can safely ignore this entire directory. Apache picks up 
`.htaccess` changes automatically without any reload.
