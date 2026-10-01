"""Trim, convert and upload the market images, then register them with the DoS Market Images plugin.

  python process_upload.py process          raw/<key>.png -> web/<key>.webp (poster margin trimmed, 1200x900)
  python process_upload.py upload [--dry]   web/*.webp -> WordPress media library (idempotent via uploads.json)
  python process_upload.py register         POST the city/state list to /wp-json/revnm/v1/market-images

Needs Pillow + requests, and a wp.env (WP_URL, WP_USER, WP_APP_PASSWORD) for upload/register: the file
named by $WP_ENV, else ../wp.env.
"""
import json, os, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
RAW, WEB = os.path.join(HERE, "raw"), os.path.join(HERE, "web")
LEDGER = os.path.join(HERE, "uploads.json")
W, H = 1200, 900


def items():
    return json.load(open(os.path.join(HERE, "prompts.json")))["items"]


def trim_margin(img):
    """The model often paints a cream 'poster sheet' border. Walk in from each edge while the
    row/column is nearly uniform and close to the corner colour; stop at 8% of the side."""
    from PIL import ImageStat
    px = img.convert("RGB")
    w, h = px.size
    corner = px.getpixel((2, 2))

    def plain(box):
        st = ImageStat.Stat(px.crop(box))
        mean_close = sum(abs(m - c) for m, c in zip(st.mean, corner)) < 36
        return mean_close and max(st.stddev) < 22

    lim_x, lim_y = int(w * 0.08), int(h * 0.08)
    l = next((x for x in range(lim_x) if not plain((x, 0, x + 1, h))), lim_x)
    r = next((x for x in range(lim_x) if not plain((w - x - 1, 0, w - x, h))), lim_x)
    t = next((y for y in range(lim_y) if not plain((0, y, w, y + 1))), lim_y)
    b = next((y for y in range(lim_y) if not plain((0, h - y - 1, w, h - y))), lim_y)
    pad = 4 if (l or r or t or b) else 0  # a few extra px past the ink edge
    return img.crop((l + pad if l else 0, t + pad if t else 0, w - (r + pad if r else 0), h - (b + pad if b else 0)))


def process():
    from PIL import Image, ImageOps
    os.makedirs(WEB, exist_ok=True)
    n = 0
    for it in items():
        src = os.path.join(RAW, it["key"] + ".png")
        dst = os.path.join(WEB, it["key"] + ".webp")
        if not os.path.exists(src):
            print("missing raw:", it["key"])
            continue
        img = trim_margin(Image.open(src).convert("RGB"))
        img = ImageOps.fit(img, (W, H), Image.LANCZOS)
        img.save(dst, "WEBP", quality=80, method=6)
        n += 1
    print(f"processed {n}")


def env():
    vals = {}
    # WP_ENV overrides the default so credentials can live outside the repo.
    for line in open(os.environ.get("WP_ENV", os.path.join(HERE, "..", "wp.env"))):
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            vals[k.strip()] = v.strip().strip('"').strip("'")
    for k in ("WP_URL", "WP_USER", "WP_APP_PASSWORD"):
        if not vals.get(k):
            sys.exit(f"wp.env is missing {k}")
    return vals["WP_URL"].rstrip("/"), (vals["WP_USER"], vals["WP_APP_PASSWORD"])


def upload(dry=False):
    import requests
    base, auth = env()
    ledger = json.load(open(LEDGER)) if os.path.exists(LEDGER) else {}
    todo = [it for it in items() if it["key"] not in ledger]
    print(f"{len(todo)} to upload, {len(ledger)} already done")
    if dry:
        r = requests.get(base + "/wp-json/wp/v2/users/me?context=edit", auth=auth, timeout=30)
        print("auth check:", r.status_code, r.json().get("name") if r.ok else r.text[:200])
        return
    for it in todo:
        path = os.path.join(WEB, it["key"] + ".webp")
        alt = f"Illustration of {it['label']}"
        with open(path, "rb") as f:
            r = requests.post(
                base + "/wp-json/wp/v2/media", auth=auth, timeout=120,
                headers={"Content-Disposition": f'attachment; filename="revnm-{it["key"]}.webp"', "Content-Type": "image/webp"},
                data=f.read(),
            )
        if not r.ok:
            print("FAILED", it["key"], r.status_code, r.text[:200])
            time.sleep(5)
            continue
        mid = r.json()["id"]
        requests.post(base + f"/wp-json/wp/v2/media/{mid}", auth=auth, timeout=60,
                      json={"alt_text": alt, "title": f"{it['label']} (market illustration)"})
        ledger[it["key"]] = mid
        json.dump(ledger, open(LEDGER, "w"), indent=1)  # saved after every file so a rerun resumes
        print("ok", it["key"], mid, flush=True)
        time.sleep(1)


def register():
    import requests
    base, auth = env()
    ledger = json.load(open(LEDGER))
    # Carousel order = busiest market first, as ranked in final100.json.
    order = [c["wpPageId"] for c in json.load(open(os.path.join(HERE, "final100.json")))]
    its = items()
    cities = [i for i in its if i["kind"] == "city" and i["key"] in ledger]
    cities.sort(key=lambda i: order.index(i["wpPageId"]) if i["wpPageId"] in order else 999)
    body = {
        "cities": [{"key": i["key"], "label": i["label"], "page_id": i["wpPageId"], "attachment_id": ledger[i["key"]]} for i in cities],
        "states": [{"key": i["key"], "label": i["label"], "slug": i["key"], "attachment_id": ledger[i["key"]]}
                   for i in its if i["kind"] == "state" and i["key"] in ledger],
    }
    r = requests.post(base + "/wp-json/revnm/v1/market-images", auth=auth, json=body, timeout=60)
    print(r.status_code, r.text[:300])


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    {"process": process, "upload": lambda: upload("--dry" in sys.argv), "register": register}.get(cmd, lambda: print(__doc__))()
