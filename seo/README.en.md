# SEO — meta tags, sitemap and SEO advisor

The plugin gives an apidcms site everything search engines expect by default and
shows what is wrong on every page.

Installation does not touch the core: SEO fields live in their own table, and the
theme receives the tags in the finished HTML.

## What it does

**On the site**

- `title`, `description`, `robots`, `canonical` for every page, record and
  section. A value from the SEO fields overrides the content; an empty field
  leaves what the author wrote.
- Open Graph and Twitter Card — how a link looks in messengers and social media.
- JSON-LD: `WebSite` for the site, `BreadcrumbList` for nested URLs, `Article`
  for table records.
- `hreflang` when the site has extra languages configured.
- `/sitemap.xml` built from pages, sections and records, skipping `noindex` ones.
- `/robots.txt` in "auto" mode — only when there is no robots.txt page in navigation.
- 301 redirects for moved URLs and a 404 log: what visitors look for and miss.

**In the admin panel**

- **SEO** section: site average score, page list with a 0–100 score and filters
  (problems / not checked / good), one-click site check.
- Page editor: fields, a 20+ item checklist, search snippet preview, length
  counters, "Generate title and description" button (AI).
- 404 log with a "where to redirect" suggestion.
- Redirects: add, hit counter, delete.

## What the advisor checks

| Group | Items |
|---|---|
| Title | present, 30–65 chars, unique across the site |
| Description | present, 70–170 chars, unique |
| Content | 300+ words, exactly one H1, H2–H4 subheadings |
| Focus keyword | in title, description, content, URL; density 0.4–4.5% |
| Images and links | image alt text, internal links |
| URL | latin letters, hyphens, no deeper than three levels |
| Social and indexing | social image, noindex, canonical URL |

The score is a weighted sum: what matters most (title, content length, H1,
keyword in text) weighs more than cosmetics. Checks that do not apply to a page
(for example word count on a form page) do not affect the score.

Analysis is offline: the analyzer reads the database, never calls the network and
spends no tokens. The AI is used only by the "Generate" button.

## Settings

Admin → Plugins → SEO. Values live in `plugin_settings`.

| Setting | Meaning |
|---|---|
| Output meta tags | master switch for `<head>` output |
| Open Graph and Twitter | social block |
| JSON-LD | structured data |
| Default social image | when the page has none |
| Title template | `{title} — My site`; empty — title as is |
| Serve sitemap.xml | site map |
| robots.txt | auto / always / leave alone |
| noindex for 404 | error page stays out of the index |
| "Generate" button | AI access through the DeepSeek key from CMS settings |

## If the theme renders tags itself

The plugin edits the finished HTML: it replaces `title`, removes duplicate
`description`/`robots`/`canonical`/`og:*` tags and inserts its block before
`</head>`. If the theme should stay in control, call in its template:

```twig
<head>
    {{ seo_meta() }}
</head>
```

The plugin will not inject a second time.

## Compatibility

- Core 2.8.0 or newer (needs `front.output`, `front.router.before`, `admin.routes`).
- No external dependencies: PHP 8.1+, SQLite and the stock CMS classes.
- Tables: `plugin_seo_meta`, `plugin_seo_redirect`, `plugin_seo_404`.
- A site's own `sitemap.xml` or `robots.txt` page in navigation is never overridden.

## What it does not do

It does not promise rankings and does not replace editing: it shows problems and
helps to close them. Backlinks, server speed and behavioural factors are out of
its scope.
