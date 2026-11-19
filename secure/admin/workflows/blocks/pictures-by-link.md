## Your Own Pictures, by Link

You may bring pictures of your own: QuickSite downloads each one into the site's media with `uploadAsset` and its `url`. Bring one only where a picture helps the page.

**The rules:**
- **A link, never data:** the direct `https://` address of the picture file itself.
- **A link that stays reachable for at least an hour:** the author reviews your answer before it runs. If you cannot give such a link, leave the picture out.
- **Only pictures the author may use on a website:** yours to give, or published under terms that let anyone use them so.
- **The same link everywhere, character for character:** in its `uploadAsset` step and in every place that shows the picture (`src`, `srcset`, a stylesheet's `url(…)`). Once the picture is downloaded, QuickSite writes its place in the site wherever that link stands: never guess that place yourself.
- **Each `uploadAsset` step FIRST,** before any step that shows its picture.
- **Its `alt` is a key of the site's texts, in the group `alt`** (`alt.` and a short name of your own): pass the same key as `alt` in the `uploadAsset` step and on the `img`, and give it its words in your `setTranslationKeys`, one sentence saying what the picture shows.

```json
[
  { "command": "uploadAsset", "params": { "url": "https://pictures.example.org/bakery-front.jpg", "alt": "alt.bakery_front" } },
  { "command": "editStructure", "params": { "type": "page", "name": "home", "structure": [
    { "tag": "img", "params": { "src": "https://pictures.example.org/bakery-front.jpg", "alt": "alt.bakery_front" } }
  ] } }
]
```

**Never invent a link:** one that leads to no picture fails, and the page keeps an address outside the site, which may disappear.
