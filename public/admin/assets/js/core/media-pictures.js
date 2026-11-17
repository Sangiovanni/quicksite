/**
 * QuickSite Admin — the pictures a shared link's card can show.
 *
 * Media's images, each named as Media names it (`images/<file>`), SVG left out: no platform draws one in a card,
 * and setSocialPreview refuses it. The one list the console's picture field (command-form.js) and the Social
 * previews page (pages/social-previews.js) offer, read from the panel's assets helper.
 *
 *   const rows = await QSMediaPictures.list();   // [{value: 'images/a.png', label: 'a.png'}, …], or null
 *
 * null when Media could not be read: a caller then keeps what it had (the console a plain text box).
 */
window.QSMediaPictures = Object.freeze({
    /**
     * @returns {Promise<Array<{value: string, label: string}>|null>}
     */
    async list() {
        let rows;
        try {
            rows = await window.QuickSiteAPI.fetchHelper('assets', ['images']);
        } catch (e) {
            return null;
        }
        if (!Array.isArray(rows)) return null;
        return rows
            .filter(row => !/\.svgz?$/i.test(String(row.value)))
            .map(row => ({ value: 'images/' + row.value, label: row.label || String(row.value) }));
    }
});
