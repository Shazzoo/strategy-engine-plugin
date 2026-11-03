# Content Studio — Engine plugin

A plugin for [Content Studio Core](https://github.com/Shazzoo/content-studio-core)
that publishes blog articles generated in the Content Studio Engine.

It syncs articles from the Engine into your site, gives them routes, an index
and detail page, blocks you can drop into any page, SEO metadata and sitemap
entries — and confirms back to the Engine once an article is live.

One package serves every site. The functionality is updated with
`composer update`; each site keeps its own look through view overrides, so no
site needs its own copy.

## What it gives you

| | |
|---|---|
| **Article sync** | `content-studio:articles`, scheduled daily, pulling approved content from the Engine |
| **Routes** | `/{prefix}` and `/{prefix}/{slug}`, locale-prefixed on multilingual sites |
| **Pages** | An article index and a detail page, overridable by your theme |
| **Blocks** | An article listing block for any page, plus overview and body blocks |
| **Admin** | A Content Studio settings page for API credentials, route prefix and paging |
| **SEO** | Metadata, Open Graph and schema.org, applied through Core's SEO layer |
| **Sitemap** | Article URLs registered with Core's sitemap generator |
| **Publish confirmation** | Tells the Engine an article is live, so it drops out of the pending list |
| **About this article** | Shows who wrote an article and how it was made, from the Engine's provenance, and checks the live page afterwards |

## Requirements

- PHP 8.3+
- `shazzoo/content-studio-core` ^1.0

## Install

```bash
composer require shazzoo/strategy-engine-plugin
```

Activate **Content Studio** under *Instellingen → Plugins*. Activating it runs
the plugin's migrations and publishes its assets.

## Configure

Open **Content Studio Settings** in the admin and fill in:

| Setting | Meaning |
|---|---|
| `cs_api_key` | Your Content Studio Engine API key |
| `cs_project_code` | The project to pull articles from |
| `route_prefix` | URL segment for articles. `blog` gives `/blog` and `/blog/{slug}` |
| `articles_per_page` | Articles on the index page |
| `articles_per_block` | Articles in the listing block |

The page shows which Engine project you are connected to and how many articles
are available, so you can confirm the credentials work before syncing.

The Engine API URL defaults to the hosted Engine and can be pointed elsewhere
with `CONTENT_STUDIO_ENGINE_API_URL` in `.env`.

## Sync articles

```bash
php artisan content-studio:articles
```

Registered to run daily. Only content approved in the Engine is pulled. After an
article is stored the plugin confirms publication back to the Engine, so a
synced article drops out of the pending list on the next run.

## About this article (provenance)

The Engine sends every article with `content_provenance`: who the author is
(a person, the customer's organisation, or Scout, Content Studio's AI
editorial assistant), who publishes it, whether AI was involved, whether a
human reviewed it, and the disclosure text to show. The plugin shows that
information and adds nothing of its own.

**Who decides.** The Engine decides what is published: it only delivers
articles that may go live. The plugin never withholds an article and never
takes one offline. It shows the provenance only when it is valid, checks
the live page, and reports what it saw. The Engine decides what to do with
that.

**On receipt**, the payload is validated. That covers the version, body
revision, authorship mode, author and publisher types, `ai_assisted`,
review status, reviewer and disclosure text. Text and provenance are stored
in one transaction. The outcome depends on the payload:

| Payload | What happens |
|---|---|
| Valid | Stored and published with its *About this article* block. |
| `null` or invalid | Published without a block. A previous block goes too, because it belonged to the previous text. The Engine gets `published_with_warning` with `provenance_invalid:<reason>`. |
| Key missing (older Engine) | Published as before, reported as `published_with_warning`. |

What is stored on `content_studio_articles`:

| Column | Holds |
|---|---|
| `provenance` | The normalised provenance |
| `provenance_version`, `body_revision`, `disclosure_hash` | For queries, without parsing JSON |
| `provenance_state` | `valid`, `invalid` or `legacy` |
| `provenance_errors` | Why the last provenance was invalid |
| `disclosure_mode` | `body` once the fallback below was needed |
| `verification`, `verification_status`, `verified_at` | The last check of the live page |

Storing the same provenance twice writes nothing.

**On the page**, `<x-strategy-engine::provenance>` renders an *About this
article* block in `card-details` and `hub-details`.

- **Content:** the Engine's byline and its disclosure text, verbatim. Scout
  appears as "Samengesteld door Scout / AI-redactieassistent van Content
  Studio", linked to its external profile.
- **Position:** `top`, `author` (after the author box) or `bottom`.
  `CONTENT_STUDIO_PROVENANCE_POSITION` overrides what the Engine suggests;
  without either, it goes at the bottom.
- **Once only:** the block renders once per page, even if a theme places the
  component as well.
- **No scripts or inline styles:** it follows the page's text colour, so it
  works in light and dark themes.
- **Not elsewhere:** it never appears in excerpts or listings.
- **Byline and author box:** the byline above the article follows the
  provenance. The *About the author* box only shows for a human author whose
  name matches. The hub page's *Expert review* box only shows after a human
  review.

**Your own views.** If you override `card-details` or `hub-details`, add the
component where the block belongs:

```blade
<x-strategy-engine::provenance :article="$article" :at="['bottom']" />
```

If a theme or an older published view leaves it out, the check after
publishing notices. It then writes the block into that article's text,
between markers, so it is replaced on the next update rather than added
twice. That is the only time the block goes into the text.
`CONTENT_STUDIO_PROVENANCE_RENDERING=body` does this for every article.

**Structured data.** With provenance, the article's `BlogPosting` uses it:

- **Author:** exactly one. A `Person` only for a real person; the customer as
  an `Organization`; Scout as the `Organization` "Content Studio Redactie"
  with `alternateName: Scout`. Scout is never a `Person`.
- **Publisher:** the customer.
- **Contributors and reviewer:** `contributor` only for listed contributors,
  and `reviewedBy` only after a human review.

This plugin writes the article schema itself, so there is no SEO plugin to
reconcile. If a theme adds its own `Article`, the check reports a conflict.
`CONTENT_STUDIO_SCHEMA_CONFLICT_POLICY=block` makes that a failure instead of
a warning.

## Verification after publishing

After storing an article the plugin:

1. purges caches for that URL;
2. fetches the public page as a visitor would;
3. runs the checks below;
4. tells the Engine the result.

The checks:

- HTTP 200, and JSON-LD that parses.
- The disclosure is visible, appears exactly once, and carries the current
  body revision.
- The visible author matches the provenance.
- The structured-data author and publisher match.
- There are no conflicting article authors.
- A human author is a `Person`; an organisation author is an
  `Organization`; Scout is never a `Person`, in JSON-LD or microdata.
- `reviewedBy` appears only after a human review.
- URLs contain no Markdown.
- Dates are consistent.

A failure that a cache can explain is retried twice, after 1 and 3
seconds, with a purge before each retry. The last attempt adds a
cache-buster.

**Result.** The result goes to the Engine with the V2 acknowledgement as
`verification`. It contains:

- `publication_status`;
- `provenance_rendered`, `structured_data_valid`, `visible_author_valid`;
- `disclosure_count`, `conflicts`, `warnings`;
- `verified_at`, `public_url`, `body_revision`;
- `failed_checks`.

| Status | Meaning |
|---|---|
| `verified` | Everything checked out |
| `published_with_warning` | Live, but something needs attention, e.g. the Engine sent no byline, a theme adds a second author, or a stale cache |
| `provenance_failed` | Live, but the disclosure or author on the page is wrong. The Engine hears `published`/`updated` with this status |
| `publication_failed` | The page is not there. The Engine hears `failed` |

A missing mandatory disclosure is never a success, but the article stays
online: the Engine gets `provenance_failed` and decides.

V1 only knows "live": it confirms every article whose page is there.

**When it runs.** The webhook answers the Engine first; checking and
reporting follow in the same process after the response
(`app()->terminating`). No queue worker is needed. A result that only failed
on something temporary, such as a stale cache or an unreachable page, is
checked again by the scheduler (`content-studio:provenance due`, every five
minutes, at most three times).

**Your own caches.** Core renders article pages per request. If your site
puts a CDN or proxy cache in front, listen for
`Shazzoo\StrategyEngine\Events\ArticlePageChanged` and purge
`$event->url`. `spatie/laravel-responsecache` is purged automatically.

| Setting (`.env`) | Default | |
|---|---|---|
| `CONTENT_STUDIO_PROVENANCE_POSITION` | Engine, else `bottom` | `top`, `author` or `bottom` |
| `CONTENT_STUDIO_PROVENANCE_RENDERING` | native | `body` puts the block in the text |
| `CONTENT_STUDIO_VERIFY_PUBLICATION` | `true` | Fetch and check the live page |
| `CONTENT_STUDIO_VERIFY_SSL` | production only | Check the certificate when fetching |
| `CONTENT_STUDIO_SCHEMA_CONFLICT_POLICY` | `warn` | `block` fails on a conflicting article schema |

## Recovering

```bash
php artisan content-studio:provenance status    {id|engine:<id>}
php artisan content-studio:provenance verify    {id|engine:<id>}   # check the live page now and report
php artisan content-studio:provenance dedupe    {id|engine:<id>}   # remove duplicate blocks, then verify
php artisan content-studio:provenance republish {id|engine:<id>}   # fetch from the Engine again and publish
```

Add `--no-report` to keep the Engine out of it.

## Privacy

The provenance block and the verification add no tracking. The block is
plain HTML: no script, no cookie, no external request.

The verifier fetches your own page server-side:

- it identifies itself as `ContentStudioVerifier/1.0`;
- it sends no cookies;
- it stores nothing about visitors;
- it does not execute the tracking script, so it creates no tracking events.

The existing article tracking (`tracking.js`, switch off with
`CONTENT_STUDIO_TRACKING_ENABLED=false`) is unchanged by this. It works as
follows:

- **In the visitor's browser:** a random visitor id in the
  `shazzoo_visitor_id` cookie and in `localStorage`, and a session id in
  `sessionStorage`.
- **Sent to the Engine:** both ids, together with the page, referrer and
  the browser's user agent.
- **Stored by the Engine:**
  - the user agent, as sent (it is used to recognise bots);
  - the visitor and session ids, only as hashes;
  - your visitor's IP address, which is only hashed into a visitor id when
    there is no browser id.

Mention this in the site's privacy statement where tracking is enabled.

## Styling per site

The plugin ships working, deliberately plain views. A site overrides only what
it wants to restyle; everything else keeps coming from the package, so plugin
updates still reach the views you did not touch.

Three levels, cheapest first.

### 1. Override individual views

```bash
php artisan vendor:publish --tag=content-studio-components
```

That copies the component views into
`resources/views/vendor/strategy-engine/components/`. Edit them freely —
markup, classes, structure. Delete any file from that directory and it falls
back to the package version.

Use `--tag=content-studio-views` to publish everything, including the article
pages and pagination.

> **Publish only what you restyle.** Every published file is one you now
> maintain: it stops receiving upstream changes. Restyling three components
> means three files to maintain, not sixteen.

| View | Purpose |
|---|---|
| `components/article-card.blade.php` | Article card in listings |
| `components/article-hub.blade.php` | Hub/cluster card |
| `components/article-info.blade.php` | Author and metadata line |
| `components/provenance.blade.php` | Where the *About this article* block goes |
| `partials/provenance.blade.php` | The block itself (keep its `data-` attributes) |
| `components/article-tag.blade.php` | A single tag |
| `components/article-tags.blade.php` | Tag list |
| `components/article-breadcrumbs.blade.php` | Breadcrumbs |
| `components/card-details.blade.php` | Card body detail |
| `components/hub-details.blade.php` | Hub body detail |
| `components/blocks/article-body.blade.php` | Article body block |
| `components/blocks/article-listing.blade.php` | Listing block |
| `components/blocks/article-overview.blade.php` | Overview block |
| `articles/index.blade.php` | Article index page |
| `articles/show.blade.php` | Article detail page |
| `partials/pagination.blade.php` | Pagination |

**When copying a view out of another project**, rewrite any references to that
project's namespaces to this package's:

```
strategy-engine::            (view and translation namespace)
Shazzoo\StrategyEngine\             (PHP classes, including inside @php blocks)
```

A stale namespace in an override fails at render time, not at install time.

### 2. Theme templates

For the index and detail pages the active theme wins over the plugin entirely.
`ArticleController` looks for these first:

```
{theme}::templates.articles-index
{theme}::templates.articles-show
```

If your theme provides them, the plugin's own `articles/index` and
`articles/show` are never used. This is the right level when your article pages
have a fundamentally different layout rather than different styling.

You can also pick a specific theme template per site under **Templates** on the
settings page. Leave it empty and nothing changes — the plugin falls back to the
theme's `articles-index` / `articles-show`, then to its own views.

A chosen template receives a page-shaped object, so a generic theme page
template can render articles:

```blade
$templateSettings = $page->template_settings ?? [];
$ctaTitle = data_get($templateSettings, "cta.{$locale}.title");
```

`template_settings` is per-template configuration you edit in the admin,
including a localised CTA. Templates that render articles as a block also
receive `$pageBlocks` and `$contentStudioContentView`.

### 3. Model accessors

When writing your own views, read article data through the accessors rather than
raw Engine column names, so your views survive schema changes upstream:

```blade
{{ $article->title }}
{{ $article->excerpt(160) }}      {{-- excerpt, or a trimmed body --}}
{{ $article->thumbnailUrl() }}
{!! $article->content !!}          {{-- body_html --}}
@if ($article->author)
    {{ $article->author->name }}
@endif
```

### How view resolution works

Core registers the site override directory ahead of the package's own views, so
the finder picks the first path that has the file:

```
1. resources/views/vendor/strategy-engine/                      ← your overrides
2. vendor/shazzoo/strategy-engine-plugin/resources/views/  ← package defaults
```

Resolution is per file, not all-or-nothing.

### A note on the view namespace

The package is `shazzoo/strategy-engine-plugin`, but the plugin slug
is **`strategy-engine`**, so views and components resolve as
`strategy-engine::…`. Do not change the slug without updating every
`strategy-engine::` reference and `<x-strategy-engine::…>` tag in
every theme that uses it.

## Other publishable tags

```bash
php artisan vendor:publish --tag=content-studio-config
```

```bash
php artisan vendor:publish --tag=content-studio-lang
```

## Multilingual sites

Locale-prefixed routes are registered only when `cms_is_multilang()` is true.
Build article URLs through `Support\ArticleRoutes` rather than `route()` or a
hand-built path — it checks the same flag, so the same code works on
single-language and multilingual sites:

```php
use Shazzoo\StrategyEngine\Support\ArticleRoutes;

ArticleRoutes::indexPath($locale);
ArticleRoutes::articleUrl($slug, $locale);
```

## Migrations

Every migration checks the state it changes before touching it, so the package
installs cleanly on a site that already has these tables and columns from an
earlier copy of the plugin, even when that copy named a migration differently.

## Development

```bash
composer install
vendor/bin/pest
```

The feature tests boot the plugin in Orchestra Testbench and render real
article pages through its routes.


Point a site at a working copy:

```json
{
  "repositories": [
    { "type": "path", "url": "../strategy-engine-plugin" }
  ]
}
```

After changing blocks, views or namespaces:

```bash
php artisan cms:build
```
