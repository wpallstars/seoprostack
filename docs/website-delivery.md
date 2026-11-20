# Website delivery from a template

How an agency on an Ultimate Multisite network delivers a client's website
from the one-page or multi-page template, and sets up a website add-on. The
steps are also two tasks on the **Client Orders** board that the Agency starter
(`starters/agency.json`) adds, so the team, or their AI assistant, can follow
them from the order. Keep the two in step: change the starter, then this page.

Cards say what to do and how on these WordPress sites; this page says how with
these plugins.

## Build a client website from the template

Task: **How to: Build a Client Website from the Template**.

1. Check the order and its brief: template (one-page or multi-page), business,
   audience, pages, domain. Missing details go back to the client from the
   **Brief** stage.
2. Open the client's site from the network admin (**Sites**). Never edit the
   template site.
3. Take out the template's keys: work through **Website Setup → Accounts and
   Keys Checklist**. A copied site arrives with the template owner's keys.
4. Write the copy page by page; replace every sample sentence and picture.
5. Brand it in **Appearance → Customize** (Kadence): colours (check light and
   dark mode), typography, header, footer, site identity.
6. Set up pages and menus (multi-page), or sections and their menu links
   (one-page), and the home page in **Settings → Reading**.
7. Forms: edit the Fluent Forms contact form, send a test entry, check it
   reaches the client.
8. SEO basics: a Rank Math title and description for each page, alt text on
   pictures, plain slugs, search engines allowed once live.
9. Check before handover: phone and computer, light and dark, every link, the
   form, legal pages, **Site Health**.
10. Move the card to **Client review**; **Done** when the client approves.

Done when no template words or keys are left and the client has approved.

## Set up a website add-on for a client

Task: **How to: Set Up a Website Add-on for a Client**.

1. Check the order: which site, which add-on (CRM, Support Desk, Project
   Management, Courses).
2. Switch on the product: in **Ultimate Multisite → Customers**, add the
   add-on's product to the client's membership. It turns the plugin on for
   their site.
3. Check the plugin is active on the client's site.
4. The add-on's setup cards are on **Fluent Boards → Website Setup**
   (`docs/addon-cards.md` says how and when they are added).
5. Work through them in order; email sending comes before the first email.
6. Test it: a contact, a ticket, a task or a lesson.
7. Tell the client where to find it; **Done** when they confirm.

## Rules

- Never put a password or key in a card, comment or message.
- Never send a customer's email through a vendor's hosted relay (`AGENTS.md`).

## Checking

- `bash scripts/preflight-plugin.sh .` checks the starters' JSON.
- On a disposable site, add the Agency starter data: the two tasks appear once
  on **Client Orders**, in **New order**; adding it again adds nothing.
- Follow the build steps once on a review copy of a template site.
