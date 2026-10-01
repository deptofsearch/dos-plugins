"""Featured images for blog posts that have none: WPA-style, Washington palette, one scene per post.

  python post_images.py generate [--workers 6] [--only id,id]   scenes -> raw_posts/<id>.png (fal.ai, resumable)
  python post_images.py process                                  raw_posts -> web_posts/<id>.webp (1600x900)
  python post_images.py upload [--limit N] [--only id,id]        upload + set featured_media (resumable)

Inputs: post_scenes.json ({post_id: scene}). Ledger: post_uploads.json ({post_id: media_id}).
upload re-reads each post first and skips any that already has a featured image, so it never overwrites one.
"""
import json, os, shutil, subprocess, sys, time
from concurrent.futures import ThreadPoolExecutor, as_completed

HERE = os.path.dirname(os.path.abspath(__file__))
RAW, WEB = os.path.join(HERE, "raw_posts"), os.path.join(HERE, "web_posts")
LEDGER = os.path.join(HERE, "post_uploads.json")
GEN = os.path.expanduser("~/.claude/skills/media-gen/scripts/generate.py")
W, H = 1600, 900

STYLE = (
    "Rendered as a 1930s WPA Works Progress Administration silkscreen poster: a limited palette of 5 to 6 flat "
    "ink colors ({palette}), bold simplified shapes with crisp edges, banded sky, stylized clouds, strong "
    "silhouettes, visible screen-print ink texture, Art Deco era aesthetic. The artwork fills the entire canvas "
    "edge to edge with no margin. Absolutely no text, no lettering, no words, no numbers, no signs, no logos, "
    "no labels, no border frame."
)


def scenes():
    return json.load(open(os.path.join(HERE, "post_scenes.json")))


def arg(name, default=None):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


def load_palettes():
    """state slug -> [ {label, names, colors} ]. The dashboard (Tools > Market Images > Color palettes) is the
    source of truth; palettes.json (sampled from the state illustrations) is the offline fallback."""
    try:
        import requests
        sys.path.insert(0, HERE)
        from process_upload import env
        base, auth = env()
        r = requests.get(base + "/wp-json/revnm/v1/palettes", auth=auth, timeout=30)
        if r.ok and r.json():
            return {k: v for k, v in r.json().items() if v}
    except Exception as e:
        print("palettes: dashboard unavailable, using palettes.json:", e)
    codes = {i["code"]: i["key"] for i in json.load(open(os.path.join(HERE, "prompts.json")))["items"] if i["kind"] == "state"}
    return {codes[c]: v for c, v in json.load(open(os.path.join(HERE, "palettes.json"))).items() if c in codes}


def post_state(post, state_names):
    """Which state a post is about: Seattle -> Washington, else a state named in its tags or title."""
    text = (post.get("title", "") + " " + " ".join(post.get("tags", []))).lower()
    if "seattle" in text:
        return "washington"
    for slug, name in state_names.items():
        if name.lower() in text:
            return slug
    return None


def choose_palette(pid, state, palettes):
    """A state's posts rotate through that state's palettes; posts not tied to a state rotate through all."""
    pool = palettes.get(state) or [p for v in palettes.values() for p in v]
    pl = pool[int(pid) % len(pool)]
    return "{names}; matching these ink colors: {hexes}".format(names=pl["names"] or pl["label"], hexes=", ".join(pl["colors"])), pl["label"]


def gen_one(pid, scene, palette):
    out = os.path.join(RAW, f"{pid}.png")
    if os.path.exists(out):
        return pid, "skipped"
    p = subprocess.run(
        [sys.executable, GEN, "image", "--prompt", scene + " " + STYLE.format(palette=palette),
         "--title", f"revnm-post-{pid}", "--aspect-ratio", "16:9", "--resolution", "1K"],
        capture_output=True, text=True, timeout=600,
    )
    try:
        shutil.copyfile(json.loads(p.stdout.strip().splitlines()[-1])["image_path"], out)
        return pid, "ok"
    except Exception:
        return pid, "FAILED: " + (p.stderr or p.stdout).strip()[-200:]


def generate():
    os.makedirs(RAW, exist_ok=True)
    palettes = load_palettes()
    states = {i["key"]: i["label"] for i in json.load(open(os.path.join(HERE, "prompts.json")))["items"] if i["kind"] == "state"}
    posts = {str(p["id"]): p for p in json.load(open(os.path.join(HERE, "post_meta.json")))}
    sc = scenes()
    only = arg("--only")
    if only:
        sc = {k: v for k, v in sc.items() if k in only.split(",")}
    fails = 0
    chosen = {}
    jobs = []
    for k, v in sc.items():
        if os.path.exists(os.path.join(RAW, f"{k}.png")):
            continue
        pal, label = choose_palette(k, post_state(posts.get(k, {}), states), palettes)
        chosen[k] = label
        jobs.append((k, v, pal))
    log = os.path.join(HERE, "post_palettes.json")
    prev = json.load(open(log)) if os.path.exists(log) else {}
    json.dump({**prev, **chosen}, open(log, "w"), indent=0)
    print("palettes this run:", {l: list(chosen.values()).count(l) for l in set(chosen.values())})
    with ThreadPoolExecutor(int(arg("--workers", 6))) as ex:
        futs = [ex.submit(gen_one, k, v, pal) for k, v, pal in jobs]
        for n, f in enumerate(as_completed(futs), 1):
            pid, st = f.result()
            fails += st.startswith("FAILED")
            print(f"[{n}/{len(jobs)}] {pid}: {st}", flush=True)
            if "Exhausted balance" in st:
                print("fal.ai balance exhausted: stopping; rerun after topping up")
                ex.shutdown(cancel_futures=True)
                break
    print(f"done, {fails} failed")


def process():
    from PIL import Image, ImageOps
    sys.path.insert(0, HERE)
    from process_upload import trim_margin
    os.makedirs(WEB, exist_ok=True)
    n = 0
    for pid in scenes():
        src, dst = os.path.join(RAW, f"{pid}.png"), os.path.join(WEB, f"{pid}.webp")
        if not os.path.exists(src) or os.path.exists(dst):
            continue
        img = ImageOps.fit(trim_margin(Image.open(src).convert("RGB")), (W, H), Image.LANCZOS)
        img.save(dst, "WEBP", quality=80, method=6)
        n += 1
    print(f"processed {n}")


def upload():
    import requests
    sys.path.insert(0, HERE)
    from process_upload import env
    base, auth = env()
    ledger = json.load(open(LEDGER)) if os.path.exists(LEDGER) else {}
    limit = int(arg("--limit", 10**9))
    only = set(arg("--only", "").split(",")) - {""}
    done = skipped = 0
    for pid in scenes():
        if only and pid not in only:
            continue
        if done >= limit:
            break
        if pid in ledger or not os.path.exists(os.path.join(WEB, f"{pid}.webp")):
            continue
        post = requests.get(f"{base}/wp-json/wp/v2/posts/{pid}?context=edit&_fields=id,title,featured_media",
                            auth=auth, timeout=60).json()
        if post.get("featured_media"):
            ledger[pid] = "had-image"
            skipped += 1
            continue
        title = post["title"]["raw"]
        with open(os.path.join(WEB, f"{pid}.webp"), "rb") as f:
            r = requests.post(f"{base}/wp-json/wp/v2/media", auth=auth, timeout=120, data=f.read(),
                              headers={"Content-Disposition": f'attachment; filename="revnm-post-{pid}.webp"',
                                       "Content-Type": "image/webp"})
        if not r.ok:
            print("FAILED upload", pid, r.status_code, r.text[:150])
            time.sleep(5)
            continue
        mid = r.json()["id"]
        requests.post(f"{base}/wp-json/wp/v2/media/{mid}", auth=auth, timeout=60,
                      json={"alt_text": f"Illustration for: {title}", "title": title, "post": int(pid)})
        # Plugin endpoint sets the thumbnail without re-saving the post, so its modified date doesn't change
        # (a regular posts update would make every post show "Updated: <today>").
        r2 = requests.post(f"{base}/wp-json/revnm/v1/set-thumbnail", auth=auth, timeout=60,
                           json={"post": int(pid), "media": mid, "only_if_empty": 1})
        if not r2.ok or not r2.json().get("set"):
            print("NOT SET", pid, r2.status_code, r2.text[:150])
            continue
        ledger[pid] = mid
        json.dump(ledger, open(LEDGER, "w"), indent=0)
        done += 1
        print("ok", pid, mid, flush=True)
        time.sleep(1)
    print(f"uploaded {done}, skipped (already had an image) {skipped}")


if __name__ == "__main__":
    {"generate": generate, "process": process, "upload": upload}.get(
        sys.argv[1] if len(sys.argv) > 1 else "", lambda: print(__doc__))()
