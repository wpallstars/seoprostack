# Speed work: what SEO Pro Stack covers

Read this before adding or changing a Speed or Server feature, or anything
that needs knowledge of one theme or plugin. These are the owner's
decisions.

- No page caching or CSS and JS minification in SEO Pro Stack (owner's
  decision): they are a common source of broken sites and support load, so
  leave them to plugins that specialise in them (LiteSpeed Cache,
  WP-Optimize). SEO Pro Stack may recommend those plugins and set them up
  through their presets, saving through their own code.
- One exception, at the owner's request (#1194, widened in #1260): **Load
  the CSS each page uses first** (Used CSS) for the stylesheets of the
  Kadence theme (and a child theme of it), the plugins SEO Pro Stack
  recommends, their Pro add-ons, Kadence's own plugins, the plugins it
  works with (`SEOProStack_Used_Css_Links::PLUGINS`) and WordPress's block
  library, worked out on the site's own server. When a plugin is added to
  or removed from Free Plugins or Pro Plugins, update that list too. Do not
  widen it to other themes or plugins; the `seoprostack_used_css_sources`
  filter is for site owners, unsupported.
- Speed work that needs knowledge of one theme or plugin (Used CSS, Load
  plugins only where needed, Image loading's opening block, moving a
  plugin's files to the end of the page) covers only Kadence and its
  plugins, the plugins SEO Pro Stack recommends and their Pro add-ons, and
  plugins the owner names on a site of theirs (Bookly, #1414). Owner's
  rule: do better than a specialist plugin only where that does not mean
  supporting any number of themes and plugins we do not recommend. Others
  get a filter for site owners, unsupported, or nothing.
