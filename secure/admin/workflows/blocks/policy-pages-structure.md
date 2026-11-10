Each line is one element: its tag, then its classes after `.`. Indentation shows what is inside what. `container` is the site's own class, if it has one.

The cookie policy page, with one table row per stored key:

```
main.container.qs-cookie-policy-page
  h1
  p.qs-cookie-policy__intro
  p.qs-cookie-policy__preferences
    button.qs-consent-btn.qs-consent-btn--primary
  table.qs-cookie-policy
    thead
      tr
        th
    tbody
      tr
        td.qs-cookie-policy__key
        td
  p.qs-cookie-policy__none
  p.qs-cookie-policy__prefix-note
  p.qs-cookie-policy__oauth-intro
  ul.qs-cookie-policy__providers
  p.qs-cookie-policy__disclaimer
```

The privacy page. Its table of collected data, its list of shared data and its list of sign-in providers appear only when the site has them:

```
main.container.qs-privacy-policy-page
  h1
  p.qs-privacy-policy__intro
  table.qs-privacy-policy
  p.qs-privacy-policy__sharing-intro
  ul.qs-privacy-policy__sharing
  p.qs-privacy-policy__oauth-intro
  ul.qs-privacy-policy__providers
  h2
  p
    a
  p.qs-privacy-policy__disclaimer
```
