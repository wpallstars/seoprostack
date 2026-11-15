# Plugin directory choices

Read this before changing which plugins SEO Pro Stack recommends: Free
Plugins and Pro Plugins (`admin/data/free-plugins.php`,
`SEOProStack_Plugin_Manager`).

- Do not recommend FlyingPress or Link Whisper in Free Plugins or Pro
  Plugins (owner's choice). This does not mean their features are replaced;
  keep the separate Link Whisper feature documentation.
- Include relevant Fluent/WPManageNinja free products using their actual
  WordPress.org slugs, including FluentAuth (`fluent-security`),
  FluentSnippets (`easy-code-manager`) and Paymattic (`wp-payment-form`).
  Check the `wpmanageninja`, `techjewel` and `adreastrian` author catalogues.
- Exclude Fluent Connect (`fluent-connect`, ThriveCart), Fluent Forms
  Connector for MailPoet (`fluent-forms-connector-for-mailpoet`) and Mautic
  Integration For Fluent Forms (`mautic-for-fluent-forms`): the owner does
  not use these connectors.
- Fluent Query Logger (`fluent-query-logger`) belongs in Debug, with the
  occasional-use and compatibility note in `SEOProStack_Plugin_Manager`:
  its WordPress.org release was last updated in July 2022.
- Legacy vendor plugins NinjaDB (`ninjadb`), WP Faq Builder
  (`wp-faq-builder`) and Testimonials Builder (`testimonials-builder`) are
  not recommendations: last updated in 2017, 2018 and 2019 respectively
  (WordPress.org checked 2026-10-03).
- Do not recommend Comment Goblin (owner's choice, 2026-10-04): its
  commentgoblin.com server served a certificate for another name, so its
  site and update checks failed. Its fix in Fixes for other plugins and its
  admin menu group stay for sites that have it installed.
- Do not recommend SSL Quality Checker (`ssl-quality-checker`) or copy how
  it gets an SSL Labs grade: last updated in 2017 (tested up to WordPress
  4.7), and the SSL Labs API terms (v2.2) forbid commercial use and use on a
  public web site without Qualys's permission; even its relaxed terms for
  hosts give full results only as links to its reports. HTTPS check runs its
  own checks and links to the SSL Labs report (GitHub issue #804; checked
  2026-10-17).
- A plugin that a feature replaces leaves the list: see `AGENTS.md` →
  Features.
- Recommend our own plugins (owner's request, GitHub issue #1657): SEO Pro
  Stack in Minimal (though installed wherever the list shows), SEO Pro
  Cookie Consent in Compliance, SEO Pro Analytics in SEO, SEO Pro Custom in
  Advanced, and SEO Pro Analytics Connect in Pro Plugins. A plugin one of
  them replaces leaves the list too: Complianz (free and Privacy Suite),
  Complianz Terms & Conditions and Burst Statistics. Keep their presets,
  fixes and Used CSS entries while sites still use them. SEO Pro Directory
  is private, so it is not listed.
- Until they are on WordPress.org, our own plugins' cards come from
  `seoprostack_get_own_plugins()` (`admin/data/free-plugins.php`) through
  `seoprostack_external_plugins`: links to their GitHub repositories and
  Activate once installed. When one reaches WordPress.org, remove it there
  so its card comes from WordPress.org.
