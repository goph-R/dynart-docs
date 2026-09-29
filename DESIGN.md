# Docs - a documentation site from a folder of Markdown

**Status: the build and the site work** (plugin 0.6.0, on Dpress 0.80.0 and dynart-micro 0.20.3).
`dpress docs:build` builds all 54 pages `docs-public`'s toctrees reach, with **the heading ids of
the Sphinx build on every one** - checked against its `_build/html` - and `/docs/...` serves them,
with the old `.html` addresses answered by a 301, and the admin has a Documentation screen. The
Pascal in it is highlighted since Dpress 0.80.0. All five steps are done, and pages can show
images since 0.6.0.

A Dpress plugin that does what `sphinx-build` does for
[docs-public](https://github.com/DynartInteractive/docs-public) - reads a folder of MyST Markdown,
follows its `toctree`s into a tree, and publishes it - but through Dpress's own renderer, so
shortcodes, callouts, code highlighting and internal links all work, and the pages are drawn by
the site's theme with the rest of the blog.

## Decisions

| | |
|---|---|
| Where the pages live | **The plugin's own table**, rebuilt from the files. The files are the only source; nothing is edited in the admin, and the blog's Pages are not touched. |
| Their address | **The files' own case**: `/docs/dos-game-engine/BASICS/VGA`. The base (`docs`) is a setting. |
| The tree | **A block**, for the sidebar, drawn **only on a documentation page** - the blog's own pages keep the sidebar they had. |

## What the source is

`docs-public` today: **59 pages, ~9,200 lines**, in three git submodules (`lisa-engine`,
`dos-game-engine`, `legal`), built with Sphinx + MyST + `sphinx_rtd_theme`. Beyond CommonMark it
uses, and the build has to understand:

| Syntax | Where | Becomes |
|---|---|---|
| ```` ```{toctree} ```` with `:maxdepth:`, `:caption:` | 11 `index.md` files | the tree - see below |
| ```` ```{note} ```` | 1 page | a Dpress callout, `> [!NOTE]` |
| `{#id}` on a line before a block, and `{#id .class}` | `legal/terms-of-use.md` | an `id` on the heading that follows |
| ``{ref}`id` `` | `legal/terms-of-use.md` | a link to that `id`, its text the heading's |
| `[text](../ENGINE/BASEGAME.md)`, relative | 16 pages | a link to that page's address |
| `<br>` in a table cell | tables in `dos-game-engine` | `{{ br() }}` - raw HTML is stripped by the renderer |
| bare URLs (`linkify`) | throughout | Dpress's autolinks already |

**Pascal** is 338 of the code blocks, and EnlighterJS has no Pascal: Dpress 0.80.0 added a Pascal
definition to its highlighter for them. **Images** are the section below (0.6.0).

## The build

A pass like `sphinx-build`'s, run:

- when the **source folder** is saved on the plugin's settings (its own section, *Documentation*);
- from a **Rebuild** button on the plugin's admin screen - in the navigation through
  `adminSections()` (Dpress 0.77.0);
- from **`dpress docs:build`**, for a deploy or a cron job - which needs one thing the core does
  not have yet: **plugins cannot add CLI commands** (`DpressCliApp::COMMANDS` is a constant). A
  `commands()` on the plugin interface, the same shape of change as `adminSections()`, comes first.

What it does:

1. **Start at `index.md`** in the source folder and follow each `toctree` in order: an entry is a
   path relative to the file it is in, without `.md`. What no `toctree` reaches is not published -
   Sphinx's rule, which is what keeps `README.md`, `CLAUDE.md` and `_build/` out.
2. **Each page**: read it, run the **MyST pre-pass** (the table above), render it through
   `MarkdownRenderer`, keep its **title** (the first `#` heading) and its **headings** (for an
   "on this page" list), and its place: parent, position among its siblings.
3. **A `toctree` in a page** is replaced by the list of its entries - the links Sphinx draws in
   that place - with `:caption:` as the list's heading.
4. **Write the whole tree in one go**, replacing the last build, so a page removed from the source
   is removed from the site and a half-finished build never shows.
5. **Report**: pages built, links that pointed at nothing, `toctree` entries with no file, syntax
   left unconverted - on the admin screen after a Rebuild, and on the console after `docs:build`.

## What is stored

`dp_docs_page`: `path` (the address below the base, original case, unique), `title`, `parent_id`,
`position`, `html` (rendered), `headings` (JSON: level, text, id), `source` (the file, relative),
`source_hash` (so an unchanged file need not be re-rendered), `built_at`.

`dp_docs_image` (0.6.0): `path` (in the source folder, original case, unique), `hash` (sha256),
`mime`, `width`, `height`, `built_at` - the images the built pages show, replaced with them.

## Images

**One place for an image: the source folder.** A page shows one the way Markdown does anywhere -
`![The palette](images/vga.png)`, relative to the page's file (or `/…` from the source folder's
root, as Sphinx reads it) - so it reads the same on GitHub. The build (`Images`) turns that into
the file's address under the base, `/docs/engine/images/vga.png?v=<12 of its hash>`, and the site
serves it **from the source folder, through PHP** (`DocsController::image()`). Nothing is copied:
slower than Apache handing over a file, and worth it for there being no second copy to drift.

**Only what a published page shows is served.** The build's list (`dp_docs_image`) is all an
image address under `/docs` answers; a file no page references is a 404 however it got into the
repository. A file is on the list when:

- its path stays inside the source folder and passes through no hidden folder (`.git`), and its
  real path - links followed - is inside it too;
- it is a **png, jpg, gif or webp** - **not SVG**, which opened on the site's own address can run
  script (a PNG of it works);
- its path has **no space**: Apache refuses one in the address it rewrites to the front
  controller (`AH10411`, a 403 before PHP runs);
- it is at most 10 MB, and `getimagesize()` agrees it is what its extension says.

Anything else is a build problem against the page, and the image is left as it was written.
The page's `<img>` gets `width`, `height` (so the text does not jump as it loads) and
`loading="lazy"`.

**PHP pays once per reader.** The address a page has is answered `immutable` for a year: a
changed file is a new `?v=` after the next build, and one changed *since* the build is not given
the year. Any other address is kept five minutes, with an `ETag` (size and time) answered with a
304 while the file has not changed. `nosniff`, and the type from the extension.

## What a visitor gets

- **`/docs/<path>`** - the page, drawn with the theme's page layout and a template of the plugin's
  (`docs:page`, which a theme may override): **breadcrumbs** up the tree, the page, and
  **previous / next** in the tree's reading order, as Sphinx's theme has - except on the root,
  which is the contents: a reader chooses there, and "Next" would choose for them.
- **`/docs`** - the root `index.md`.
- **The *Documentation tree* block**, placed in the sidebar: the root's chapters - not the root,
  which the block's title stands for - the branch of the current
  page open, the page itself marked; draws nothing on a page that is not documentation. **On a
  documentation page it is alone in its place** - the tag cloud, the categories and the rest are
  the blog's way around, not the manual's. Through `block:before_render` (Dpress 0.79.0), so the
  footer and every other page keep their blocks.
- **Internal links**: `docs#12`-style references are not needed - the source links by path, and
  the build turns those into addresses.

## The old addresses

Sphinx published at **docs.dynart.net**, every page as `.html`, and those addresses are in
bookmarks, search results and other people's pages. They map one to one, and each is answered
with a **301 Moved Permanently**:

| Sphinx | Docs plugin |
|---|---|
| `/dos-game-engine/BASICS/VGA.html` | `/docs/dos-game-engine/BASICS/VGA` |
| `/dos-game-engine/index.html` | `/docs/dos-game-engine` |
| `/index.html`, `/` | `/docs` |

- **On docs.dynart.net**, which is another host than the blog, three lines of `.htaccess` do it and
  nothing of Dpress runs there:

  ```apache
  RewriteEngine On
  RewriteRule ^$                       https://gopherlab.net/docs [R=301,L]
  RewriteRule ^(?:(.*)/)?index\.html$  https://gopherlab.net/docs/$1 [R=301,L]
  RewriteRule ^(.*)\.html$             https://gopherlab.net/docs/$1 [R=301,L]
  ```

- **On the blog**, the plugin also accepts `.html` on its own addresses and answers it with a 301 to
  the address without - so `gopherlab.net/docs/.../VGA.html`, which is what somebody rewriting an
  old link by hand types, lands too. Were docs.dynart.net ever pointed at this install instead, the
  same route answers the bare old paths.
- **The `#fragment` of an old link survives** a 301 - the browser keeps it - but only lands if the
  heading has the id Sphinx gave it. Dpress's renderer gives headings no ids, so the build adds them
  **by Sphinx's rule**: lowercase, every run of characters that is not a letter or a digit one `-`,
  none at either end - `## VGA Graphics` is `#vga-graphics`. A title repeated on one page is named
  the way the existing `_build/html` shows, checked against it. `{#id}` in the source wins over the
  generated one, as it does in MyST.

## What the admin gets

A **Documentation** section in the navigation, after Pages, with: the source folder, the address,
the time and result of the last build - its problems listed - a **Build now** button, and the tree
of built pages, each with a View. The settings are in their own *Documentation* section
of the Site tab, which has a Build button too; each goes back to the screen it was pressed on.

**Preview media in the editor** (Dpress 0.85.0): the editor's field carries
`data-relative-preview`, pointing at `GET /admin/docs/file?page=<source>&path=<as written>`, so a
relative image is read from the page's own folder in the source - with the build's rules
(`Images::inspect()`), and for a file no page shows yet, which is the one being previewed. Behind
`docs.edit`, `no-store`, and a 404 for anything the build would refuse.

**Editing a page's source** (0.5.0): with the `docs.edit` permission, a page's title - and an Edit
action beside its View - opens its source file in the Markdown editor, its lines numbered (a wrapped
line keeps one number, so a long table row still reads as one row). Save writes the file back and
rebuilds, **without pulling** (a pull could only get in the way of the edit just made), and does not
commit:

- **Only files the build published** can be opened: `?file=` is looked up among the pages' sources,
  never opened as a path, and the path is checked to stay inside the source folder.
- **Written back as the file had it**: its line endings and its final newline, so `git diff` shows
  the change and nothing else. Text that is not UTF-8 is refused - the renderer would refuse it at
  the next build.
- **A file that changed after the editor opened it** - a pull, an edit on the server - is not
  written over: the form carries a hash of the file as it was, and a save onto a different one is
  refused with the text kept in the form.

**What differs from the remote** (`RepositoryStatus`, in Dpress since 0.93.0): every repository of the source, the submodules
too, is asked for its uncommitted files (`git status`) and for the files of commits the remote does
not have (`git diff <upstream>...HEAD`, or `origin/HEAD` for a submodule at a commit). The tree marks
those pages *not committed* or *not pushed*, the Documentation screen lists every such file, and the
editor says above the text that it should be committed and pushed - until then the change exists only
on the server.

**Asked after the screen is drawn.** Each git command is a process started, so the list and the editor
render without it, as fast as any other list, and `docs-admin.js` fetches `/admin/docs/changes` - the
warning, rendered, and a badge per source file - a moment later. It is kept to few commands: one
`git status --porcelain=v2 --branch` per repository says both what is changed and how far the branch
is ahead of its upstream, the unpushed files are asked for only when there are some, and
`.gitmodules` is read in PHP. Four repositories are six commands where they were fifteen.

The last build is kept in a setting, `docs_last_build`, written by every build - the button's and
`dpress docs:build`'s alike. Not the pages' `built_at`: a build that makes nothing leaves the last
good pages up, and the screen has to say both.

## On production

The source folder has to be on the server: `docs-public` cloned with its submodules
(`git submodule update --init --recursive`), then `dpress docs:build` after each pull - or the
Rebuild button. The build reads only inside the folder it is given.

**Or the build pulls it** (0.4.0): with *Update* on in the Documentation settings, a build - the
button's, or `dpress docs:build` from a cron job - first runs `git pull --ff-only
--recurse-submodules` and `git submodule update --init --recursive` in the source folder
(`RepositoryUpdater`, in Dpress since 0.93.0). What it did is the first line of the build's report: "updated from a to b",
"up to date", or - as a problem, with the build going ahead on what is there - git's own
`fatal:` line.

- **The web server's user has to own the folder**, since that is who runs git from the button:
  `chown -R www-data: /var/www/docs-public`.
- **The remotes have to need no password**: `https://` for public repositories - the submodules
  too, whose `.gitmodules` addresses are `git@github.com:` and are overridden in the clone's own
  config. Git is told there is nobody to ask, so a missing credential fails at once rather than
  hanging the request.
- **`--ff-only`**, because the server's copy is a mirror: an edit made there stops the pull with
  "Not possible to fast-forward" instead of becoming a merge commit on the server.

## The order of work

1. **Core:** `PluginInterface::commands()` - CLI commands from a plugin. **Done, Dpress 0.78.0.**
2. **Plugin, the build:** the entity and migration, the tree walk, the MyST pre-pass, the render,
   `docs:build`. Tested against `docs-public` itself. **Done** - and one construct the table above
   missed, found by that: `{.numbered-header}`, classes with no id, which go on the heading.
   Later, from reading the pages: a fence with no language is built as `text`, so it is drawn in
   the highlighter's box as Sphinx draws every literal block - not as the blog's plain `<pre>`.
3. **Plugin, the site:** the route, the page template, breadcrumbs and previous/next, the tree block.
   **Done** - and one change to the framework it needed: the router tried catch-alls in the order
   they were added, so the site's `/*` took `/docs/*` too. A longer catch-all is tried first now
   (dynart-micro 0.20.3). A page's own `# Title` is taken out of its body and drawn as the
   theme's `entry-title`, keeping its id. A path in another case is a 301 to the real one.
4. **Plugin, the admin:** the section, the settings, Rebuild and its report. **Done.**
5. **Highlighter:** a Pascal language for EnlighterJS - separate, and it helps the blog's posts too.
   **Done, in Dpress 0.80.0** - in the core, not here: `assets/enlighter/pascal.js`.
