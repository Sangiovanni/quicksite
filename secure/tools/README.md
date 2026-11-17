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
WAMP, call WAMP's PHP, a patched release of its branch (`README.md`,
*Requirements*):

```bash
C:\wamp64\bin\php\php8.4.27\php.exe secure\tools\session-sweep.php --dry-run
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

---

## `template-previews.php` — build the templates' previews

### What it does

*Start from a template* offers each site template of `secure/admin/workflows/templates/`
with a **Preview** button. What that button shows is built by this tool: for each
template, the pages QuickSite's own build answers for it, in every language the
template is written in, in its light theme and in its dark theme when it has one.
They are kept as static files under `public/admin/assets/template-previews/`. A
template's photos are kept once for its two themes, byte for byte.

A preview holds the template's own pages and nothing else. A link that leads out of
it (a booking page, a call, a letter, a page the preview does not hold) is switched
off in the preview's copy, and a press on it makes the panel say that the link works
on a real site. The site a run makes keeps its links.

It touches no account and no project of the installation. It copies QuickSite's own
files to a temporary folder, serves that copy with PHP's built-in server on a free
local port, runs each template there as the panel runs it, builds it, fetches every
page, and removes the copy.

### When you need it

- you changed a template's file, or added one: its card shows no **Preview** button
  until its preview is built again;
- you replaced one of a template's photos (`secure/admin/workflows/templates/photos/`):
  a preview records each photo it shows, and one whose photo changed is stale;
- you are releasing a version: a preview records which engine built it, and one built
  by another engine is stale even when no template changed. The engine, here, is the
  engine's source folder, every command file, the workflows a template's run goes
  through, and this tool: a change to any of them makes every preview stale.

A preview is never built by the panel: opening a page does not start a build.

### How to run it

From the installation's root folder:

```bash
php secure/tools/template-previews.php            # build the previews that are stale or missing
php secure/tools/template-previews.php --all      # build every preview again
php secure/tools/template-previews.php --check    # build nothing; say which are stale
php secure/tools/template-previews.php --help
```

If the `secure` folder was renamed at setup, use its new name. It needs PHP's command
line, with `proc_open` and `allow_url_fopen` available, and nothing else.

### What it prints

```
QuickSite template previews
  fresh      landing-page
  stale      starter-business: its template changed
  built      starter-business, light: 26 steps, 8 pages in en, fr
  built      starter-business, dark: 26 steps, 8 pages in en, fr
Every preview is fresh.
```

- **fresh**: built from this template's file, by this engine;
- **stale**: *it has no preview*, *its template changed*, *it was built by another
  engine*, *a file of it is missing*, or *its template is gone* (the preview is then
  removed);
- **refused**: the template breaks the rule of the three views (see
  `docs/WORKFLOW_SYSTEM.md`, *Site templates*): its stylesheet names a width other than
  `(max-width: 64rem)` and `(max-width: 40rem)`, holds a second block of either, puts the
  phone's before the tablet's, or a rule after them. No preview is built for it;
- **built**: one line per theme.

A build that fails replaces nothing: the previous preview stays as it was.

Exit code: `0` when every preview is fresh; `1` when it refused to run, a build
failed, `--check` found a stale preview, or a template was refused.
