Each line is one element: its tag, then its id after `#`, then its classes after `.`. Indentation shows what is inside what.

The banner, fixed at the bottom of the page. Its link leads to the cookie policy, when the site has one. Its plain buttons refuse or customize, its primary button accepts:

```
div#qs-consent-banner.qs-consent-banner
  p.qs-consent-banner__text
    a.qs-consent-banner__policy
  div.qs-consent-banner__actions
    button.qs-consent-btn.qs-consent-btn--ghost
    button.qs-consent-btn.qs-consent-btn--primary
```

The popup, opened over the page, with one row per kind of cookie. The locked row cannot be switched off:

```
div#qs-consent-popup.qs-consent-popup
  div.qs-consent-popup__dialog
    div.qs-consent-popup__head
      h2.qs-consent-popup__title
      button.qs-consent-popup__close
    p.qs-consent-popup__intro
    div.qs-consent-popup__rows
      div.qs-consent-row.qs-consent-row--locked
        span.qs-consent-row__name
        span.qs-consent-row__note
        input.qs-consent-row__toggle
      label.qs-consent-row
        span.qs-consent-row__name
        input.qs-consent-row__toggle
    div.qs-consent-popup__actions
      button.qs-consent-btn.qs-consent-btn--ghost
      button.qs-consent-btn.qs-consent-btn--primary
```
