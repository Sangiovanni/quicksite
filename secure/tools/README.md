# QuickSite deployer tools

Scripts the operator runs on the server itself, from a shell. Each one acts on the
whole installation rather than on one project, so it is neither a command nor a
panel page: no account can be given the right to run it, and having shell access
to the server is what authorises it. Each one refuses to run from a web request.

Run them as the web server's user (`www-data` on Debian and Ubuntu), so that what a
tool writes stays that user's to change. None of them asks a question while it runs.

(The nginx reload fallback is a separate, cron-only script: see `../cron/README.md`.)

---

## `tools.php` — the list, and one way in

### What it does

Run with nothing after it, the launcher lists every tool in this folder, with one
line saying what each does and how to run it. Given a tool's name, it runs that
tool with the options that follow, and ends with the tool's own exit code.

A tool is listed by reading the start of its file, never by running it: its header
names it, says what it does in one line, and gives its options (see *Adding a tool*
below). Each tool also runs on its own, by its file's name, which is what a cron
line names.

### How to run it

From the installation's root folder:

```bash
php secure/tools/tools.php                          # list every tool
php secure/tools/tools.php log-prune --dry-run      # run one, with its options
php secure/tools/tools.php cache-prune --help       # a tool's own help
```

If the `secure` folder was renamed at setup, use its new name. On Windows with
WAMP, call WAMP's PHP:

```bash
C:\wamp64\bin\php\php8.4.27\php.exe secure\tools\tools.php
```

The tool runs in a process of its own, started with the same PHP as the launcher.
A setting given to that PHP on its command line (`-d …`) does not reach the tool,
which reads its `php.ini` as PHP always does.

### What it prints

```
QuickSite deployer tools

  cache-prune            Remove what the four caches already ignore, each by its own rule
                         php secure/tools/tools.php cache-prune [--dry-run] [--quiet]

  log-prune              Remove the logs' days past their age, stray locks and vanished projects' logs
                         php secure/tools/tools.php log-prune [--dry-run] [--quiet] [--days=N] [--security-days=N]
  …
```

Exit code: the tool's own; `1` when the launcher refused (a web request, an
unknown tool, a tool it could not start); `0` for the list.

### Adding a tool

A tool joins the list by being a `.php` file in this folder whose header, its first
comment, holds three lines:

```php
/**
 * my-tool.php — what it does, for whoever reads the file.
 *
 * @qs-tool   my-tool
 * @qs-line   One line saying what it does
 * @qs-usage  [--dry-run] [--quiet]
 */
```

- `@qs-tool` is its name, which is its file's name without `.php`;
- `@qs-line` is what the list shows beside the name;
- `@qs-usage` is its options, as they follow its name (empty for a tool with none).

A file without the three lines is not listed and cannot be run through the
launcher. The tools share `lib/cli.php`: the refusal of a web request, the options,
the report, and the way a file is removed from a store under the store's own lock.
Give a new tool its section in this file.

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

---

## `log-prune.php` — remove the logs' old days

### What it does

QuickSite writes one file per day to each of its logs under `secure/logs/`: the
command log of each project (`p/<project>/`), the log of the commands that target
no project (`_global/`), and the security trail (`_security/`), each day file with
a small `.lock` file beside it. Nothing else removes them. The prune removes:

- command log days older than 90 days, in every project's folder and in `_global`;
- security trail days older than 365 days;
- a `.lock` file left without its day file;
- the log folder of a project that no longer exists (deleting a project removes its
  own; one is left where a project's folder was removed by hand).

Both ages are options. A file dated today, yesterday or tomorrow is never touched,
and neither is a project's folder holding one: the web server's PHP may date its
files in another time zone than the command line's. A day file a writer is using is
left for the next run, and so is anything else in the folders.

### When you need it

Whenever the logs grow past what you want to keep: once a day from cron is plenty.
A project's owner can still clear their own project's history from the panel; this
removes every project's old days at once, and the days of `_global` and the security
trail, which no command reaches.

Keep a copy elsewhere first if your retention policy asks for longer than the ages
you choose.

### How to run it

From the installation's root folder:

```bash
php secure/tools/log-prune.php                       # remove now
php secure/tools/log-prune.php --dry-run             # list what would go; remove nothing
php secure/tools/log-prune.php --quiet               # print nothing (for cron)
php secure/tools/log-prune.php --days=30             # the command log's age, in days (90 by default)
php secure/tools/log-prune.php --security-days=180   # the security trail's age, in days (365 by default)
php secure/tools/log-prune.php --help
```

On Windows with WAMP:

```bash
C:\wamp64\bin\php\php8.4.27\php.exe secure\tools\log-prune.php --dry-run
```

### What it prints

```
QuickSite log prune (dry run)
  engine       /var/www/site/secure
  would remove logs/_global/commands_2026-07-25.json  (older than 90 days)
  would remove logs/_global/commands_2026-07-26.json.lock  (a lock without its day file)
  would remove logs/p/old-site  (the log of a project that no longer exists, 4 files)
  examined     212
  would remove 3 (18.2 KB)
  kept         209 (209 within their age)
```

A line per day file, lock or folder that goes; then what was kept, and why. A file
a writer held is listed as **in use**: the next run takes it. A **link, left** is a
link (a symlink, or a Windows junction) found where a log folder or a day file
should be: it is never followed, and nothing it leads to is touched.

### On a schedule (optional)

With cron, once a day:

```bash
30 3 * * * php /path/to/secure/tools/log-prune.php --quiet
```

Exit code: `0` when it ran (with nothing to do, or files in use left for the next
run); `1` when it refused to run (a web request, an unknown option, a bad number);
`2` when it left something that needs a look, each listed: a file it could not
remove, or a link.

---

## `cache-prune.php` — remove what the caches already ignore

### What it does

QuickSite keeps four caches under `secure/cache/`. Each one already ignores an
entry once it is past its use, but keeps its file. The prune removes, from each,
what that cache ignores, by that cache's own rule:

- `rate/`: an account's write-rate file (and its lock) once every window it counts is
  over. A file whose window is still open stays;
- `membership/`: an account's dashboard repair once it is 300 seconds old, the time
  the repair stands for. The `token` file is never removed;
- `resolver/`: a data-resolver response past its expiry;
- `space/`: a project's measured disk size past its expiry, and the measurement of a
  project that no longer exists.

There is no age to choose: what goes is what the cache would not use. A file a
writer is using at that moment is left for the next run. A file a cache's rule
cannot judge (one that is not what that cache writes) is listed and left; the
`cleanResolverCache` command, which an author runs, deletes a malformed resolver
entry itself.

### When you need it

When the cache folders hold many files: an installation with many accounts or many
pages served from a data resolver. Once a day from cron keeps them small; the caches
work the same with or without it.

### How to run it

From the installation's root folder:

```bash
php secure/tools/cache-prune.php              # remove now
php secure/tools/cache-prune.php --dry-run    # list what would go; remove nothing
php secure/tools/cache-prune.php --quiet      # print nothing (for cron)
php secure/tools/cache-prune.php --help
```

On Windows with WAMP:

```bash
C:\wamp64\bin\php\php8.4.27\php.exe secure\tools\cache-prune.php --dry-run
```

### What it prints

```
QuickSite cache prune (dry run)
  engine       /var/www/site/secure
  would remove cache/rate/3f1c…e9a0.json  (its windows are over)
  would remove cache/membership/77b2…01cd.json  (its repair is 300 s old or more)
  would remove cache/space/old-site.json  (its project is gone)
  examined     9
  would remove 3 (412 B)
  kept         6 (2 with a window still open; 1 with a repair still standing; 3 not expired)
```

A file name in `rate/` and `membership/` is a hash of an account's id: the account
itself is never named. **in use**, **could not judge** and **link, left** are listed
as for `log-prune`.

### On a schedule (optional)

With cron, once a day:

```bash
45 3 * * * php /path/to/secure/tools/cache-prune.php --quiet
```

Exit code: `0` when it ran; `1` when it refused to run; `2` when it left something
that needs a look, each listed: a file it could not read or judge, a file it could
not remove, or a link.

---

## `reconcile-memberships.php` — bring every account's memberships back in line

### What it does

Who belongs to a project is written in that project's roster
(`config/members.json`). Each account also keeps its own copy of its memberships,
which the panel's project menu and invitations read. The copy can drift: a roster
edited by hand, a write that failed, a project folder removed by hand. For every
project, the reconcile brings each account's copy back to what the roster says, as
the `reconcileMemberships` command does for one project:

- an account the roster lists as a member, as invited, or as asking to join gets
  that entry;
- an entry the roster contradicts is removed;
- a notice (a refusal, a removal, a deleted project) is kept.

Then an account's entry for a project that no longer exists, member or pending,
becomes the *deleted* notice, as deleting a project leaves it. A roster is never
written, and a project whose roster cannot be read is not reconciled against.

### When you need it

After editing a roster by hand, after removing a project's folder by hand, or when
an account's project menu shows a project it should not (or misses one it should).
It is not a routine task: the panel repairs an account's own copy when it opens.

### How to run it

From the installation's root folder:

```bash
php secure/tools/reconcile-memberships.php              # reconcile now
php secure/tools/reconcile-memberships.php --dry-run    # list what would change; write nothing
php secure/tools/reconcile-memberships.php --quiet      # print nothing (for cron)
php secure/tools/reconcile-memberships.php --help
```

On Windows with WAMP:

```bash
C:\wamp64\bin\php\php8.4.27\php.exe secure\tools\reconcile-memberships.php --dry-run
```

### What it prints

```
QuickSite membership reconcile (dry run)
  engine       /var/www/site/secure
  would change account usr_3c7d… · project shop  (pending_invite → pending_invite, its name dropped)
  would change account usr_9a01… · project old-site  (member → deleted)
  examined     4 projects
  would change 2
  kept         3 (3 already in agreement)
```

One line per account entry that changes: its status before and after, and, when the
status stays, the part of the entry that changes. A project whose roster cannot be
read is listed as **could not judge**.

### On a schedule (optional)

With cron, once a week, if you want it:

```bash
15 4 * * 0 php /path/to/secure/tools/reconcile-memberships.php --quiet
```

Exit code: `0` when it ran; `1` when it refused to run; `2` when a roster could not
be read or the account file could not be written, each listed.

---

## What is not here, and why

Every other store keeps itself small, so no tool prunes it:

- **the sign-in and registration limits** (`secure/management/config/`): one file
  each, pruned of what has expired on every write;
- **builds**: one per project, replaced by the next build;
- **exports**: a project keeps its last five; the next export removes the oldest;
- **backups**: each backup removes the oldest beyond the number it is told to keep
  (`max_backups`, five by default);
- **sessions**: `session-sweep.php`, above, which a sign-in also runs now and then.
