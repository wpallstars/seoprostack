# Support email and helpdesks

Read this before changing Fluent Support email by IMAP
(`includes/features/class-seoprostack-helpdesk-imap.php` and the
`includes/class-seoprostack-helpdesk-imap-*.php` classes), or anything else
that touches a site's support mailbox.

- Support email stays between the site and its own mailbox (owner's
  decision, for data privacy, #1066): helpdesks (Fluent Support or another)
  read it over IMAP, through Fluent Support email by IMAP or the plugin that
  manages the mailbox, and reply through the site's mail plugin (FluentSMTP).
- Never route it through a vendor's hosted relay or piping service, such as
  Fluent's email piping, which passes every customer email through the
  vendor.
- A GPL feature that works locally must not wait on a licence check or call
  home: call the plugin's own local code, as
  `SEOProStack_Helpdesk_Imap_Reader` does with Fluent's `ByMailHandler`.
