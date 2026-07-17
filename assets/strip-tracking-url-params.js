// Strips ugly tracking params (gclid, fbclid, …) from the URL – they are useless
// to the user and pollute copied links. Runs once on page load; module scripts
// are deferred, and this code needs no DOM anyway (location + history only).
const annoyingKeys = ["_fid", "fbclid", "feci", "gbraid", "gci", "gclid", "hgtid", "igshid",
    "li_fat_id", "mc_cid", "mc_eid", "msclkid", "s_kwcid", "sznclid", "ttclid", "twclid", "wbraid"];
if (new RegExp(`[?&](${annoyingKeys.join("|")})=`).test(location.search)) {
    const url = new URL(location.href);
    annoyingKeys.forEach(function (key) {
        url.searchParams.delete(key);
    });
    history.replaceState(history.state, document.title, url.href);
}
