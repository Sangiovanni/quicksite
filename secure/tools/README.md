# QuickSite deployer tools

Scripts the operator runs on the server itself, from a shell. Each one acts on the
whole installation rather than on one project, so it is neither a command nor a
panel page: no account can be given the right to run it, and having shell access
to the server is what authorises it. Each one refuses to run from a web request.

(The nginx reload fallback is a separate, cron-only script: see `../cron/README.md`.)

---

## `session-sweep.php` — tidy the session store

### What it does

Every sign-in leaves a small session file in `secure/tmp/sessions/`. A session
nobody uses for `idle_ttl` (one day by default, in `auth.php`) stops being
accepted, but its file stays until something removes it. The sweep removes:

- empty files older than an hour;
- sign-ins unused for longer than `idle_ttl`, which the installation already refuses;
- files that hold no sign-in at all, untouched for longer than the longest lifetime
  the installation promises (`remember_ttl`, 30 days by default).

Nothing else. A session someone is using is never removed.

### When you need it

Usually never. One sign-in in ten (`sweep_divisor` in `auth.php`) also sweeps the
store, once that sign-in has its answer. Run it yourself when:

- nobody signs in on the installation for a long while;
- the installation's sign-ins all go through the `login` command on Apache's
  mod_php, which cannot sweep without delaying its answer, so it does not;
- you want it done now, or want to see what it would remove.

### How to run it

From the installation's root folder:

```bash
php secure/tools/session-sweep.php             # sweep now
php secure/tools/session-sweep.php --dry-run   # show what would go; remove and change nothing
php secure/tools/session-sweep.php --quiet     # print nothing (for cron)
php secure/tools/session-sweep.php --help
```

If the `secure` folder was renamed at setup, use its new name. On Windows with
WAMP, call WAMP's PHP:

```bash
C:\wamp64\bin\php\php8.4.0\php.exe secure\tools\session-sweep.php --dry-run
```

### What it prints

```
QuickSite session sweep (dry run)
  store      /var/www/site/secure/tmp/sessions
  examined   8 files in 0.002s
  would remove 0 (0 empty, 0 idle) — 0 B
  kept       8 (4 recent, 4 read and judged alive or not ours, 0 locked by a request in flight)
```

- **recent**: alive by their date alone, so not even opened;
- **read and judged alive or not ours**: opened, and either still valid or belonging to
  something other than QuickSite;
- **locked**: a request was using them at that moment.

### Large stores

One run examines at most 20,000 files. When it stops there it says so, and the next
run — or the next sign-in that sweeps — continues where it stopped. The place is
kept in `sweep-position.json` in the store, which is safe to delete: the next run
then simply starts at the top. Only one sweep runs at a time; a run that finds
another one running says so and does nothing.

### On a schedule (optional)

With cron, once an hour:

```bash
0 * * * * php /path/to/secure/tools/session-sweep.php --quiet
```

Exit code: `0` when it swept, had nothing to do, or found another sweep running;
`1` when it refused to run (from a web request).
